# self-update-app

A PHP command-line app, shipped as a single `app.phar`, that updates itself securely.

On start it asks a server for the latest version. If that version is newer, the app:

1. verifies an RSA signature,
2. downloads the new PHAR and checks its SHA-256,
3. health-checks the new PHAR,
4. swaps itself for it,
5. restarts as the new version.

The code is deliberately small and covers the happy path only (see [Limitations](#limitations)).

## Layout

```
app/                 the app that goes inside the PHAR
  main.php           entry point
  public.pem         RSA public key (packed into every PHAR)
  src/Verifier.php   signature + hash checks
  src/AppLock.php    single-instance lock
  src/Updater.php    fetch, download, health check, swap
server/
  keygen.php         run once: creates the RSA keypair
  release.php        bumps the version, builds and signs a PHAR
  router.php         the HTTP server: GET /latest + the PHAR files
  latest.yaml        latest version, sha256, signature (generated)
  keys/private.pem   signing key (generated, never committed or served)
  public/releases/   app-1.phar, app-2.phar, ... (served)
tests/               PHPUnit tests
```

## Requirements

- PHP 8.1+ CLI with `openssl` and `phar`.
- `pcntl` for the automatic restart. Without it, the app prints `App was updated to X, please restart`.
- Composer.
- Linux or macOS.

## Demo

```bash
composer install

# Once: create the signing keys
php server/keygen.php

# Release version 1, and install it as the "client"
php -d phar.readonly=0 server/release.php        # Released version 1
mkdir -p client && cp server/public/releases/app-1.phar client/app.phar

# Release version 2
php -d phar.readonly=0 server/release.php        # Released version 2

# Start the server (terminal 1)
php -S 0.0.0.0:8080 -t server/public server/router.php

# Run the old client (terminal 2)
cd client
php app.phar
# Your app was updated to 2
# Press Enter to exit

php app.phar
# Your app is running version 2
```

**Lock:** while one `php app.phar` is waiting for Enter, run another one in a second terminal. It prints `Another instance is running` and exits with code 2.

**Tampering:** set `version: 3` in `server/latest.yaml` without re-signing, and copy `app-2.phar` to `app-3.phar`. The client stops with `Invalid signature`, and `app.phar` is left untouched (`php app.phar --health` still prints `2`).

Point the client at another server with `UPDATE_SERVER_URL=http://host:port php app.phar`.

## How the update works

```
start ─ lock app.lock ─ GET /latest ─ newer? ─no─→ "running version X"
                                        │yes
          verify RSA signature of "<version>:<sha256>"   (else stop)
          download to app.phar.new, check SHA-256         (else stop)
          php app.phar.new --health  → exit 0 + version   (else stop)
          rename(app.phar.new → app.phar)
          release lock, pcntl_exec(php app.phar --updated) → "updated to X"
```

### Trust model

| Check | Answers | Key |
|---|---|---|
| RSA-4096 signature over `"<version>:<sha256>"` | Did the holder of the private key publish this release? | Public key inside the PHAR; private key only on the server |
| SHA-256 of the downloaded file | Is this exactly the file that was signed? | none |

- **Signature first.** Nothing the server says is trusted until the signature verifies.
- **The version is signed together with the hash.** A validly signed old PHAR can't be advertised as a newer version.
- **Signing is asymmetric** rather than a shared secret (HMAC). Anyone can unpack the PHAR, so a secret inside it would let them forge updates. The public key can only verify.
- **The server doesn't choose the download URL.** `/latest` returns only `version`, `sha256` and `signature`. The app builds the URL itself as `<its server URL>/releases/app-<signed version>.phar`, so a malicious server can't point it at other hosts or at local files (`file://`, `phar://`).

### Swapping a running PHAR

PHP reads code from the PHAR while it runs, so the app never writes *into* `app.phar`. It downloads to `app.phar.new` in the same folder, then calls `rename()`. On the same filesystem a rename is atomic, so `app.phar` is always either the complete old file or the complete new one. The running process keeps its open handle to the old file's data until it exits.

`pcntl_exec` then replaces the process (same PID) with `php app.phar --updated`, which loads the new file. All classes are loaded at the top of `main.php`, before the swap.

### Locking

`app.lock` (next to `app.phar`) is held with `flock(LOCK_EX | LOCK_NB)` for the whole run. The lock belongs to the open file handle, so it is released right before `pcntl_exec`; otherwise the restarted app could lock itself out. `--health` doesn't take the lock, because its parent process holds it.

## Tests

```bash
vendor/bin/phpunit
```

- Signature: valid passes; changed version, changed hash, or wrong key fails.
- Hash: a one-byte change fails.
- Version comparison.
- Lock: a second lock fails while the first is held.
- A bad signature throws before anything is downloaded.

## Limitations

- **Happy path only.** A failed check throws and stops the process (`app.phar` is untouched). There's no retry, logging, cleanup of `app.phar.new`, or friendly error message. Server down or bad JSON also just fail.
- **Lock gap at restart.** Between releasing the lock and the new process taking it, another instance could start. The restarted app would then print `Another instance is running`; nothing unsafe happens.
- **Run it as a PHAR.** `php app/main.php` isn't supported: the lock path comes from `Phar::running()`.
- **No rollback after the swap.** The health check runs *before* the swap instead.
- **Replay of old releases.** Versions must be newer, so the latest signed release can't be swapped for an older one. A server can still keep offering an old-but-newer-than-yours release.
- **Key management.** No key rotation. A stolen private key can sign anything.
- **HTTP only** for the local demo. Production would use HTTPS (the signature still protects the update over plain HTTP).
- **Windows** isn't supported (no `pcntl`, different rename semantics).
- **Checks only on start.** There's no scheduling.
