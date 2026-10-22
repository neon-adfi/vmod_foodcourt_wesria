# Circularity Phase 9 — single-use items acceptance

**Date:** 2026-10-23, Asia/Kuala_Lumpur. **Status:** complete locally; uncommitted, no deployment.

## Revision and scope

Baseline `a32dac48ece75aef66e4db46c3a74ad47ebd8422`, branch `codex/circularity-phase9-single-use`; uncommitted changes. The checkout started clean. Five existing production files: provider EntryService, HTTP CircularityClient, Capture component, entries page and Overview page. Four existing test/harness files extended; existing gap/phase contract updated. No schema, dependency, permission, CSS/JavaScript asset, ERP or runtime configuration change.

The seven business rules are recorded in [Phase 9 contract](../vmod-wesria-circularity-mvp-plan-2026-10-20.md#phase-9--single-use-items-issued-capture-and-reporting). The provider owns calculations and the connector remains HTTP-only. Seven metrics require a matched provider/connector; six-metric or malformed support is unavailable, not a fabricated zero/null bucket. Existing history remains unchanged. Physical counting and cross-entry overlap are operator responsibilities; this software does not prove items avoided, recovery, stock or savings.

## Verification

- New regressions first failed because the provider rejected the new type (422) and the connector rejected seven metrics (503).
- Focused provider/bridge: **41 tests / 1,261 assertions passed**.
- Real provider-only HTTP: exact `103 + 27 = 130`, existing oil/food `12.125`, independent reusable 8/12, all three groups, scope, validation, replay, stale writes and removal passed.
- Disposable Egolite: **10 journey groups**, three actual cached Back/Forward returns, **41 visually inspected screenshots**, zero runtime errors; runner and HTTP harness **exit 0**.
- Circularity Human Test: **eight report views** (all four on desktop/mobile), Overview and form/guidance, **12 inspected screenshots**, zero JavaScript or failed-document errors; runner **exit 0**. No measurements, areas, grants or credentials changed. Existing food waste remained `10.000 kg` for 2026-10-22; the new metric remained No data.
- Aggregate: **112 Vmod tests / 2,239 assertions**, five Core concurrency scenarios, **425 framework tests / 2,287 assertions** with four existing SQLite skips; **exit 0**.
- Final PHPCS on all six changed PHP files, Node syntax and diff whitespace: pass.

Owned disposable runtime reports PHP 8.2.34, MySQL 8.0.46, `mysql-test/vmod_wesria_test`, file sessions/cache and separate native UI/provider workers. Installed Egolite 2.1.0 uses one TaskSpace, **37**, throughout acceptance, finished exactly once after inspection and successful harness completion. Source SHA-256 hashes include Overview and the final HTTP/browser runners, and match final source. The temporary browser origin was `http://127.0.0.1:54720`; the guarded runtime is now closed.

Synthetic UI acceptance proved single-use create `3`, edit `5`, explicit zero `0`, removal and next-read missing data, plus current `130` versus previous `11` and its exact record link. Existing oil CRUD and inactive history were retained. All four reports reconciled through record links on desktop 1440×900 and mobile 390×844. Scope, refresh/reset, All permitted courts, denied/read-only access, workspace changes, real provider/previous-only failure, keyboard controls and card-contained table overflow passed. Both runtimes used one Egolite TaskSpace; screenshot inspection used native contact sheets plus full-size checks.

## Acceptance attempts and review

Three initial browser attempts stopped on test-harness issues: an ambiguous disclosure selector after adding a second details block; a transient null document during navigation; and a screenshot-only hover intercepted by the existing sticky Save bar. Each was diagnosed against observed state and fixed only in the runner. Native keyboard opening plus page scrolling proved the guidance readable. Failed attempts are retained; none is counted as a passing gate. Each changed runner restarted the disposable harness while preserving the same Egolite TaskSpace.

Independent source review found no actionable production defect. It identified incorrect guidance selection in the runner; corrected selectors and the final browser rerun verify that fix. Runtime/visual evidence remained owned by the main agent; independent-version deployment was explicitly outside review scope.

## Evidence and reproduction

- [Disposable browser report and source hashes](../../output/ego-lite/circularity-phase9/report.json)
- [HTTP receipts](../../output/ego-lite/circularity-phase9/receipts.jsonl)
- [Human Test report](../../output/ego-lite/circularity-phase9-human-2026-10-23/report.json)
- [Desktop single-use report](../../output/ego-lite/circularity-phase9/desktop-single-use-period.png), [mobile report](../../output/ego-lite/circularity-phase9/mobile-single-use-period.png), [mobile guidance](../../output/ego-lite/circularity-phase9/mobile-single-use-guidance.png)

```sh
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml --profile test run --rm -T php-test php vendor/bin/phpunit -c phpunit-vmod.xml --filter Circularity --do-not-cache-result
bash tests/vmod/check-circularity-http.sh --browser
ego-browser nodejs < tests/e2e/circularity-reporting.mjs
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml --profile test run --rm -T -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html php-test composer test
node --check tests/e2e/circularity-reporting.mjs
git diff --check
```

Scoped PHPCS uses `phpcs.xml` on the three production PHP files, CaptureTest, BridgeTest and the HTTP check. Sanitized logs and the execution ledger are retained under `output/ego-lite/circularity-phase9/`; credentials remain private outside the repository.

The target graph was unavailable; relevant source/callers/tests were inspected directly. Rulings: reuse the clean mounted checkout on a feature branch; preserve uncommitted source and ignored execution evidence; run destructive acceptance only on disposable MySQL and keep the requested Human Test checks read-only. No commit, push, deployment or development-data mutation.
