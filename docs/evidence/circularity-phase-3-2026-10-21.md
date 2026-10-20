# Circularity Phase 3 — live totals acceptance

**Result:** Complete locally on 2026-10-21. The native `/circularity` overview reconciles to filtered active measurements. Phase 4 remains not started. Git commit, merge and push were separately authorized after acceptance; deployment remains outside scope.

Baseline: clean `main` at `2fe395369fff071fb2abe83bb920952cf2ee955b`. Implementation used `/Users/stev/.codex/worktrees/circularity-phase3/vmod_wesria`; an exact manifest was copied to the served `/Users/stev/htdoc/vmod_wesria` for acceptance. Both retained uncommitted changes at the acceptance gate. The subsequent Git release uses the served checkout; the detached implementation worktree is preserved.

## Delivered

- Protected read-only `/api/circularity/v1/summary`, sharing inclusive range/scope filters with entry listing. Both range boundaries are required; invalid/reversed ranges and exact-date/range combinations fail validation. Legacy exact-date requests remain supported.
- One grouped SQL `SUM`/`COUNT` query, combined with installed `Brick\Math\BigDecimal`. All five metrics remain separate, include inactive-area history and exclude removed entries. Quantities remain strings; aggregate totals may exceed the per-entry limit.
- Month-to-date overview, all-history measurement-list default, local filtered drilldowns, existing 25-row pagination, explicit unknown destinations and independent issued/returned counts. Destination applies uniformly.
- Summary shape validation, safe unavailable rendering, editable GET filter correction and court/area dependency initialization. Existing workspace authorization and protected-history handlers are reused.

No schema, index, permission, dependency, credential or write-protocol change. Core and ERP source are unchanged. Runtime remains the owned `foodywesria` Compose project: PHP 8.2.34, MySQL 8.0.46, `mysql-dev/vmod_wesria_dev` for guarded additive browser fixtures and `mysql-test/vmod_wesria_test` for application/HTTP tests. Synthetic fixture records remain locally; existing business data was not reset.

## Verification

| Check | Observed result |
| --- | --- |
| Final focused provider/connector suite | **29 tests / 789 assertions passed** |
| Aggregate Vmod suite | **100 tests / 1,767 assertions passed** |
| Database guard / adjacent Core concurrency | **11 unsafe targets rejected / 5 existing synchronized scenarios passed** |
| Separate framework suite | **425 tests / 2,287 assertions; 4 existing SQLite skips** |
| Real HTTP | One UI worker → one API worker; provider-only boot, authorization, summary, range validation, CRUD/replay/stale/removed behavior passed |
| Egolite | **8 scenario groups, 12 inspected screenshots, zero recorded JavaScript runtime errors** |
| Scoped PHPCS, Node syntax and whitespace | Passed |
| Fresh independent review | No application defects; native-date keyboard harness assumption corrected |

Provider checks cover `10.125 + 2.000 = 12.125`, separate mixed waste, valid 8 issued/12 returned, zero versus missing, destination filtering, totals above the row limit, inclusive ranges, reporting-month/timezone boundaries, legacy dates, pagination, denied scope, inactive areas and edits/removals on the next read. Connector checks reject malformed summary payloads and exercise native CMS rendering when `/summary` fails.

Browser TaskSpace **16** was reused and finished after visual inspection. Desktop 1440×900 and mobile 390×844 checks cover overview-to-list reconciliation, filter correction, all-history/legacy-date pagination, read-only access, court/area isolation, edited/removed quantities, inactive-area history, reload, keyboard traversal and responsive tables. The mobile table scrolls inside its container without page overflow. Supplemental lower-page screenshots show the post-edit state (11.125 kg food waste after removing the 2.000 kg record).

Evidence: `/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase-3/report.json` and its twelve PNGs. Private logs are in `/private/tmp/wesria-circularity-phase3/`: `focused-lint-final.log`, `aggregate-final.log`, `http.log`, browser logs, `manifest.json` and `progress.md`. Fixture secrets remain only in the mode-0600 `/private/tmp/wesria-circularity-phase3-acceptance.json`.

## Decisions, corrections and limits

- Date validation checks types/formats before comparing canonical dates. A regression reproduced Laravel's cross-field date rule throwing on an array; malformed input now returns a correctable validation response.
- The isolated worktree lacked the ignored application key, causing the first aggregate attempt's Core recovery failures. A synthetic key in the private test Compose override fixed the harness; application/runtime configuration was not changed.
- Native date controls have internal keyboard segments; acceptance now allows bounded Tab progression. The unavailable preview also required scheduling navigation before handling its intercepted request. Both were harness corrections.
- Unavailable-state proof combines a real native CMS render with a simulated upstream HTTP 503, then replays that HTML as a browser HTTP 503. This is not a live provider outage or timeout qualification.
- Workspace recovery used a real server-side workspace switch and synthetic persisted `pagehide`/`pageshow` events. It verifies the shared recovery handler, not actual browser BFCache admission.
- No new circularity concurrency scenarios, uncertain-write qualification, independent-host rehearsal or human operational acceptance is claimed. These remain later-phase work. The four framework skips are unchanged SQLite cases.
- No matching current target graph was available. Impact review used implementation, callers, interfaces and tests directly.

## Changed-file manifest

Provider: `plugins/vmod/circularity/{classes/CaptureApi.php,routes.php,tests/CaptureTest.php}`.

Connector: `plugins/vmod/circularityui/{classes/CircularityClient.php,components/Capture.php,tests/BridgeTest.php}`.

Frontend: `themes/vmod/{assets/app.js,layouts/default.htm,pages/circularity.htm,pages/circularity-entries.htm,pages/circularity-areas.htm}`.

Checks: `tests/vmod/check-circularity-http.php`, `tests/e2e/circularity-phase3.mjs`, `tests/e2e/seed-circularity-phase3.php`.

Documentation: this evidence and `docs/vmod-wesria-circularity-mvp-plan-2026-10-20.md`. **16 files total.**

## Repeat checks

Run serially from the served checkout, using the owned disposable test service:

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpunit -c phpunit-vmod.xml --filter Circularity --do-not-cache-result
bash tests/vmod/check-circularity-http.sh
"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test
"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpcs \
  --standard=phpcs.xml plugins/vmod/circularity/classes/CaptureApi.php \
  plugins/vmod/circularity/routes.php plugins/vmod/circularity/tests/CaptureTest.php \
  plugins/vmod/circularityui/classes/CircularityClient.php \
  plugins/vmod/circularityui/components/Capture.php plugins/vmod/circularityui/tests/BridgeTest.php \
  tests/vmod/check-circularity-http.php tests/e2e/seed-circularity-phase3.php --extensions=php
node --check themes/vmod/assets/app.js
node --check tests/e2e/circularity-phase3.mjs
git diff --check
```

This execution added a private Compose override for the implementation worktree and existing dependency mounts. The HTTP harness used `VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria`. Database guards were unchanged.

For a fresh browser acceptance, archive the previous private fixture/report first. Run `tests/e2e/seed-circularity-phase3.php` once through the owned local `php-tech` service with `umask 077`, saving stdout to `/private/tmp/wesria-circularity-phase3-acceptance.json`. Generate the unavailable preview by running `BridgeTest::testUnavailableOverviewRendersTheNativeWarningWithoutTotals` in `php-test`, mounting the private proof directory at `/proof` and setting `VMOD_CIRCULARITY_ERROR_HTML=/proof/unavailable.html`. Then run `ego-browser nodejs < tests/e2e/circularity-phase3.mjs`. Resume an interrupted run using its existing fixture/TaskSpace; never reclaim finished TaskSpace 16 or reseed a running acceptance. Inspect all screenshots and finish that space once.
