# Circularity Reporting — Phases 6–7 qualification

**Date:** 2026-10-22, Asia/Kuala_Lumpur. **Status:** Phases 6–7 complete locally. All required gates passed; no deployment.

## Revision and scope

- Checkout: `/Users/stev/htdoc/vmod_wesria`, branch `codex/circularity-reporting`.
- Baseline: `c169d4d3c2ac8c05b0afd390b60fde3c29d97c1a`; reporting changes remain uncommitted. The browser report records SHA-256 hashes of every changed production file, its runner and HTTP harness, plus the synthetic fixture identity. Resume rejects changed source and does not reuse another fixture's passes.
- Four existing production files: `themes/vmod/layouts/default.htm`, `plugins/vmod/circularityui/components/Capture.php`, `plugins/vmod/circularityui/classes/CircularityClient.php`, `plugins/vmod/circularity/classes/CaptureApi.php`. One new page: `themes/vmod/pages/circularity-reporting.htm`.
- Existing provider/bridge tests and HTTP check extended; one focused browser runner: `tests/e2e/circularity-reporting.mjs`. Gap document and phase contract updated.
- No schema, grants, dependencies, CSS, JavaScript assets, ERP or runtime configuration changes. No commit, push, deployment or later capability.

## Runtime and phase gates

Owned Compose project `foodywesria`, configuration `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`; PHP 8.2.34, MySQL 8.0.46, guarded `mysql-test` / `vmod_wesria_test`, October 4.3 / Laravel 12. Native browser runtime uses isolated file sessions/cache, synthetic accounts and separate UI/API workers over real HTTP; the provider worker loads only Circularity. Development data is not used. Database suites run serially outside browser fixture ownership.

Phase 6 closed before Phase 7 implementation: 31 focused tests / 837 assertions; real HTTP and desktop/mobile Egolite period/navigation/drilldown checks; three inspected screenshots; harness exit 0. Artifacts are retained in `output/ego-lite/circularity-reporting/phase6/`.

Final checks:

| Check | Result |
| --- | --- |
| Focused provider/bridge PHPUnit | 36 tests / 1,008 assertions pass |
| Real HTTP provider-only integration | Legacy and optional area/date/branch grouping reconcile; invalid grouping denied; capture/auth/scope checks pass |
| Egolite native acceptance | 7 journey groups; 13 screenshots visually inspected; 3 actual BFCache history returns; zero JavaScript exceptions; runner exit 0 |
| Aggregate Vmod/Core/framework | Vmod: 107 tests / 1,986 assertions; 5 synchronized Core concurrency scenarios; framework: 425 tests / 2,287 assertions with 4 existing SQLite skips; aggregate exit 0 |
| Scoped PHPCS, Node syntax, whitespace | All pass |

Focused checks cover inclusive/leap/year periods, long ranges without an arbitrary cap, exact decimal totals, zero/missing values, edits/removals, scope, inactive history, malformed/ignored grouping, valid object-key reordering and daily keys outside the effective date range. Every group and grand total comes from one provider query; the bridge validates shapes/counts without duplicating provider quantity arithmetic.

## Browser evidence

One Egolite TaskSpace **29**, reused for both phases and the review correction run; finished exactly once after final visual inspection and HTTP harness exit 0. Final browser origin was `http://127.0.0.1:53664`; temporary runtime is now closed. Required journeys:

1. Sign in, Circularity → Reporting, desktop/mobile active navigation and month-to-date defaults.
2. Switch all four reports; filter, refresh, reset and drill down with only supported measurement parameters. Unknown-destination and mixed-waste links reconcile.
3. `10.125 + 2.000 = 12.125`, explicit `0.000`, missing values, independent 8 issued / 12 returned; separate court 100.000 and grand total 112.125.
4. UI create 1.125 → edit 2.250 → remove; next report read reflects each change. Inactive recorded areas remain visible, active empty areas/courts are explicit, daily reports do not invent missing dates. A separate previous-day 2.750 entry reconciles through the previous-period link and is then removed.
5. Read-only entry detail; 403 denied access and 404 foreign court/area. Actual BFCache Back after read revocation, Forward after court revocation, and Back after workspace change conceal and replace the cached document.
6. Real guarded provider 503 for period/grouped reports; no zero/table substitutes. Hold first summary, fail second summary, release: current 12.125 remains while previous is unavailable.
7. Keyboard Tab moves through native report/court/area controls; 390px table scroll stays within the card with no page overflow; runtime exception collection.

Local artifacts: [sanitized report](../../output/ego-lite/circularity-reporting/report.json), [HTTP receipts](../../output/ego-lite/circularity-reporting/receipts.jsonl), [desktop report](../../output/ego-lite/circularity-reporting/desktop-both-periods.png), [mobile filters](../../output/ego-lite/circularity-reporting/mobile-filters.png), [mobile scrolled table](../../output/ego-lite/circularity-reporting/mobile-grouped-scrolled.png). Logs are retained under `/private/tmp/wesria-circularity-reporting/` and copied into the ignored output folder; private credentials/fixtures are not published.

The target graph was unavailable; implementation, callers, tests and native contracts were traced directly. Final source hashes were rechecked against the browser report before releasing the runtime.

## Review and limits

Fresh independent review led to one correction pass: match grouped cells to header metric keys, qualify area names with court names, reject out-of-range daily keys and bind browser checkpoints to fixture/source identity. Three relevant regression checks were observed failing, then all 36 focused tests passed.

Two review suggestions were deliberately not expanded into shared-contract changes: provider 401 means service credentials (ApiAccess), and existing bridge behavior maps it to unavailable; frontend/workspace denial remains 403/404. Quantity and destination arithmetic remain provider-owned, verified through SQL/HTTP/browser reconciliation rather than implemented again in the bridge.

This proves local native browser behavior against synthetic disposable data. It does not qualify production hosting/cutover, real collections, human acceptance, denominators, verified recovery, ERP exchange, costs, assets or FCI. Periods are separate live reads, not a transactionally frozen snapshot. Those later capability boundaries remain in the phase contract and gap document.

## Reproduction

```sh
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml --profile test run --rm -T php-test php vendor/bin/phpunit -c phpunit-vmod.xml --filter Circularity --do-not-cache-result
bash tests/vmod/check-circularity-http.sh --browser
ego-browser nodejs < tests/e2e/circularity-reporting.mjs
```

The HTTP command waits for browser acceptance. Inspect screenshots, write `pass` to its private proof directory's `complete` file, require harness exit 0, then finish the one TaskSpace once. Run the owned-container `composer test` aggregate only after this runtime closes; use the command-local Git safe-directory variables documented in `docs/runtime-phase-1.md`. Run scoped PHPCS on the six changed PHP files, `node --check tests/e2e/circularity-reporting.mjs`, and `git diff --check`.
