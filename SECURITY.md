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

1. **Encryption and key management** — KEK/DEK handling, nonce misuse, key-rotation correctness, and the KEK/DB separation rule.
2. **App isolation / access control** — any way an app (or `user_id`) can read, write, or delete another's files.
3. **Secret handling** — `.env`, KEK files, OAuth refresh tokens, API keys at rest or in logs.
4. **The local-backend capacity check** — the TOCTOU-safe transaction that enforces per-backend caps.
5. **Path traversal / injection** — PDO-prepared statements and local-backend path validation.

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
