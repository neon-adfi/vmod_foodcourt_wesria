# Phase 6 — implementation and acceptance ledger

Status: **complete — locally qualified**. Baseline: `eec4ff5c8084fa7409fa8de1a9de4e8a05a8e03f`.

## Scope and custody

The user approved the grilled Phase 6 plan on 2026-10-16. Work stays in the served `/Users/stev/htdoc/vmod_wesria` checkout. PHP/nginx mount it at `/var/www/html`; PHP 8.2.34 was verified. Preserve unrelated untracked `docs/plugin-skeletons/`. No commit, push or deployment.

Approved additions: operational invoice references with fixed company/branch/direction/counterparty, many eligible same-scope document links, exact order revisions, active duplicate detection, reasoned edits/void/restore and detailed audit. Amount is a nonnegative two-decimal gross amount in linked-order currency, without allocation/accounting. Voided references are read-only; restore preserves retained links and checks number availability.

Printing uses authenticated HTML/browser PDF. PO/SO show saved commercial amounts; GRN/DO show quantities only. New issuer/receiving-location snapshots preserve names; old missing snapshots disclose current-value fallback. No attachments or server PDF generation.

Ruling: reuse the current served checkout and retain changes uncommitted, as explicitly approved; a new worktree would require repointing the runtime. Use this evidence file as the execution ledger rather than adding a separate planning framework.

Ruling: keep the shared DocumentOperation API unchanged. The new service writes detailed before/after/link audit in the same transaction alongside its standard operation audit.

Pre-flight: invoice links and print views consume immutable order revisions; order prints must accept parent-validated revision IDs. New links lock order parents before fulfilment children. Snapshot additions must preserve old revisions and existing stock operations.

## Progress

- Invoice references: complete; lifecycle, scope, duplicates, revision pinning and audit checks pass.
- Protected printing and snapshot additions: implemented; native PO/SO/GRN/DO prints and saved rename stability verified in egolite.
- Fresh installation, MySQL and egolite acceptance: complete.
- Independent review, repairs and final aggregate checks: complete.

Initial ERP suite: 32 tests / 501 assertions passed. Final expanded focused results appear below.

Fresh installation: verified no php-test workers, mysql-test tmpfs `/var/lib/mysql`, then stopped/removed/recreated only mysql-test. Guarded script proved `vmod_wesria_test`, `vmod_wesria_test@%`, MySQL 8.0.46, **0 → 97 tables**, zero frontend/backend accounts. Development data remained intact. Guarded owner-bootstrap test passed (1 test / 114 assertions before runtime-boundary additions).

Invoice concurrency: **8 exact waits pass** with correct 409 duplicate/stale/transition outcomes, 200 exact replay/number release and 403 permission revocation; stock/reservations and detailed audit counts reconcile. The new harness resolves the blocker by InnoDB transaction identity: MySQL 8.0.46 attributed an implicit inserted-row blocking lock to the requesting thread. Acceptance still verifies the exact independent waiter connection and actual holder connection; no timing-only acceptance.

Eg olite TaskSpace **43** is reused throughout. Principal catalogue → PO → partial/full GRN → SO approval → partial/full DO → invoice workflow is created via UI. Snapshots survive issuer/branch/party/location renames; escaped script-like input stays text. Browser PDFs are A4, including a four-page 60-line synthetic legacy fixture. No existing historical row was altered to simulate missing snapshots.

Unchanged-lock installation reports nothing to install/update/remove; platform requirements pass. Packagist DNS was unavailable for Composer advisory-filter retrieval, so this is installation/platform evidence, not a fresh security-advisory check. Native `October.Demo` remains registered alongside RainLab.User/Core/ERP; it is an explicit native framework allowance. Source scan finds only BackendAuth calls in the pre-existing negative identity test, not frontend application code.

## Final verification

All mandatory Phase 6 gates passed locally on 2026-10-16 (Asia/Kuala_Lumpur). No mandatory check is blocked.

| Gate | Current result |
| --- | --- |
| Fresh owned MySQL installation | 0 → 97 tables; zero frontend/backend users; October and Tailor migrations pass |
| Focused invoice/print/bootstrap tests | 9 tests / 392 assertions, MySQL, no skips |
| Final `composer test` Vmod | 68 tests / 940 assertions; 11 unsafe pre-boot targets rejected |
| Invoice-reference races | 8 forced, observed waits; expected outcomes and audit/stock reconciliation pass |
| Adjacent purchasing/sales/catalogue races | 9 / 12 / 6 forced, observed waits, run serially; ledger reconciliation passes |
| Core concurrency in aggregate | 5 synchronized independent-process scenarios pass |
| Framework SQLite suite | 425 total / 421 executed; 2,287 assertions; four existing date-aggregation SQLite skips |
| Eg olite | TaskSpace 43; 10 passing scenario groups; 20 inspected screenshots; zero observed JavaScript runtime errors |
| Browser PDF | 5 valid A4 PDFs; four single-page documents and one four-page 60-line legacy fixture; repeated headings and final totals inspected |
| Static checks | Scoped PHPCS, new PHP entry-point syntax, both JavaScript syntax checks, tracked and new-file whitespace pass |
| Dependency/runtime | Lockfile/package manifests unchanged; installation requires no changes; platform passes; expected native plugins/components only |
| Development reconciliation | 17 references, 22 successful operations, 22 detailed audits; on-hand 4.0000, reserved 0.0000; zero physical/reservation mismatches and active duplicates |

The framework SQLite skips are **not** MySQL/concurrency evidence. The application tests and 35 ERP lock-wait scenarios above run against MySQL 8.0.46 and do not skip. Native `October.Demo`, framework `backend_users` tables/components and the pre-existing backend-identity rejection test are allowed; application/frontend code introduces no backend actor fields. Historical design citations and untracked skeletons are not installed runtime code. No attachment/file-publication surface was added, so upload acceptance is inapplicable.

### Commands and custody

Run from `/Users/stev/htdoc/vmod_wesria`; logs/artifacts are under ignored `output/ego-lite/phase-6/`. All service invocations use project `foodywesria` and `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`. Exact application mounts remain `/Users/stev/htdoc/vmod_wesria → /var/www/html` in both PHP/nginx. PHP is 8.2.34, MySQL is 8.0.46, RainLab.User is locked at 3.7.2; Core 1.0.3 / ERP 1.0.4. Development uses `mysql-dev/vmod_wesria_dev`, `vmod_wesria_dev@%`; test uses `mysql-test/vmod_wesria_test`, `vmod_wesria_test@%`. No ordinary aggregate command resets a schema.

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
# Fresh-install gate only: first verify mysql-test tmpfs, ownership and zero php-test workers.
docker inspect foodywesria-mysql-test-1 --format '{{json .HostConfig.Tmpfs}}'
docker ps --filter label=com.docker.compose.project=foodywesria --filter label=com.docker.compose.service=php-test --format '{{.Names}}'
"${wesria_compose[@]}" --profile test stop mysql-test
"${wesria_compose[@]}" --profile test rm -f mysql-test
"${wesria_compose[@]}" --profile test up -d --wait mysql-test
"${wesria_compose[@]}" --profile test run --rm -T php-test php tests/vmod/check-foundation-install.php

"${wesria_compose[@]}" --profile test run --rm -T -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html php-test composer install --no-scripts --no-interaction
"${wesria_compose[@]}" --profile test run --rm -T php-test composer check-platform-reqs
"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpunit -c phpunit-vmod.xml --filter 'InvoiceReferenceTest|DocumentAccessTest|FoundationInstallTest' --do-not-cache-result

# Run each harness serially; keep observer credentials private on stdin.
for harness in references purchasing sales erp; do
  "${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' |
    "${wesria_compose[@]}" --profile test run --rm -T php-test php "tests/vmod/check-${harness}-concurrency.php"
done

"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpcs --standard=phpcs.xml plugins/vmod/erp tests/foundation/FoundationInstallTest.php tests/vmod/check-foundation-install.php tests/vmod/check-references-concurrency.php tests/e2e/seed-phase6.php --extensions=php
"${wesria_compose[@]}" --profile test run --rm -T -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html php-test composer test
node --check themes/vmod/assets/app.js
node --check tests/e2e/phase6.mjs
git diff --check
```

Browser repeat recipe (fresh synthetic companies; do not recreate them to recover a failed browser round):

```sh
umask 077
"${wesria_compose[@]}" exec -T -w /var/www/html php-tech php tests/e2e/seed-phase6.php > /private/tmp/wesria-phase6-acceptance.json
WESRIA_PHASE6_PRINCIPAL_ONLY=1 ego-browser nodejs < tests/e2e/phase6.mjs
"${wesria_compose[@]}" exec -T -w /var/www/html php-tech php tests/e2e/seed-phase6.php --legacy-print < /private/tmp/wesria-phase6-acceptance.json > /private/tmp/wesria-phase6-legacy.json
ego-browser nodejs < tests/e2e/phase6.mjs
```

The runner persists its space ID and completed steps in private fixture/evidence files. This run used 43 throughout. Principal catalogue/order/reference data came from UI forms. Additional pagination data used authenticated native AJAX. The separately guarded legacy fixture inserts new synthetic rows only; it does not erase an existing snapshot. Private JSON files remain mode 0600 and are excluded from evidence. Browser PDFs are local test artifacts; no public document URLs/files were created. Print-to-PDF is verified; physical printer delivery is not claimed.

### Observed invoice waits

| Scenario | Waited table | Waiter / blocker connection | Result |
| --- | --- | --- | --- |
| Duplicate create | invoice_references | 213 / 211 | 409; one active identity |
| Identical retry | operations | 214 / 211 | 200; no duplicate effect/audit |
| Restore versus create | invoice_references | 215 / 211 | 409; original stays voided |
| Concurrent edits | invoice_references | 216 / 211 | 409; version/data preserved |
| Link versus reopen | purchase_orders | 218 / 211 | 409; stale revision rejected |
| Link versus receipt correction | purchase_orders | 219 / 211 | 409; corrected original rejected as new link |
| Void releases number | invoice_references | 220 / 211 | 200; new active reference |
| Permission revocation | core companies | 221 / 211 | 403; no mutation |

Exact table prefixes and SQL-observed identities are in `references-concurrency.log`; the harness releases a holder only after observing the exact blocked request. Its nine successful mutations produce nine detailed audits. No failed/replayed operation adds a detailed audit. Development reconciliation separately checks all existing balances against movements and reservations.

### Independent review and repairs

Fresh-context source review used `phase6_final_review` (GPT-6 Astra, high), read-only against baseline `eec4ff5` and all changed/new Phase 6 files. It found one P2: commercial prints omitted the saved base quantity/UOM. A rendered PO assertion failed before repair, then passed after the template displayed the already-saved values. Screenshot review found mobile commercial columns splitting decimals; a 358px-table/358px-wrapper assertion failed before the screen-only 600px minimum width repair. The native scroll region is keyboard focusable; ArrowRight/ArrowLeft movement and return pass. Both regressions remain in `phase6.mjs`; affected screenshots/PDFs were regenerated and inspected, followed by final aggregate verification.

No further confirmed service authorization, scope, revision, decimal, duplicate, audit, retry or lock-order defect was found. No deferred minor finding remains. The reviewer declined to independently judge live migration/runtime/browser/test claims, deployment and unrelated skeletons. Ruling: those live claims are supported by this executor's recorded checks; deployment remains outside scope and skeletons remain untouched. The review is source-only, not a second runtime acceptance run.

Harness setup repairs made during acceptance: correct native Auth login, required catalogue fixture fields, timestamp on newly replicated legacy revision, company-scoped audit assertion, native October.Demo allowance, MySQL strict GROUP BY projection, and released restored reservations before receipt reversal. These were acceptance-fixture issues, not changes to the approved business policy. The first empty-install invocation had a missing log directory; it did not start migrations, and the next guarded invocation proved zero tables before proceeding. No migration was rolled back.

### Boundaries

No dependency, shared DocumentOperation API or external integration was added. No existing historical snapshot was backfilled. Preserve additive dev/test fixture data and unrelated `docs/plugin-skeletons/`. No commit, push or deployment. Composer's network advisory-filter refresh was unavailable (DNS); unchanged-lock installation and local platform checks passed, but no new security-advisory freshness claim is made. Previously recorded SVG-sanitizer advisories remain outside this phase. The generic MVP is locally qualified; later FoodCourt/accounting/POS work still requires its own approved scope.

## Exact Phase 6 manifest

Unrelated untracked skeletons are excluded. **34 files**:

```text
docs/create-document-standard.md
docs/evidence/phase-6-references-printing-2026-10-16.md
docs/vmod-wesria-erp-mvp-plan-2026-10-15.md
plugins/vmod/erp/Plugin.php
plugins/vmod/erp/components/DocumentPrint.php
plugins/vmod/erp/components/InvoiceReferences.php
plugins/vmod/erp/models/InvoiceReference.php
plugins/vmod/erp/models/InvoiceReferenceLink.php
plugins/vmod/erp/models/PurchaseOrderRevision.php
plugins/vmod/erp/models/SalesOrderRevision.php
plugins/vmod/erp/services/InvoiceReferenceService.php
plugins/vmod/erp/services/PurchasingService.php
plugins/vmod/erp/services/ReceivingService.php
plugins/vmod/erp/services/SalesService.php
plugins/vmod/erp/tests/DocumentAccessTest.php
plugins/vmod/erp/tests/InvoiceReferenceTest.php
plugins/vmod/erp/updates/create_invoice_references.php
plugins/vmod/erp/updates/version.yaml
tests/e2e/phase6.mjs
tests/e2e/seed-phase6.php
tests/foundation/FoundationInstallTest.php
tests/vmod/check-foundation-install.php
tests/vmod/check-references-concurrency.php
themes/vmod/assets/app.js
themes/vmod/assets/print.css
themes/vmod/layouts/default.htm
themes/vmod/layouts/print.htm
themes/vmod/pages/delivery.htm
themes/vmod/pages/document-print.htm
themes/vmod/pages/invoice-references.htm
themes/vmod/pages/purchasing.htm
themes/vmod/pages/receiving.htm
themes/vmod/pages/sales.htm
themes/vmod/partials/invoice-choices.htm
```

## Browser artifact hashes

Paths are relative to `output/ego-lite/phase-6/`. Every file hash was rechecked after the final repairs. GRN/DO PDFs were captured before later corrections; the corrected-original screenshots separately show VOIDED and preserve their original quantities. TaskSpace 43 was finished after inspection.

| Artifact | SHA-256 |
| --- | --- |
| catalogue.png | `002f5558627ed8f24776235516e05ea20e85b12529a2043a94ce52939a860dcc` |
| purchase-completed.png | `e84e82635a0621c146f2bc1d75103a5c1fe589dc21e5ca94eff2d2c1fe93937c` |
| sales-completed.png | `5adfef9445fb4861865312c605ba53f2e97a812a61dcd915d185121ecb64a3f5` |
| invoice-validation.png | `572f287244a32a3f0046baa6f3029b77c3c98206d1835b909e8c35596ba248bb` |
| invoice-audit.png | `8201f708449e5c5d0559498873db8586a5ce6220668e4ff11d4d7dbdec79c7c7` |
| print-po.png | `078497de2bc41af73f494e8e2e746592e35f723eebdbc2a73a066ec575cd9e17` |
| print-so.png | `c755b68cb954b98fbfa037a7fec2c197509c3db4e072be10c8eb04cce61223c7` |
| print-grn.png | `7074233791eaf0e1c6adbe8edc5cad5cba86e38fb2601af77cf794d4a59eb17c` |
| print-do.png | `1ca28cc178e9a507f2986efea903b30cdae51fac2c95af8a83c5b39ecd11ba47` |
| print-legacy-long.png | `861dbe0637fd90e6fd2a55c87bb1391fb477d367b9680563da15ef8b0d3ce4de` |
| print-history.png | `8d8ff552a6fcdece0037b966d6a740d2fd8044b36613a1d2d8d4ba4727f6bcd3` |
| print-corrected-grn.png | `f979bd798cf2cdbce14d36f317deeb2c2d6138cb7e24490009442ecf71d42338` |
| print-corrected-do.png | `d728747f9215c8ef19e812002db4636c40794e109a19dc9b3012fde33b7cccce` |
| print-closed-short.png | `541b0cccad90e3585f542501a2bf6cf72ea52b9c5ffecdaa49b6b62fa1663eb8` |
| invoice-search.png | `3ab095b01c8efe4db8cc31dc8da4e0ee96f013138e058654311c70157e0c6343` |
| invoice-restricted.png | `d1ba62444365ef81886b3571eef9b662d9a6974d8b78ceeac160118ee6442c1f` |
| mobile-invoice.png | `22b02014a813283a20da6bcb5ca93acd8a05ff6ee866c0013ccce1da55daa038` |
| mobile-invoice-detail.png | `8d3b6b352f6ebcb6f805b1159b9c2758c53242ec1d9d26e4fced09afdfeb178c` |
| mobile-print.png | `bcf22806d230b1281e363c9ac503c6eb74b6dd343d3a2d756cec0427edae87bb` |
| final-stock.png | `1ee47b1e7e9bb448c815db745eea36c3619338b5c6c0820431ce35787f48f6ad` |
| print-po.pdf | `6932270fa0129ea69431306611ce05cbbd6e8b34f76a413850c0e13b6495db02` |
| print-so.pdf | `1b7764a0f84150bb0bece2db4f4a69122a6659b5f12280ac662024db25a42b97` |
| print-grn.pdf | `c7fd0d893055a62073de988c1d31383456cbc3fae2cee4a646454533a58b4de7` |
| print-do.pdf | `cebaaf23289b1fb5f0d72217c030babdc7e8511332e290c9029c5a29363b5e94` |
| print-legacy-long.pdf | `2d127ee794040b672f9901719ad1c0161cebededa24d06d81b4757bf1d80b962` |

Unchanged `composer.lock` SHA-256: `e5017adecd449ead9d00de31b2561756f0c804adf8f685c91354e860719420d6`.
