# Circularity Phase 4 — integrated MVP qualification

**Accepted locally on 2026-10-22.** All required Phase 4 gates passed after Docker recovered. This replaces the 2026-10-21 blocked checkpoint. Phase 5, commit, push and deployment remain excluded.

## Source, runtime and changes

- Baseline: `1937e16b1e93fd1645422d0ca261760a1b4cc4b8`.
- Tested source: `/Users/stev/.codex/worktrees/circularity-phase4/vmod_wesria`, preserved and uncommitted. Delivery checkout: `/Users/stev/htdoc/vmod_wesria`; the eight files below are copied with matching SHA-256 hashes. The main checkout was clean at the baseline before delivery.
- Owned `foodywesria` runtime: PHP **8.2.34**, MySQL **8.0.46**, disposable host `mysql-test`, schema `vmod_wesria_test`; framework tests use their existing SQLite configuration. No development data/configuration or credentials changed.
- Browser origin was `http://127.0.0.1:49255`, published only on loopback. Separate UI/provider FPM workers served the tested source read-only. Only Nginx joined the host-facing bridge; PHP and MySQL remained on the owned internal network. Sessions/cache were private files with a unique cookie and existing native MySQL session locking.
- Exact image identities, mount paths and networks: [runtime provenance](../../output/ego-lite/circularity-phase-4/runtime-provenance.json). Final source hashes: [manifest](../../output/ego-lite/circularity-phase-4/source-manifest.json). No matching Wesria code graph was available; callers, implementation and tests were traced directly.

Eight changed/new files:

| File | Change |
| --- | --- |
| `themes/vmod/assets/app.js` | Four-line branch in existing `pageshow`: persisted `/circularity` and child routes reload before old content is revealed. Existing concealment remains; fresh authorization determines access and discards drafts. |
| `themes/vmod/layouts/default.htm` | Existing JavaScript asset version becomes `20261021-2`. |
| `tests/vmod/check-circularity-concurrency.php` | Retains seven races, adds eight, reconciles rows and filtered summaries in every case. |
| `tests/vmod/check-circularity-http.sh` | Preserves standalone checks; adds explicit `--browser`, loopback origin and private runtime. |
| `tests/vmod/check-circularity-http.php` | Guarded native bootstrap, separate staff role/unaffected administrator, private CLI controls, exact-request barriers and credential-free receipts. |
| `tests/e2e/circularity-phase4.mjs` | One resumable egolite TaskSpace covering the approved integrated acceptance. |
| `docs/vmod-wesria-circularity-mvp-plan-2026-10-20.md` | Phase tracker and acceptance entry. |
| `docs/evidence/circularity-phase-4-2026-10-21.md` | This evidence. |

No dependency, migration, permission model, API, production writer or timeout setting changed. Qualification exposed only harness issues: macOS Bash 3 empty-array handling, Docker 29 internal-network port publishing, Laravel storage-path initialization before October's override, and two browser assertions (existing `count` unit and scrolling a visible table). Each was corrected in test code. No further business/user-flow decision was needed.

## Executed checks

All database checks ran serially. Success logs are retained under [output/ego-lite/circularity-phase-4/logs](../../output/ego-lite/circularity-phase-4/logs).

| Check | Result |
| --- | --- |
| Focused provider/connector | **29 tests / 789 assertions**, pass |
| Circularity MySQL contention | **15 observed races**, pass; identities, counts, versions, modes, quantities and summaries reconciled |
| Standalone real HTTP | Pass: isolated provider boot/auth/scope, summary/range/destination contracts, area/entry CRUD, replay/staleness/removal |
| Browser-mode HTTP preflight | Same checks pass through the owned temporary runtime |
| Egolite TaskSpace **18** | **7 scenario groups**, **5 actual BFCache returns**, **19 inspected screenshots**, **0 JavaScript exceptions** |
| Affected Core suite | **16 tests / 89 assertions**, pass |
| Scoped checks | PHP 8.2 syntax and PHPCS on both PHP harnesses; Node syntax on app/runner; Bash syntax; whitespace: pass |
| `composer test` | Exit **0**: **11 unsafe targets rejected**, Vmod **100 tests / 1,767 assertions**, Core **5 concurrency scenarios**, framework **425 tests / 2,287 assertions**, **4 existing skips** |
| Independent whole-change review | No actionable Critical, Important or Minor findings |

The four framework skips are existing weekly/monthly/quarterly/yearly dashboard date-aggregation checks unsupported by its SQLite configuration. No Circularity acceptance gate was skipped. Prior host-only handler checks were supplementary; actual browser navigation now satisfies history acceptance.

## Observed MySQL races

Every scenario observed the blocked independent process in `performance_schema.data_lock_waits`, verified ascending area-lock order, and reconciled physical/active rows, exact UUIDs, versions, modes, quantities and filtered destination summaries. The numbers below are **service-wrapper results, not HTTP evidence**. All waits were on `vmod_circularity_areas` except cross-area UUID contention, which waited on `vmod_circularity_entries`.

| Scenario | Contending result |
| --- | --- |
| Daily duplicate | 409 |
| Daily first versus individual | 409 |
| Same UUID across areas | 409 |
| Matching UUID replay | 200 |
| Opposite area moves | 200 |
| Deactivation before creation | 422 |
| Creation before deactivation | 200 |
| Individual first versus daily | 409 |
| Independent individual readings | 200; exact sum **3.125** |
| Edit versus stale edit | 409 |
| Edit versus stale removal | 409 |
| Removal versus stale edit | 409 |
| Removal versus original UUID replay | 409; no resurrection |
| Last daily removal versus new individual | 200; mode change succeeds |
| Target conflict versus area move | 409; source unchanged |

## Actual browser and timeout evidence

[Browser report](../../output/ego-lite/circularity-phase-4/report.json) records all seven journeys, runtime identity, screenshot paths and assertions. [Sanitized receipts](../../output/ego-lite/circularity-phase-4/receipts.jsonl) contain 809 events across 204 received provider requests, without credentials.

- Capture → reload → edit → remove, both input modes and conflicts pass. Overview/list reconcile `10.125 + 2.000 = 12.125`; mixed waste stays separate, recorded zero differs from missing data, issued **8** and returned **12** remain independent, and destination/scope filters agree.
- Actual **Back** for unchanged access, read revocation and workspace change; actual **Forward** for write revocation and reduced court grants. All five returned from BFCache, retained concealment and replaced the document; drafts were discarded. Native read-only/denial outcomes matched current grants. An `/account` history return retained its existing document and draft.
- Service unavailability, keyboard submission/focus and mobile horizontal table scrolling pass. All 19 screenshots were visually inspected at **1440×900** or **390×844**, with no page overflow. Examples: [desktop overview](../../output/ego-lite/circularity-phase-4/desktop-overview.png), [mobile totals](../../output/ego-lite/circularity-phase-4/mobile-totals.png), [scrolled mobile table](../../output/ego-lite/circularity-phase-4/mobile-table-scrolled.png), [read-only view](../../output/ego-lite/circularity-phase-4/revoked-write-read-only.png).

The guarded front controller held these real requests beyond the unchanged ten-second client timeout:

| Case | Initial request / elapsed | Observed outcome and explicit retry |
| --- | --- | --- |
| Committed response held | `e4e0eeeab87d3e21` / **10,428 ms** | Dispatch → HTTP 201 completion → hold → drain. UUID `c056439f-938f-4c3d-96a2-1e973eb2a123`; quantity `6.125`. Same-UUID retry `7e20c40f8c3d267a` returned HTTP 200 replay. |
| Terminated before provider dispatch | `840e2140866a6623` / **10,509 ms** | Received → hold → drain without dispatch/completion or saved row. UUID `b5d4f3e4-461d-478a-986b-c0816ecfcfeb`; quantity `7.125`. Same-UUID retry `56c775888971ef5d` returned HTTP 201. |

Each had **one initial mutation attempt**, no automatic retry, retained UUID/values and failed-lookup uncertainty. Requests drained before recovery lookup. After each explicit retry there was exactly **one physical row, version 1**.

Revocation request `46e02db9641144d3` (UUID `98055180-d7aa-46f1-b522-ff5e52a3efeb`) had already reached provider HTTP when paused. Staff write-grant revocation committed on a separate connection, then release allowed provider execution to finish with HTTP **201**, quantity `8.125`, version 1. The next unauthorized AJAX save returned **403**, with the provider received-request count unchanged. Receipt `dispatched` denotes provider kernel execution; the client had already dispatched HTTP before this barrier. This proves the permitted in-flight completion and denial of later dispatch, not cancellation.

## Commands and repeatability

The private Compose override mounted the isolated checkout, existing main `vendor` and RainLab.User dependencies, and a synthetic APP_KEY. It is at `/private/tmp/wesria-circularity-phase4/compose.yml`; it contains no development credential. Use only the owned disposable runtime and run serially:

```sh
cd /Users/stev/.codex/worktrees/circularity-phase4/vmod_wesria
wesria_compose=(docker compose -p foodywesria \
  -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml \
  -f /private/tmp/wesria-circularity-phase4/compose.yml)
"${wesria_compose[@]}" --profile test up -d --wait mysql-test
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpunit -c phpunit-vmod.xml --filter Circularity --do-not-cache-result
"${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' |
  "${wesria_compose[@]}" --profile test run --rm -T php-test php tests/vmod/check-circularity-concurrency.php
VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria bash tests/vmod/check-circularity-http.sh
VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria bash tests/vmod/check-circularity-http.sh --browser
# While READY is held, in another terminal:
ego-browser skill
ego-browser nodejs < tests/e2e/circularity-phase4.mjs
```

Inspect all screenshots before writing `pass` to the private proof directory's `complete` file; wait for successful harness exit and finish its TaskSpace once. Do not start another database suite while this runtime owns fixtures. The private pointer is `/private/tmp/wesria-circularity-phase4-runtime.json`; never print its referenced credential file. Then:

```sh
node --check themes/vmod/assets/app.js
node --check tests/e2e/circularity-phase4.mjs
bash -n tests/vmod/check-circularity-http.sh
git diff --check
"${wesria_compose[@]}" --profile test run --rm -T php-test sh -c \
  'php -l tests/vmod/check-circularity-http.php && php -l tests/vmod/check-circularity-concurrency.php && php vendor/bin/phpcs --standard=phpcs.xml tests/vmod/check-circularity-http.php tests/vmod/check-circularity-concurrency.php'
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpunit -c plugins/vmod/core/phpunit.xml --do-not-cache-result
"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test
```

## Closure and limits

TaskSpace 18 was finished once. Browser runtime exited 0; no proof containers remain, and its successful private credential/fixture directory was removed. Failure diagnostics remain privately in task-owned temporary directories. Existing worktrees and captured development data are preserved. Docker Desktop was not restarted.

This qualifies the local MVP against disposable MySQL, native UI and real HTTP. It does not establish independent-hosting readiness, production deployment, physical-device behavior or a two-second connection-blackhole test; the required ten-second response-timeout cases were exercised directly. Phase 5 is not started. No commit, push or deployment was performed.
