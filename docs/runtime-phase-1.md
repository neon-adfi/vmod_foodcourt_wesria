# Wesria local runtime

The application checkout is `/Users/stev/htdoc/vmod_wesria`. Runtime authority is `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`, project `foodywesria`. Run commands below from the application root. Existing `.env` and the shared `foodywesria` schema are preserved; container environment selects new owned databases.

## Owned services

| Service | Purpose | Database / access |
| --- | --- | --- |
| `php-tech`, `nginx-tech` | PHP 8.2.34 / local HTTP | `https://wesria.local.test`, app mount `/var/www/html` |
| `mysql-dev` | MySQL 8.0.46, persistent `foodywesria_wesria-dev-data` | `vmod_wesria_dev`, schema-limited user of the same name |
| `mysql-test` | MySQL 8.0.46, disposable tmpfs | `vmod_wesria_test`, schema-limited user of the same name |
| `php-test` | Same PHP image, `test` profile | Private `foodywesria_test` network, `10.253.49.0/24` |

Neither MySQL service publishes host ports. The PHP application is disconnected from the shared `db` network. The pre-existing `proxy` and `foodywesria_internal` networks are retained. Test and development MySQL never share a data directory or credentials.

Four `wesria-*.env` files in the external runtime directory contain private per-service credentials, created with mode 0600. Do not print, commit, or copy them into the application. Test ownership is `VMOD_TEST_DATABASE_OWNER=vmod_wesria_phase1`. Seed or destructive test operations require that marker and the exact disposable host/database/user; development seeding must separately verify `APP_ENV=local` and `mysql-dev/vmod_wesria_dev`. Phase 1 does not add synthetic development accounts.

## Commands

Use this shell array in Bash or Zsh:

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" build php-tech
"${wesria_compose[@]}" --profile test up -d --wait mysql-dev mysql-test
"${wesria_compose[@]}" run --rm --no-deps -w /var/www/html php-tech composer install --no-scripts --no-interaction
"${wesria_compose[@]}" run --rm --no-deps -w /var/www/html php-tech composer run-script post-autoload-dump
"${wesria_compose[@]}" run --rm --no-deps -w /var/www/html php-tech php artisan october:migrate --no-interaction
"${wesria_compose[@]}" run --rm --no-deps -w /var/www/html php-tech php artisan tailor:migrate --no-interaction
"${wesria_compose[@]}" up -d --no-deps php-tech nginx-tech
"${wesria_compose[@]}" exec -T -w /var/www/html php-tech php -v
"${wesria_compose[@]}" exec -T -w /var/www/html php-tech composer check-platform-reqs
"${wesria_compose[@]}" --profile test run --rm php-test composer test
ego-browser nodejs < tests/e2e/phase1.mjs
```

The pinned Composer CLI is 2.10.3. Older host Composer versions do not understand this project's pre-existing `config.policy` settings. All acceptance commands use the container, without platform bypasses. Review security audit results separately; a platform-requirements pass is not a security audit pass.

Test bootstrap rejects ordinary/development databases, missing ownership, connection URL/socket overrides, conflicting PHP environment sources and cached configuration before application boot. Eleven independent PHP processes verify rejection before autoload. The application test base rechecks resolved configuration before migrations and retains native frontend authentication, registering plugins once. The framework suite is independently forced to SQLite memory and its own fixtures; it is not MySQL/concurrency evidence. Both suites use separate temporary cache/storage paths, and the framework bootstrap also rejects cached configuration.

Use `composer test:vmod` or `composer test:framework` in the same `php-test` invocation for focused checks. Later plugin configurations use `../../../tests/vmod/bootstrap.php` and extend `Vmod\Tests\ApplicationTestCase`; the aggregate suite discovers their tests. Future stock concurrency checks must use independent processes against `mysql-test`, fail when unavailable, and never use the development schema.

Do not run project-wide `down -v` or cleanup shared networks/databases. To discard test data, stop/remove only `mysql-test`; its tmpfs is disposable. Retain development volume data. Existing historical credentials in AGENTS.md are not recreated.

## Frontend and browser scope

Phase 1 serves the framework frontend and backend-login page at `/admin` (the preserved `.env` sets `BACKEND_URI=/admin`). Native Tailor migrations create the demo theme's required content tables; no theme sample data or user accounts are seeded. `/auth`, the Vmod user model, workspaces and Tabler application screens begin in Phase 2. Adapt the local Tabler 1.5.1 distribution as specified by the MVP plan; do not introduce another Bootstrap runtime.

The egolite script uses native Node assertions and the installed ego-browser API. It checks the served application at desktop/mobile widths, required assets, navigation/reload and runtime errors, and writes a JSON report/screenshots under `output/ego-lite/phase-1`. A browser connection failure is a failed gate, not permission to substitute HTTP-only proof. Do not clear the user's browser profile or reuse unrelated login credentials.

Current acceptance is **complete for local Phase 1**; see [evidence](evidence/phase-1-foundation-2026-10-15.md). The native demo entry now uses its existing `window.jQuery`; its CodeMirror version is unchanged. Egolite screenshot capture recovered after a graceful restart of the existing installation, without upgrading or clearing the profile. TaskSpace 13 finished successfully after ten scenarios. A new invocation of the documented script creates a task space for that new acceptance run.

The framework suite retains four SQLite-only date-aggregation skips. The evidence includes the guarded disposable-MySQL command that runs all 23 report tests with no skips, covering those four cases. The restored Tailor checks use isolated fixture datasources and temporary UUID-writing files. Three existing SVG-sanitizer advisories remain; local Phase 1 acceptance is not a production-security or license approval.
