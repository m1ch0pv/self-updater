# Self-updating PHAR: design

Date: 2026-11-03
Context: build a production-ready program that updates itself.

## Intent

A PHP CLI app shipped as `app.phar`. On start it asks a server whether a newer version exists. If one does, it downloads it, checks it, replaces itself and restarts as the new version. The code must be **dead simple and readable**, with comments on the parts that matter (signing, verification, the swap, the restart, locking).

## Goals

- One release script that bumps the version, builds a signed PHAR and records it.
- A small HTTP server on port 8080: one JSON endpoint for the latest version, plus the PHAR files.
- A client `app.phar` that self-updates securely and restarts itself.
- A few tests on the most important logic.

## Non-goals

- Semantic versions. The version is a plain **integer** (1, 2, 3, ...).
- Cleaning up old releases. They stay on the server.
- Windows support, key rotation, delta updates, scheduled checks. These go in the README as limitations.
- Running a child process with `passthru`/`proc_open` as a restart fallback. This was explicitly rejected (see Restart).

## Anti-goals

- Clever or framework-heavy code. Plain PHP, few classes, no abstraction the author can't explain.
- Writing over the running `app.phar` in place.
- Any secret embedded in the client. The client holds only the public key.

## The demo (as the author described it)

1. On the server side, run the release script. The version in the YAML is bumped, and a PHAR with that version inside it is built and signed.
2. Start the server on port 8080.
3. Run `php app.phar` from the client folder. It checks the version and updates itself if needed.
4. After an update and restart it prints `Your app was updated to X`. If there was no update it prints `Your app is running version X`. It then stays alive until Enter is pressed, so locking can be tested from a second terminal.

To have something to update *from*: release v1, copy that PHAR to `client/app.phar`, release v2, start the server, run the client.

## Decisions made with the author

| Topic | Decision |
|---|---|
| Version | Integer, stored as a file inside the PHAR, not in source code |
| Server state | A YAML file with the latest version and the key path |
| Fingerprint | SHA-256 of the PHAR file |
| Signing | RSA (OpenSSL extension, `openssl_sign` / `openssl_verify`, `OPENSSL_ALGO_SHA256`) |
| What is signed | The string `"<version>:<sha256>"`. This binds the version to the exact file, so a validly signed old PHAR cannot be relabelled as newer |
| Restart | `pcntl_exec` if available. Otherwise print `App was updated to X, please restart` and exit. No `passthru` fallback |
| Health check | Before the swap, run `php app.phar.new --health`. It must exit 0 and print the expected version |
| Lock | Exclusive advisory `flock` on `app.lock` in the app's folder (created if missing), held for the whole run |
| Stay alive | Blocking `fgets(STDIN)` ("Press Enter to exit"), no busy loop |
| YAML parsing | `symfony/yaml` via Composer (no YAML extension installed). Used only by the server-side scripts |

## Client flow (`app.phar`)

1. **`--health` mode:** print the version and exit 0. Do nothing else; in particular, **don't take the lock**, because the parent process holds it.
2. **Lock:** open or create `app.lock` next to `app.phar` and take `flock(LOCK_EX | LOCK_NB)`. If that fails, print "Another instance is running" and exit with code 2.
3. **Check:** `GET {server}/latest` and decode the JSON. If `version <= current`, skip to step 8.
4. **Verify signature:** run `openssl_verify("<version>:<sha256>", base64_decode(signature), publicKey, SHA256)`. Do this before trusting any field.
5. **Download** to `app.phar.new` in the same directory as `app.phar` (same filesystem, so the rename is atomic). Compare its SHA-256 to the signed value with `hash_equals`.
6. **Health check:** run `PHP_BINARY app.phar.new --health` with `exec()`. Require exit code 0 and output equal to the expected version.
7. **Swap and restart:** `rename(app.phar.new, app.phar)`. If `pcntl_exec` exists: release the lock (unlock + close), then `pcntl_exec(PHP_BINARY, [app.phar, '--updated'])`. Otherwise print `App was updated to X, please restart` and exit 0.
8. **Report:** `--updated` → `Your app was updated to X`, otherwise `Your app is running version X`. Then print "Press Enter to exit", block on `fgets(STDIN)`, and exit 0.

**Happy path only (author's decision).** No error recovery, cleanup or reason reporting. The security checks still gate the update: if the signature, hash or health check fails, the code throws an exception and the process stops *before* the swap, so `app.phar` is never replaced. Server down, bad JSON and similar cases are not handled specially.

### Why rename works on a running PHAR (comment this in code)

PHP reads classes from the PHAR lazily while the process runs, so writing *into* `app.phar` would corrupt the running process. `rename()` instead points the name `app.phar` at a new file. The running process keeps its open handle to the old file's data, which the OS frees when the process ends. `pcntl_exec` then replaces the process with a fresh PHP that loads the new file.

### Why release the lock before exec

`flock` locks belong to the open file handle. If the handle survived the exec, the new process's own `flock` on a fresh handle would conflict with it and the app would lock itself out. Releasing first leaves a tiny window where another instance could grab the lock. That's acceptable, and worth stating in the README.

## Server side

- **Key generation script** (run once): creates an RSA 4096-bit keypair. The private key goes in a gitignored file on the server side. The public key goes where the build packs it into the PHAR.
- **YAML** (`server/latest.yaml`): `version` (int), `private_key` (path), plus `sha256` and `signature` for the current release, written by the release script.
- **Release script:**
  1. Read the YAML and compute `version + 1`.
  2. Build the PHAR from the app source with the `Phar` class. Run with `php -d phar.readonly=0`. Add the version file and the public key, and set a stub that runs the entry script.
  3. Write it to the served releases folder as `app-<version>.phar`.
  4. Compute the SHA-256 and sign `"<version>:<sha256>"` with the private key.
  5. Write version, sha256 and signature back to the YAML.
- **Server:** `php -S 0.0.0.0:8080` with a router script. The document root exposes **only** `GET /latest` and the release PHARs. `GET /latest` reads the YAML and returns `{"version", "url", "sha256", "signature"}` as JSON. The YAML and the private key must never be reachable over HTTP.

## Tests (PHPUnit)

The most important ones:
- Signature: a valid signature passes; a changed version, a changed sha256, or a signature from another key fails.
- Hash check: a matching file passes; a file with one changed byte fails.
- Version decision: newer → update; equal or older → no update.
- Lock: a second lock on the same file fails while the first is held.

Swap, exec and the full download path are covered by the manual demo, not automated tests.

## Exit codes

0 ok, 2 another instance holds the lock, non-zero (PHP's default for an uncaught exception) when a check fails.

## Left to the builder

- Folder layout, class names and how logic splits across the few classes. Keep it small, e.g. one class for signature/hash checks, one for the lock, one for the update steps, and a short entry script.
- Server URL configuration. My call: default `http://localhost:8080`, overridable with an env var.
- README content: demo steps, trust model, limitations listed above.

## Small calls settled during review (my calls)

- A process started with `--updated` skips the update check and goes straight to step 8 (it still takes the lock). This rules out update loops.
- `url` in `/latest` is absolute (`http://localhost:8080/releases/app-<v>.phar`). It isn't signed, and that's fine: the downloaded file is checked against the signed sha256.
- On the first release a missing YAML is treated as version 0, so the first release is version 1.
- Minimum PHP version is 8.1.
