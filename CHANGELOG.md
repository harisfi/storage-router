# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/). Changes are grouped as **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**, and **Security**.

## [Unreleased]

- Initial public open-source release (MIT).

### Added

- Multi-app, multi-provider encrypted storage router.
- REST API: `POST /api/upload`, `GET /api/files/{file_id}`, `DELETE /api/files/{file_id}`.
- Envelope encryption via `libsodium` streaming AEAD (`crypto_secretstream_xchacha20poly1305`).
- Per-app KEKs with versioned rotation (`bin/rotate-kek.php`).
- Storage backends: Google Drive (REST OAuth2) and local disk, behind a common provider interface.
- Least-used-space backend selection with priority tie-break and retry-on-failure.
- Admin UI: storage backends, apps, app↔backend assignments, file browser, error view.
- Per-app rate limiting (fixed 60-second window) on upload and file endpoints, plus an IP-based throttle on the admin login form.
- CLI tools: `migrate`, `create-admin`, `create-app`, `create-local-backend`,
  `list-storage-backends`, `assign-backend`, `refresh-quota`, `rotate-kek`,
  `delete-kek`, `backup`, `restore-backup`, `verify-deployment`.
- Strict KEK retention: backups include only referenced KEKs; `bin/delete-kek.php` purges an obsolete (non-current, zero-any-status-reference) version; `bin/prune-keys.php` automates the same gate on a schedule.
- Operation atomicity: KEK rotation is transactional (all re-wraps + version bump commit/rollback together), and backup/rotation/purge serialize on a shared `storage/ops.lock` so they can never race.
- Upload hardening: configurable `MAX_UPLOAD_BYTES` enforced by **counting actual bytes** read from the body (spoofed `Content-Length` can't bypass it); downloads stream with per-chunk authentication and an upfront `Content-Length` so truncation is always detectable; deletes destroy the DEK before the blob is removed. Memory is bounded via `php://temp` (5 MiB RAM cap, temp-file spill).
- Audit log for admin actions, content-level access, and operational failures.
- Deny-all `.htaccess` protection + documented Nginx equivalents for sensitive paths (shared-hosting layout).
- `bin/backup.php` consistent DB + KEK snapshot via `VACUUM INTO`, with optional `--encrypt` (passphrase-encrypted single artifact) and `bin/restore-backup.php`.
- `bin/verify-deployment.php` automated post-deploy security check, plus a `--repo` static mode run in CI so deployment structure is verified on every push.

### Fixed

- `EnvelopeEncryptor` now emits a `TAG_FINAL` for files whose size is an exact multiple of the streaming chunk size (previously they could not be decrypted); covered by `testRoundTripExactlyOneChunk`.

### Security

- Plaintext DEKs/KEKs are wiped with `sodium_memzero()` after use in both the upload and download paths, and inside the encryptor on decrypt.
