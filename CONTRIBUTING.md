# Contributing

Thanks for your interest in Storage Router. This project is small by design, and contributions that respect that are the most welcome.

## Project philosophy

- **No framework, minimal dependencies.** Keep it that way — a tiny front controller and plain PHP classes. Add a package only when the cost of not adding it is clearly higher.
- **Security is the product.** Encryption, key handling, and app isolation are the core value. Changes to them need review and tests.
- **Stream with bounded memory.** Uploads/downloads must never load a full file into **RAM**: use `php://temp` (5 MiB in-memory cap, then a temp file) and chunked reads, keeping memory flat regardless of file size.

## Getting started

1. Fork and clone the repo.
2. `composer install`.
3. `cp .env.example .env` and set `DB_PATH` / `KEK_STORE_PATH`.
4. `php bin/migrate.php`.
5. Make a change, then verify: `composer validate`, `composer audit`, `php -l` on every changed file.

## Code style

- PSR-4, `App\` → `src/`.
- `declare(strict_types=1)` at the top of every PHP file.
- No comments that just restate the code; comments explain *why* (security invariants, concurrency, deployment constraints).
- Prepared statements only — never string-concatenated SQL.

## Tests

The `tests/` suite is currently a stub and building it out is a high-value contribution. If you add or change behavior, add a test where practical:

- `EnvelopeEncryptor` — round trips (small / multi-chunk / exact-64KiB / zero-byte), tamper detection.
- `KeyManager` — lazy KEK creation, rotation across versions.
- `BackendSelector` — ordering, empty candidate sets.
- `RateLimiter` / rate-limit repository — window counting, `0` disables.
- Migration idempotency — running `bin/migrate.php` twice is a no-op.

## Security

Found a vulnerability? **Do not** open a public issue. Report it privately per [SECURITY.md](SECURITY.md).

## Pull requests

- Keep PRs small and focused on one thing.
- Reference any related issue.
- Update the [CHANGELOG](CHANGELOG.md) for user-visible changes.
- Be prepared to explain the security implications of anything touching crypto, auth, or data isolation.

## Code of conduct

All interactions are governed by our [Code of Conduct](CODE_OF_CONDUCT.md).
