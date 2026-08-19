# Security Policy

Storage Router handles encryption keys, OAuth credentials, and file content. We take security reports seriously and respond promptly.

## Reporting a vulnerability

**Do not open a public GitHub issue for security problems.**

Please report privately by email to the maintainer (see the commit author / project owner). Include:

- A description of the vulnerability and its impact.
- The affected version(s) and the conditions to reproduce.
- Proof of concept or repro steps, if any.

You should receive an acknowledgement within 3 business days. We'll keep you updated as we triage and fix, and we'll credit you when the fix is released (unless you prefer to remain anonymous).

## Scope

Things we care about most, in priority order:

1. **Encryption and key management** — KEK/DEK handling, nonce misuse, key-rotation correctness, the KEK/DB separation rule, and the KEK retention/purge policy (`bin/delete-kek.php`, backup key selection).
2. **App isolation / access control** — any way an app (or `user_id`) can read, write, or delete another's files.
3. **Secret handling** — `.env`, KEK files, OAuth refresh tokens, API keys at rest or in logs, and whether the backup/restore path (`bin/backup.php --encrypt`, `BackupCipher`) leaks plaintext.
4. **Auth hardening** — the per-IP admin-login throttle and per-app API rate limiting.
5. **The local-backend capacity check** — the TOCTOU-safe transaction that enforces per-backend caps.
6. **Path traversal / injection** — PDO-prepared statements, local-backend path validation, and verified access from outside the webroot (`.htaccess`/Nginx `deny` rules).

## Out of scope

This system protects data **at rest** on storage it writes to, and the app-isolation of the router itself. It does **not** protect against the underlying environment, and you should not rely on it for these cases — these are deployment/host responsibilities, not something this software can fix:

1. **Compromised application host** — the router does **not** protect against an attacker who already has execution (or root/shell) access to the server it runs on. Once the host is compromised, in-memory keys, the SQLite DB, and `storage/` are all reachable regardless of this code.
2. **Shared-hosting misconfigurations** — it does **not** protect against a web server that ignores the deny rules (e.g., Nginx without equivalent `deny` blocks, or Apache with `AllowOverride` off). Point the document root at `public/` and run `bin/verify-deployment.php`; if you cannot verify the guards, do not store sensitive data there.
3. **Memory scraping on shared environments** — it does **not** guarantee protection against other tenants reading process memory on shared hosting. Keys are kept in memory during operations (`sodium_memzero` bounds their lifetime, but on shared hosting you must assume memory isolation is imperfect).
4. **Social engineering / credential theft** — stolen admin passwords or API keys are **outside** the cryptographic threat model. The app cannot distinguish a legitimate holder from a thief; account/credential handling is the operator's responsibility.
5. **Physical theft of an unencrypted backups/DB copy** — if you store a backup outside `--encrypt`, that copy is only as safe as wherever you put it (see README "Backup").
6. **Denial of service / network floods** — raw traffic floods must be absorbed at the edge (Nginx `limit_req`/CDN); the in-app layer is not an anti-DoS substitute.

## Supported versions

| Version | Supported |
|---|---|
| latest release | ✅ |
| older releases | ❌ — upgrade to the latest |

## Reporting expectations

- **Private disclosure** until a fix is released.
- **Reproducible details** — vague reports are hard to act on.
- If you've confirmed a real issue and need help with a fix, mention that; we'll coordinate.

Thank you for helping keep encrypted storage safe.
