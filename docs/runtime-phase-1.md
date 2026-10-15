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

Phase 1 acceptance is **complete locally**; see [evidence](evidence/phase-1-foundation-2026-10-15.md). The native demo entry now uses its existing `window.jQuery`; its CodeMirror version is unchanged. Egolite screenshot capture recovered after a graceful restart of the existing installation, without upgrading or clearing the profile. TaskSpace 13 finished successfully after ten scenarios. A new invocation of the documented Phase 1 script creates a task space for that acceptance run.

The framework suite retains four SQLite-only date-aggregation skips. The evidence includes the guarded disposable-MySQL command that runs all 23 report tests with no skips, covering those four cases. The restored Tailor checks use isolated fixture datasources and temporary UUID-writing files. Three existing SVG-sanitizer advisories remain; local Phase 1 acceptance is not a production-security or license approval.

## Phase 2 identity/workspaces

`themes/vmod` is now the configured frontend theme (`config/cms.php` plus the ignored local `ACTIVE_THEME=vmod` override). `/auth` supports native frontend login/recovery; `/workspaces`, `/account`, `/settings/company`, `/settings/members` and `/settings/roles` are the Core screens. No historical credentials were reused. Bootstrap a new owner only through the guarded interactive command, which hides password input:

```sh
"${wesria_compose[@]}" exec -w /var/www/html php-tech php artisan vmod:bootstrap
```

After introducing a new local plugin, October's non-debug plugin manifest may need refreshing. During this phase only the derived `storage/cms/manifest.php` was removed, then `october:migrate` ran against `mysql-dev`. Core migrations are additive; do not drop tables to resume interrupted work.

Core restores Laravel 12's native CSRF middleware on the CMS route because the installed October AJAX handler did not enforce it. The same route uses native `block(60, 10)` session blocking. Its dedicated `vmod_session` cache store uses Laravel's database lock driver and the standard `vmod_core_session_locks` table; the host bind-mounted file lock store failed concurrent request acceptance. Session contents still use the native file session driver. No custom session or locking implementation was introduced.

Run Phase 2 acceptance with the existing owned services:

```sh
"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpcs --standard=phpcs.xml plugins/vmod/core --extensions=php
umask 077
"${wesria_compose[@]}" exec -T -w /var/www/html php-tech \
  php tests/e2e/seed-phase2.php > /private/tmp/wesria-phase2-acceptance.json
python3 -c 'import json; d=json.load(open("/private/tmp/wesria-phase2-acceptance.json")); assert d["a"]["company_id"] and d["b"]["company_id"]'
ego-browser nodejs < tests/e2e/phase2.mjs
```

The Git safe-directory setting is command-local, not a global Git configuration change. The seed verifies CLI/local/owned database identity before writes and creates only synthetic records. Treat its private JSON as a secret; never print or publish it. The browser runner uses native Node assertions, reads local log mail privately, and writes only sanitized evidence. It creates a TaskSpace for a new run; use `globalThis.vmodE2eSpaceId` to resume the same space after failure, then finish it after screenshot inspection. This acceptance used space 22 throughout.

Native `MAIL_MAILER=log` is the local sink for invitations and recovery. Successful local acceptance proves rendering, token handling and application state; it does not prove external transport or inbox delivery. See [Phase 2 evidence](evidence/phase-2-core-2026-10-15.md) for commands, results, checksums and boundaries.

## Phase 3 catalogue

`Vmod.Erp` 1.0.1 and Core 1.0.3 are applied additively to the owned dev/test schemas. `/catalogue/products` provides native Tabler master-data screens; company settings now own currency and its permanent first-price lock. Phase 3 is complete locally; see [exact commands, forced waits and browser evidence](evidence/phase-3-catalogue-2026-10-15.md).

The focused runner is `php vendor/bin/phpunit -c plugins/vmod/erp/phpunit.xml --do-not-cache-result` in `php-test`. Aggregate `composer test` discovers ERP tests. The separate guarded `tests/vmod/check-erp-concurrency.php` needs the owned `mysql-test` observer credential privately on stdin to read `performance_schema.data_lock_waits`; application writes retain the schema-limited user. It releases held rows only after observing the exact wait, never on timing alone. No schema reset or privilege grant is needed.

Browser acceptance uses `tests/e2e/seed-phase3.php` (owned local CLI only) and `tests/e2e/phase3.mjs`; private seed JSON is `/private/tmp/wesria-phase3-acceptance.json` with mode 0600. This run used and finished TaskSpace 25 after nine screenshot inspections. If an acceptance attempt has already saved a price, use a fresh additive synthetic company to repeat the initially-unlocked currency scenario; never clear a permanent lock or reset existing data. Stop before Phase 4.

## Phase 4 purchasing and stock

`Vmod.Erp` 1.0.2 adds ten tables to the owned dev/test schemas; Core remains 1.0.3. `/purchasing`, `/receiving` and `/inventory` provide native Tabler document, correction and stock flows. The first stock movement now permanently locks product base UOM alongside the existing first-package rule. Phase 4 is complete locally; see [the full evidence](evidence/phase-4-purchasing-stock-2026-10-15.md).

The focused ERP command above now runs 14 tests/197 assertions. `tests/vmod/check-purchasing-concurrency.php` takes the same private observer credential on stdin and forces nine exact MySQL waits, then reconciles balances with movements. The adjacent catalogue harness still passes all six waits. The final aggregate run passes 47 Vmod tests/305 assertions, five Core races and the framework suite with its four existing SQLite skips.

Browser acceptance uses the guarded additive `tests/e2e/seed-phase4.php` and `tests/e2e/phase4.mjs`. Keep `/private/tmp/wesria-phase4-acceptance.json` private with mode 0600. Resume an interrupted browser run using `globalThis.vmodE2eSpaceId`; never create a replacement TaskSpace to recover an error. This acceptance used TaskSpace 28 throughout and finished it after all 12 scenarios and eight screenshot inspections. Use fresh additive fixture companies when repeating one-time opening entries; preserve previous stock, movement and document history. Stop before Phase 5. No commit, push or deployment was performed.


## Phase 5 continuation

Phase 5 is qualified locally on the same served checkout and owned services; see [Phase 5 evidence](evidence/phase-5-sales-reservations-2026-10-15.md). Core remains `1.0.3`; ERP `1.0.3` adds six sales/delivery/reservation tables, balance reservations and delivery movement identities. Preserve existing data; no reset or deployment is part of this work.

Run the guarded `tests/vmod/check-sales-concurrency.php` with the same private observer-stdin invocation as the purchasing harness. It forces twelve exact MySQL waits and reconciles physical stock, line reservations and delivery consumption. The affected nine purchasing and six catalogue waits also pass. The focused ERP suite passes 26 tests / 440 assertions; the single final aggregate run passes 59 Vmod tests / 548 assertions and five Core races, with the framework’s four existing SQLite skips unchanged.

Browser acceptance uses `tests/e2e/seed-phase5.php` and `tests/e2e/phase5.mjs`. Seed only in the owned local runtime and keep its JSON at `/private/tmp/wesria-phase5-acceptance.json` with mode 0600. TaskSpace 33 completed nine scenario groups, ten inspected screenshots and zero JavaScript runtime errors; it was finished after inspection. Reuse a space while resuming a run; future independent acceptance starts a new run. Additive fixture records remain. Stop before Phase 6.
