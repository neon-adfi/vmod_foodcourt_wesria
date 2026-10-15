# Phase 4 — purchasing and stock evidence

Status: **Phase 4 complete locally**. Focused MySQL tests, forced races, egolite and independent review pass. Implementation acceptance finished without commit, push or deployment. The user subsequently authorized the scoped commit and push; Phase 5 is not started.

## Scope and custody

- Baseline: `9e9be513c3492e0e738db23f7083e28e47cb8f3b` on `main`; served checkout `/Users/stev/htdoc/vmod_wesria`. Unrelated untracked `docs/plugin-skeletons/` is preserved and excluded from this manifest.
- Implemented the approved purchasing/receiving/stock contract: immutable PO revisions and price anchors, explicit repricing, partial receipts, chain-owned delivery references, correction/full reversal, opening stock, adjustments and permanent first-movement UOM locking.
- Three explicit services use existing Workspace, Audit and Brick arithmetic. One small transaction/idempotency helper serves those three callers. Native October components/forms use existing Tabler assets. No dependency, generic CRUD engine, attachment, sales, reservation, accounting or printing work was added. `DocumentTotals` and Core authentication contracts are unchanged.
- The matching target code graph was unavailable; direct implementation/caller/test tracing was used. Work stayed in the verified served checkout, with this file as the execution ledger. No reset, cleanup, Git staging or branch operation was performed during implementation acceptance.
- Git delivery was separately authorized after acceptance: commit only this 35-file manifest as `add Phase 4 purchasing receiving and stock`, then push `main`, including the existing Phase 3 commit `9e9be51`. The read-only remote check found `main` at `db4c5f9fde650f851d851f6886754a796ad674d6`; no merge is needed while it remains an ancestor. Refresh and verify fast-forward ancestry immediately before push, then verify the remote head. This records authorization and procedure, not an unobserved push result. No deployment is authorized.

## Runtime and schema identity

| Item | Verified identity |
| --- | --- |
| Compose | Project `foodywesria`, `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml` |
| Served URL / theme | `https://wesria.local.test`, `vmod` |
| Web/PHP mount | Both `nginx-tech` and `php-tech`: `/Users/stev/htdoc/vmod_wesria:/var/www/html` |
| PHP | `8.2.34`; image `sha256:5ade0667a0ae5d7d555f5b0ea237094381bf6fc55d8ef31430f4848d37fb9866` |
| Nginx image | `sha256:cf0b9341d232ba527fecfbf887d8515a80d5012e04cb044799f71240c2ee35b1` |
| MySQL | `8.0.46`; both services use image `sha256:b7e118f56c5963e079252e0d6e2978c9c010eb7fc7aaaec100e367796b5dd08f` |
| Development | `mysql-dev / vmod_wesria_dev`; schema-limited user of the same name; persistent owned volume |
| Test | `mysql-test / vmod_wesria_test`; schema-limited user of the same name; disposable tmpfs, ownership marker `vmod_wesria_phase1` |
| Applied versions | Core `1.0.3`, ERP `1.0.2` |

The guarded browser seed reports the PHP/MySQL/host/schema/theme identity above. Docker mount/image inspection and a final read-only application bootstrap confirm it. ERP 1.0.2 adds ten tables: operations, document counters, purchase orders, purchase-order revisions/items, goods receipts/items, delivery-reference claims, stock balances and stock movements. No schema was cleared. Previous interrupted synthetic fixture data remains intact; the historical shared database was not touched. Private account JSON remains mode 0600 at `/private/tmp/wesria-phase4-acceptance.json` and is not part of evidence.

## Commands and final results

Commands were run from the target checkout. The array below abbreviates the repeated Compose prefix; observer credentials travel privately through stdin and are never logged. Observer access is read-only; application mutations retain the guarded schema user.

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpunit -c plugins/vmod/erp/phpunit.xml --do-not-cache-result

"${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' | \
  "${wesria_compose[@]}" --profile test run --rm -T php-test php tests/vmod/check-purchasing-concurrency.php
"${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' | \
  "${wesria_compose[@]}" --profile test run --rm -T php-test php tests/vmod/check-erp-concurrency.php

"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test

"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpcs --standard=phpcs.xml plugins/vmod/erp \
  tests/vmod/check-purchasing-concurrency.php tests/e2e/seed-phase4.php --extensions=php
node --check themes/vmod/assets/app.js
node --check tests/e2e/phase4.mjs
git diff --check
# Each new manifest file is also checked with:
git diff --no-index --check /dev/null <new-file>
shasum -a 256 output/ego-lite/phase-4/*.png

# Guarded additive local browser fixture (private output):
umask 077
"${wesria_compose[@]}" exec -T php-tech php tests/e2e/seed-phase4.php > /private/tmp/wesria-phase4-acceptance.json
# The completed run resumed space 28 via this temporary wrapper:
# globalThis.vmodE2eSpaceId = 28; followed by tests/e2e/phase4.mjs
# A future independent acceptance starts from the tracked runner directly.
ego-browser nodejs < /private/tmp/wesria-phase4-run.mjs
```

| Gate | Final result |
| --- | --- |
| Complete focused ERP suite on MySQL | **14 tests, 197 assertions, pass**, no skips |
| Purchasing forced races | **9 exact waits observed, pass**; balances reconcile |
| Adjacent catalogue forced races | **6 exact waits observed, pass** |
| Aggregate `composer test` | Executed **once** after final fixes, exit 0; **47 Vmod tests, 305 assertions**; eleven unsafe-target guards; five Core independent-process races |
| Framework aggregate | **421 executed successfully / 425 total**, 2,287 assertions; four unchanged SQLite-only date skips |
| Scoped PHPCS / JavaScript syntax | Exit 0, no diagnostics |
| Tracked and new-file whitespace | Pass; unrelated files and generated runtime artifacts excluded |
| Egolite | **12 scenarios, eight inspected screenshots, zero JavaScript exceptions/console errors** |
| Independent review | One P2 finding repaired and reviewed again; no remaining confirmed findings |

Aggregate output is retained locally at `output/ego-lite/phase-4/composer-test.log`. Runtime browser report is `output/ego-lite/phase-4/report.json`; generated artifacts are ignored rather than committed. No new test framework or dependency was introduced.

Domain checks cover frozen/repriced snapshots, repeated inclusive/mode/tax conversions, exact quantities/overflow, partial/full receiving and cumulative over-receipt, reference normalization/chain claims, retirement, opening restrictions and permanent UOM locking, scoped permissions and foreign IDs, stale versions/context, matching/changed retry keys, revoked replay and audit-failure rollback. Lifecycle checks cover reopening/voided drafts, terminal closure, correction of completed orders, sticky receiving history after full reversal and complete rollback of unsafe corrections.

## Forced MySQL waits and reconciliation

A holder transaction invokes the actual service and launches an independent PHP waiter. Release occurs only after `performance_schema.data_lock_waits` identifies the exact requesting connection, blocking connection, schema and required table, with the requested lock `WAITING`. Every worker also verifies that its first transactional read is the company lock. Simultaneous starts or elapsed time are not the pass condition.

| Race | Required observed table | Waiter result / state assertion |
| --- | --- | --- |
| Two POs create the same first stock key | `vmod_erp_stock_balances` | 200; one balance, sum `3.0000` |
| Identical confirmation key | `vmod_erp_operations` | 200; exactly one physical posting |
| Competing receipts exceed remainder | `vmod_erp_purchase_orders` | 409; losing draft unchanged |
| Close-short before confirmation | `vmod_erp_purchase_orders` | 409; pending draft voided |
| Decrease adjustment before unsafe reversal | `vmod_erp_stock_balances` | 409; original receipt stays confirmed |
| Concurrent company PO numbering | `vmod_erp_document_counters` | 200; unique numbers, both documents retained |
| First stock before base-UOM edit | `vmod_erp_products` | 409; original UOM retained |
| UOM edit before stale opening form | `vmod_erp_products` | 409; no opening movement |
| Permission revocation before confirmation | `vmod_core_companies` | 403; receipt remains draft |

Final output: `Purchasing MySQL: 9 forced and observed waits passed; ledger reconciled; no schemas cleared.` The adjacent catalogue run also observes six waits for currency/first price, first package/UOM, stale product edits and revocation.

Tests and the race harness compare cached balances with the exact sum of signed movements after receipts, corrections, openings and adjustments, and assert unique receipt-item/action postings. A final read-only check of **all preserved development stock** found **zero balance mismatches and zero duplicate receipt postings**, including interrupted and completed browser fixtures. The comparison joins balances to movements on company/branch/location/product and compares `on_hand` with `COALESCE(SUM(quantity), 0)` using MySQL decimal arithmetic.

## Browser acceptance

Egolite TaskSpace **28** was reused throughout and finished after visual inspection. Final report time: `2026-10-15T11:04:36.182Z` (run start). Desktop **1440×900**, mobile **390×844**. All screenshot calls validate loaded Tabler/October assets, PNG dimensions and absence of horizontal page overflow. Native forms drive the successful mutations; direct requests exercise denial/retry boundaries with genuine browser sessions and tokens.

1. Search and pagination.
2. PO preview/save/reload and nested validation preservation.
3. Explicit inclusive conversion and reprice revision.
4. Approval freezes revision; stale version/context and real CSRF.
5. Partial receipt posts once and updates stock.
6. Full receipt completes PO.
7. Over-receipt rejected; correction retains both reference claims.
8. Full reversal preserves historical freeze; close-short voids drafts.
9. Pre-receipt reopening voids saved receipt drafts.
10. Opening and reasoned adjustments; unsafe reduction preserves input.
11. Tenant/branch isolation and read-only direct-action denial.
12. Mobile layout, native fields and keyboard order.

Real CSRF uses an otherwise-valid current draft request without its token and returns **419**. Stale context/version return **409**; a read-only actor's direct approval returns **403**, foreign company/unauthorized branch documents **404**. Duplicate reference and over-receipt attempts return **409**. Matching confirmation replay returns **200** without another movement. Error assertions verify retained notes/quantities and focus on the nested invalid field. Keyboard Tab moves from receipt reference to receipt date.

Final fixture records: PO **59**, first/full GRNs **13/14**, correction **16**, rejected duplicate draft **17**, reopened PO **60**. These are local synthetic record identities, not production documents.

All eight PNGs were opened and inspected. Nested validation preserves the typed quantity and notes with visible focus/error feedback. Saved inclusive unit price is `10.6000`; completed PO quantities/totals are readable. Correction history links original/replacement records with the retained reason. Movement history shows full reversals/replacements plus opening/decrease entries. Restricted views omit mutation controls. Mobile fields stack within the viewport, focus is visible and inventory balances remain readable. SHA-256 values below were independently recomputed with `shasum`.

| Screenshot under `output/ego-lite/phase-4/` | SHA-256 |
| --- | --- |
| `nested-validation.png` | `a9a832efddfb94cfbb2df37627f61642f5cbc329aa117eb00a85a6b87325c3cf` |
| `purchase-revisions.png` | `70afb32a26b594d439f877ef8782e1d5458b388f182343afeae4c288b91ee911` |
| `completed-purchase.png` | `714135d277e2ba3c3f2521f7ab98fb2ac55c264ca1312c63c12a6eb501549351` |
| `receipt-correction.png` | `74a676edd0e07b75f0f2a3c68a821b5210f1ef43b0febdd1785f1475b6bca638` |
| `stock-ledger.png` | `720411c962a191f8784568d681d069adad1fcc22902befaf52dabafd1a97d979` |
| `restricted-reader.png` | `fe3294b944f41ab24cf05829bf154a5f923b8082cb97a872d92167ca4c9005d7` |
| `mobile-receiving.png` | `dcd2c9096c64030b7b5513a4e4b14120b5860fa188af1a8a37b97b3ed009f4f8` |
| `mobile-inventory.png` | `9bc412cbea6356ff484944a0f849214daf8a3d7d1c1ad546fe636c542c240709` |

## Repairs and review dispositions

- Initial domain tests failed for the absent services, then passed as the subsystems were added. Tests were expanded before final acceptance.
- The first forced-race attempt exposed broad receipt locking reads: the waiter blocked on receipt rows before the intended stock key. Adding company predicates to the current PO/GRN reads uses the existing company/PO index. The final harness observes the intended table; no timing assertion was weakened and no extra index was added.
- A browser run stopped while waiting for stock validation feedback. An otherwise-valid reduction returned 409; a native follow-up showed the error and retained input. The runner now waits for native October/Tabler readiness after reload. A later runner assumption incorrectly tried to switch an owner into an unassigned company; the UI correctly omitted it. The runner was corrected to test stale context with that owner's second branch, then foreign-company access through the second owner. Three additive fixture sets were retained; all runs reused TaskSpace 28.
- Scoped PHPCBF corrected test formatting only. Final PHPCS is clean.
- Independent read-only review covered the implementation, callers, schema and tests, excluding unrelated files. Its sole P2 finding was that reopening validated but discarded its reason. An assertion failed before the fix. `DocumentOperation` now stores the trimmed reason in the same transactional audit; the complete ERP suite passes, and the reviewer confirmed the repair with no remaining finding. Runtime/browser qualification remains the main agent's separate evidence above.

## Exact changed-file manifest

35 files; no dependency or Core implementation changes.

```text
docs/evidence/phase-4-purchasing-stock-2026-10-15.md
docs/runtime-phase-1.md
docs/vmod-wesria-erp-mvp-plan-2026-10-15.md
plugins/vmod/erp/Plugin.php
plugins/vmod/erp/classes/DocumentOperation.php
plugins/vmod/erp/components/Catalogue.php
plugins/vmod/erp/components/Inventory.php
plugins/vmod/erp/components/Purchasing.php
plugins/vmod/erp/models/GoodsReceipt.php
plugins/vmod/erp/models/GoodsReceiptItem.php
plugins/vmod/erp/models/PurchaseOrder.php
plugins/vmod/erp/models/PurchaseOrderItem.php
plugins/vmod/erp/models/PurchaseOrderRevision.php
plugins/vmod/erp/models/StockBalance.php
plugins/vmod/erp/models/StockMovement.php
plugins/vmod/erp/services/CatalogueService.php
plugins/vmod/erp/services/PurchasingService.php
plugins/vmod/erp/services/ReceivingService.php
plugins/vmod/erp/services/StockService.php
plugins/vmod/erp/tests/PurchasingStockTest.php
plugins/vmod/erp/updates/create_purchasing_stock_tables.php
plugins/vmod/erp/updates/version.yaml
tests/e2e/phase4.mjs
tests/e2e/seed-phase4.php
tests/vmod/check-purchasing-concurrency.php
themes/vmod/assets/app.js
themes/vmod/layouts/default.htm
themes/vmod/pages/inventory.htm
themes/vmod/pages/purchasing.htm
themes/vmod/pages/receiving.htm
themes/vmod/partials/document-action.htm
themes/vmod/partials/document-list.htm
themes/vmod/partials/purchase-line.htm
themes/vmod/partials/purchase-preview.htm
themes/vmod/partials/receipt-line.htm
```

## Completion boundary

All revised Phase 4 gates passed locally. No unresolved implementation or acceptance check remains for this phase. The framework's four existing SQLite skips and previously disclosed platform advisories remain outside this change; ERP arithmetic/transaction/race gates ran on MySQL without skips. Native select options intentionally suit the MVP catalogue size; add scoped asynchronous search only when measured volume warrants it. Phase 5 must add reservation-aware negative-stock protection before sales reservations exist. No sales/reservations were introduced here. Implementation acceptance performed no commit, push or deployment; subsequent Git delivery is separately authorized above. Stop before Phase 5.
