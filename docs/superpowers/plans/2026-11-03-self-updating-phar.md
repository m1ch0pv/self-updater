# Self-Updating PHAR Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A PHP CLI `app.phar` that, on start, asks a local server for the latest version and, if newer, downloads it, verifies its RSA signature and SHA-256, health-checks it, swaps itself and restarts via `pcntl_exec`.

**Architecture:** Three small client classes (`Verifier`, `AppLock`, `Updater`) plus an entry script `main.php`, packed into a PHAR together with `version.txt` and `public.pem`. The server side is three plain scripts: `keygen.php` (once), `release.php` (bump, build, sign, write YAML), and `router.php` for PHP's built-in server.

**Tech Stack:** PHP ≥ 8.1 (CLI), ext-openssl, ext-phar, ext-pcntl (optional at runtime), `symfony/yaml` (server scripts only), PHPUnit 10+.

**Spec:** `docs/superpowers/specs/2026-11-03-self-updating-phar-design.md`

## Global Constraints

- Dead simple, readable code; comment the parts that matter (signing, verification, rename-over-running-file, exec, lock release before exec). No frameworks.
- Happy path only: no error recovery or reason reporting. Signature, hash and health-check failures `throw new RuntimeException(...)` so the process stops **before** the swap.
- Version is an integer. It lives in `version.txt` inside the PHAR, never in source code.
- Signed payload is exactly the string `"<version>:<sha256>"` (sha256 as lowercase hex), signed with `OPENSSL_ALGO_SHA256`, signature transported base64-encoded.
- RSA 4096-bit keys. The private key never enters the PHAR or the served folder.
- The client PHAR needs no Composer dependencies. `main.php` loads `src/*.php` with `require_once`.
- Server: `php -S 0.0.0.0:8080 -t server/public server/router.php`. Only `GET /latest` and `/releases/*.phar` are reachable.
- Default server URL `http://localhost:8080`, overridable with env var `UPDATE_SERVER_URL`.
- Exact user-facing lines (X = version):
  - `Your app was updated to X`
  - `Your app is running version X`
  - `App was updated to X, please restart`
  - `Another instance is running` (exit code 2)
  - `Press Enter to exit`
- `--health` prints only the version and exits 0, without taking the lock.
- `--updated` skips the update check (still takes the lock).

## Review Focus

1. **Lock path inside a PHAR:** `__DIR__` is a `phar://` path, so the lock file must be in `dirname(Phar::running(false))`, the real folder. Covered by the demo in Task 5 (`app.lock` appears in `client/`).
2. **Running a PHAR named `app.phar.new`:** the health check runs a file that doesn't end in `.phar`. The stub uses `Phar::mapPhar`, which works for any filename. Covered by the demo in Task 5. If it fails, rename the temp file to `app.new.phar` and update the spec.
3. **Two locks in one process:** `flock` conflicts between two separate `fopen` handles even within one process (macOS/Linux). Pinned by `AppLockTest::test_second_lock_on_same_file_fails`.
4. **Tampered version or hash in the server response:** must fail signature verification. Pinned by the `VerifierTest` cases in Task 1.
5. **Classes loaded after the swap:** every `src` file is `require_once`d at the top of `main.php`, before any rename, so nothing is lazily read from the replaced file. Covered by a code comment in Task 4 and the Task 5 demo.

---

## File Structure

```
composer.json            php >=8.1, symfony/yaml, phpunit (dev); autoload-dev only for tests
phpunit.xml
.gitignore               vendor/, server/keys/, server/latest.yaml, server/public/releases/*.phar, client/
app/
  main.php               entry point packed into the PHAR
  public.pem             written by keygen.php, committed
  src/Verifier.php       signature + hash checks (pure functions)
  src/AppLock.php        flock wrapper
  src/Updater.php        fetch, download, health check, swap
server/
  keygen.php             run once
  release.php            bump + build + sign + write YAML
  router.php             /latest endpoint; static files otherwise
  public/releases/       served PHARs (app-1.phar, app-2.phar, ...)
tests/
  VerifierTest.php
  AppLockTest.php
  UpdaterTest.php
README.md
```

Classes use namespace `App`. Tests load them with `composer.json` `"autoload": {"psr-4": {"App\\": "app/src/"}}` (the PHAR itself doesn't use the Composer autoloader).

---

### Task 1: Project scaffold + Verifier

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`, `app/src/Verifier.php`
- Test: `tests/VerifierTest.php`

**Interfaces:**
- Produces:
  - `App\Verifier::payload(int $version, string $sha256): string` returns `"$version:$sha256"`
  - `App\Verifier::signatureIsValid(int $version, string $sha256, string $signatureBase64, string $publicKeyPem): bool` returns `openssl_verify(...) === 1`
  - `App\Verifier::fileMatchesHash(string $path, string $sha256): bool` returns `hash_equals($sha256, hash_file('sha256', $path))`

- [ ] **Step 1:** `git init`, then create `composer.json` (above), `phpunit.xml` (bootstrap `vendor/autoload.php`, testsuite `tests`) and `.gitignore`. Run `composer require symfony/yaml && composer require --dev phpunit/phpunit`.
- [ ] **Step 2: Write the failing tests.** `setUp` generates an RSA 4096 keypair with `openssl_pkey_new` and signs `Verifier::payload(2, $sha)` with `openssl_sign(..., OPENSSL_ALGO_SHA256)` (base64 it).
  - `test_valid_signature_passes`: `assertTrue(signatureIsValid(2, $sha, $sig, $pub))`
  - `test_changed_version_fails`: `assertFalse(signatureIsValid(3, $sha, $sig, $pub))`
  - `test_changed_sha_fails`: `assertFalse(signatureIsValid(2, str_repeat('0', 64), $sig, $pub))`
  - `test_signature_from_other_key_fails`: sign with a second keypair, verify with the first public key → false
  - `test_file_hash_matches`: temp file `"hello"`, `assertTrue(fileMatchesHash($f, hash('sha256', 'hello')))`
  - `test_file_hash_one_byte_changed_fails`: temp file `"hellp"` against the hash of `"hello"` → false
- [ ] **Step 3:** Run `vendor/bin/phpunit tests/VerifierTest.php`. Expected: FAIL (class not found).
- [ ] **Step 4:** Implement `app/src/Verifier.php` with the three static methods. Comment *why* the version is part of the payload (so an old signed PHAR can't be relabelled as newer).
- [ ] **Step 5:** Run `vendor/bin/phpunit tests/VerifierTest.php`. Expected: 6 tests PASS.
- [ ] **Step 6:** Commit: `git add -A && git commit -m "feat: project scaffold and signature/hash verifier"`

### Task 2: AppLock

**Files:**
- Create: `app/src/AppLock.php`
- Test: `tests/AppLockTest.php`

**Interfaces:**
- Produces: `App\AppLock::__construct(string $path)`, `acquire(): bool` (`fopen($path, 'c')` + `flock(LOCK_EX | LOCK_NB)`), `release(): void` (`flock(LOCK_UN)` + `fclose`)

- [ ] **Step 1: Write the failing tests.**
  - `test_first_lock_succeeds`: `assertTrue((new AppLock($tmp))->acquire())`, and the file exists afterwards
  - `test_second_lock_on_same_file_fails`: `$a->acquire()` true, then `$b = new AppLock($tmp); assertFalse($b->acquire())`
  - `test_lock_can_be_taken_after_release`: `$a->acquire(); $a->release(); assertTrue($b->acquire())`
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/AppLockTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. Comment: the lock is advisory, and it belongs to the open file handle, which is why `main.php` releases it before `pcntl_exec`.
- [ ] **Step 4:** Run. Expected: 3 PASS.
- [ ] **Step 5:** Commit: `feat: advisory app lock`

### Task 3: Updater

**Files:**
- Create: `app/src/Updater.php`
- Test: `tests/UpdaterTest.php`

**Interfaces:**
- Consumes: `Verifier::signatureIsValid`, `Verifier::fileMatchesHash`
- Produces:
  - `App\Updater::__construct(string $pharPath, string $publicKeyPem, string $serverUrl)`
  - `static shouldUpdate(int $current, int $latest): bool` returns `$latest > $current`
  - `fetchLatest(): array` returns the decoded `/latest` JSON `{version:int, url:string, sha256:string, signature:string}`, via `file_get_contents($serverUrl . '/latest')`
  - `downloadVerified(array $latest): string` checks the signature first (throws if invalid), `copy($latest['url'], $pharPath . '.new')`, checks the hash (throws if mismatched), returns the `.new` path
  - `healthCheck(string $newPath, int $expectedVersion): void` runs `exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($newPath) . ' --health', $out, $code)`; throws unless `$code === 0 && trim($out[0] ?? '') === (string) $expectedVersion`
  - `swap(string $newPath): void` calls `rename($newPath, $pharPath)`

- [ ] **Step 1: Write the failing tests.**
  - `test_should_update_when_latest_is_newer`: `assertTrue(Updater::shouldUpdate(1, 2))`
  - `test_no_update_when_equal`: `assertFalse(Updater::shouldUpdate(2, 2))`
  - `test_no_update_when_older`: `assertFalse(Updater::shouldUpdate(3, 2))`
  - `test_download_rejects_bad_signature`: `$latest` with a garbage base64 signature; `expectException(RuntimeException::class)`; and assert `$pharPath.'.new'` does not exist afterwards (the signature is checked before any download)
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/UpdaterTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. Comments: the order of checks (signature → download → hash), why `.new` sits next to `app.phar` (same filesystem, so the rename is atomic), and why `rename` is safe on a running PHAR (the old process keeps its open handle to the old file's data).
- [ ] **Step 4:** Run `vendor/bin/phpunit`. Expected: all tests PASS (Tasks 1–3).
- [ ] **Step 5:** Commit: `feat: updater (fetch, verify, health check, swap)`

### Task 4: Entry script

**Files:**
- Create: `app/main.php`

**Interfaces:**
- Consumes: `AppLock`, `Updater`, `Verifier` (via `require_once __DIR__ . '/src/...'`)
- Reads from inside the PHAR: `__DIR__ . '/version.txt'` (int), `__DIR__ . '/public.pem'`

- [ ] **Step 1:** Write `app/main.php` in this order (exact copy from Global Constraints):
  1. `require_once` all three `src` files at the top. Comment: everything is loaded before the swap.
  2. Read `$version`. If `--health` is in `$argv`: echo `$version` and exit 0.
  3. `$pharPath = Phar::running(false)`. Take the lock at `dirname($pharPath) . '/app.lock'`. If that fails: `Another instance is running`, exit 2.
  4. If `--updated`: print `Your app was updated to $version`.
  5. Otherwise run `fetchLatest`. If `shouldUpdate`: `downloadVerified` → `healthCheck` → `swap`. Then, if `function_exists('pcntl_exec')`: `$lock->release()` (comment why) and `pcntl_exec(PHP_BINARY, [$pharPath, '--updated'])`. Otherwise print `App was updated to {$latest['version']}, please restart` and exit 0.
  6. If there was no update: `Your app is running version $version`.
  7. `Press Enter to exit`, `fgets(STDIN)`, `$lock->release()`, exit 0.
- [ ] **Step 2:** Syntax check: `php -l app/main.php`. Expected: `No syntax errors detected`.
- [ ] **Step 3:** Commit: `feat: app entry point`

### Task 5: Server scripts, README and end-to-end demo

**Files:**
- Create: `server/keygen.php`, `server/release.php`, `server/router.php`, `server/public/releases/.gitkeep`, `README.md`
- Generated (gitignored): `server/keys/private.pem`, `server/latest.yaml`. Generated and committed: `app/public.pem`

**Interfaces:**
- `keygen.php`: `openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA])`. Writes `server/keys/private.pem` (`openssl_pkey_export_to_file`) and `app/public.pem` (`openssl_pkey_get_details($key)['key']`).
- `latest.yaml` keys: `version` (int), `private_key` (path relative to `server/`, default `keys/private.pem`), `sha256`, `signature`.
- `release.php` (run with `php -d phar.readonly=0 server/release.php`):
  1. Load the YAML with `Symfony\Component\Yaml\Yaml::parseFile` (missing file → version 0).
  2. `$v = version + 1`.
  3. Build `server/public/releases/app-$v.phar` with `new Phar($path)`: add `main.php`, `src/*.php`, `public.pem`, and `addFromString('version.txt', (string) $v)`.
  4. Stub: `"#!/usr/bin/env php\n<?php Phar::mapPhar('app.phar'); require 'phar://app.phar/main.php'; __HALT_COMPILER();"`
  5. `$sha = hash_file('sha256', ...)`. Sign `Verifier::payload($v, $sha)` with `openssl_sign(..., OPENSSL_ALGO_SHA256)`.
  6. `Yaml::dump` → `latest.yaml`. Echo `Released version $v`.
- `router.php`: if `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/latest'`, read the YAML and echo JSON `{version, url: "http://{$_SERVER['HTTP_HOST']}/releases/app-$v.phar", sha256, signature}` with `Content-Type: application/json`. Otherwise `return false` (the built-in server serves the file from `server/public`).

- [ ] **Step 1:** Write the three scripts. Comment: the private key is outside `server/public`, so it can never be served.
- [ ] **Step 2:** `php server/keygen.php`. Expected: both PEM files exist; `app/public.pem` starts with `-----BEGIN PUBLIC KEY-----`.
- [ ] **Step 3:** Release v1 and set up the client: `php -d phar.readonly=0 server/release.php` → `Released version 1`; then `mkdir -p client && cp server/public/releases/app-1.phar client/app.phar`.
- [ ] **Step 4:** Release v2: run the release command again → `Released version 2`.
- [ ] **Step 5:** Start the server in the background: `php -S 0.0.0.0:8080 -t server/public server/router.php`. Then `curl -s localhost:8080/latest` → JSON with `"version":2`. `curl -o /dev/null -w '%{http_code}' localhost:8080/../latest.yaml` → not 200 (YAML not served).
- [ ] **Step 6:** Update demo: `cd client && echo | php app.phar`. Expected output contains `Your app was updated to 2` and `Press Enter to exit`. `php app.phar --health` → `2`. `ls client/` → `app.lock`, `app.phar`, no `app.phar.new`.
- [ ] **Step 7:** No-update demo: `echo | php app.phar` → `Your app is running version 2`.
- [ ] **Step 8:** Lock demo: in one shell run `php client/app.phar` and leave it waiting; in a second shell run `php client/app.phar` → `Another instance is running`, exit code 2.
- [ ] **Step 9:** Tamper demo: in `latest.yaml`, change `version` to `3` without re-signing, then run the client → it throws a `RuntimeException` (signature), and `php app.phar --health` still prints `2`. Restore the YAML.
- [ ] **Step 10:** Write `README.md`: what it is, setup (`composer install`, keygen), the demo steps above, the trust model (signature over `version:sha256` = authenticity, hash = integrity, public key only in the client), how the swap and restart work, the lock release before exec, and limitations (happy path only, no key rotation, Windows, the tiny lock window at restart, HTTP only for local demo).
- [ ] **Step 11:** Run `vendor/bin/phpunit`. Expected: all PASS. Commit: `feat: server scripts, README, end-to-end demo`.
