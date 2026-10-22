# Circularity Phase 8 — used cooking oil acceptance

**Date:** 2026-10-23, Asia/Kuala_Lumpur. **Status:** complete locally; no deployment.

## Revision and scope

Baseline `8303df28a1cb34c3694199d857256edda6fc0f4f`, branch `codex/circularity-phase8-oil`; changes remain uncommitted. Source SHA-256 hashes are in the browser report and were checked against the final source. The existing dirty planning documents were preserved and updated; no unrelated changes were present or modified.

Five existing production files:

- `plugins/vmod/circularity/classes/EntryService.php`: canonical `used_cooking_oil` kg type.
- `plugins/vmod/circularityui/classes/CircularityClient.php`: oil capability, entry/filter allowlists and strict six-metric validation.
- `plugins/vmod/circularityui/components/Capture.php`: oil query filter.
- `themes/vmod/pages/circularity-reporting.htm`: metric-count-based empty-state colspans.
- `themes/vmod/pages/circularity-entries.htm`: native keyboard-accessible handover guidance.

Existing provider/bridge tests, HTTP fixture and Reporting browser runner extended. No schema, dependency, permission, CSS, JavaScript asset, ERP or runtime configuration change. Provider and connector are a coordinated contract update; independent five/six-metric version skew is unsupported and fails unavailable rather than fabricating an oil total.

## Verification

| Gate | Result |
| --- | --- |
| Regression proof | New tests first failed on unsupported oil type and acceptance of the old provider contract; then passed |
| Focused provider/bridge | 39 tests / 1,125 assertions pass |
| Real provider-only HTTP | Options, capture, exact sums, grouping, scope, validation, replay, stale writes and removal pass; harness exit 0 |
| Disposable Egolite | Eight journey groups, three actual cached Back/Forward returns, 26 screenshots inspected, zero runtime exceptions; runner exit 0 |
| Human Test Egolite | All four reports at desktop and mobile, Overview and oil form/help; 12 screenshots inspected, zero JavaScript or failed-document errors; runner exit 0 |
| Aggregate | 110 Vmod tests / 2,103 assertions; five Core concurrency scenarios; 425 framework tests / 2,287 assertions with four existing SQLite skips; exit 0 |
| Scoped checks | PHPCS on six changed PHP files, Node syntax and diff whitespace pass |
| Independent source review | No actionable correctness, regression or security findings |

Installed Egolite 2.1.0 used one TaskSpace, **33**, for both runtimes, finished exactly once after visual inspection and successful HTTP harness completion. Disposable runtime: owned `foodywesria` Compose stack, PHP 8.2.34, MySQL 8.0.46, guarded `mysql-test/vmod_wesria_test`, isolated sessions/cache and separate native UI/provider workers. Browser origin was `http://127.0.0.1:54099`; the temporary runtime is now closed. Database-owning tests ran serially.

Synthetic acceptance reconciled oil `10.125 + 2.000 = 12.125`, preserving food/mixed waste and independent 8 issued / 12 returned. UI creation `1.125`, edit `2.250`, explicit zero `0.000`, restored `2.250`, inactive history and removal affected the next report read correctly. Previous-period oil `2.750` reconciled through its own record link and was removed. All four reports and Overview passed desktop 1440×900 / mobile 390×844 checks; blank All permitted courts, filters, refresh, reset, supported drilldowns, permissions, workspace changes, real provider failure and previous-only failure were exercised. Native guidance opens by keyboard; tables scroll within their cards without page overflow.

## Requested local Human Test verification

At `https://wesria.local.test`, signed in using the existing Circularity Human Test account and selected its company-wide workspace. Verified oil is selectable with kg/decimal input and native guidance, Overview includes its missing-data state, and period/area/daily/court reports all work on desktop and 390px mobile. Applied All permitted courts through native forms; checked refresh, record drilldowns, destination filtering/reset, keyboard focus and table scrolling.

Existing food waste remained `10.000 kg` for the recorded 2026-10-22 row; oil and the other unrecorded metrics correctly showed No data. No measurements, areas, permissions or credentials were created, edited or removed in this account. Save/edit/remove and failure injection were proved in the disposable runtime, not this development workspace. This split preserves the plan's data boundary while satisfying the requested account verification.

## Evidence and reproduction

- [Disposable browser report and source hashes](../../output/ego-lite/circularity-phase8/report.json)
- [HTTP receipts](../../output/ego-lite/circularity-phase8/receipts.jsonl)
- [Human Test report](../../output/ego-lite/circularity-phase8-human-2026-10-23/report.json)
- [Desktop oil period](../../output/ego-lite/circularity-phase8/desktop-oil-period.png), [mobile oil period](../../output/ego-lite/circularity-phase8/mobile-oil-period.png), [Human Test mobile capture](../../output/ego-lite/circularity-phase8-human-2026-10-23/mobile-capture.png)

Commands run from the repository root:

```sh
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml --profile test run --rm -T php-test php vendor/bin/phpunit -c phpunit-vmod.xml --filter Circularity --do-not-cache-result
bash tests/vmod/check-circularity-http.sh --browser
ego-browser nodejs < tests/e2e/circularity-reporting.mjs
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml --profile test run --rm -T -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html php-test composer test
node --check tests/e2e/circularity-reporting.mjs
git diff --check
```

Scoped PHPCS used `phpcs.xml` and the three production PHP files plus `CaptureTest.php`, `BridgeTest.php` and `tests/vmod/check-circularity-http.php`. Sanitized logs are retained under `output/ego-lite/circularity-phase8/`; private Human Test credentials remain outside the repository.

The target graph was unavailable; source/callers/tests were traced directly. The checkout was reused on a new branch so the existing local app served the tested source; no deployment or container restart was needed. This proves software behavior, not physical weighing, downstream recovery, production cutover, oil stock, costs or conversion factors. Later capability gates remain unchanged. No commit or push was performed.
