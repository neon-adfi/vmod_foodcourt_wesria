# Phase 5 — sales, reservations and dispatch evidence

Status: **Phase 5 complete locally.** MySQL, egolite and independent review gates pass. Implementation acceptance ended without commit, push or deployment. The user subsequently authorized scoped Git delivery; see below. Stopped before Phase 6.

## Scope and custody

- Implementation baseline and pre-release HEAD: `1197f9b2e2b7cc951ff3636a8c891110bf0291b0`, branch `main`, served checkout `/Users/stev/htdoc/vmod_wesria`. Unrelated `docs/plugin-skeletons/` remains untouched and excluded from the manifest.
- Implemented the approved sales, reservation and delivery contract: frozen commercial revisions and exact shared price conversion; current approval eligibility without repricing; atomic complete-order reservations; protected inbound/adjustment stock; partial/full dispatch, correction chains and full reversal; permanent historical revision lock; native list/detail/history/forms and inventory figures.
- `SalesService` and `DispatchService` reuse `DocumentOperation`; typed reservation/dispatch operations extend `StockService`. No separate reservation service, generic workflow, dependency, authentication change or Phase 6 work.
- No matching current target graph was indexed. Direct implementation/caller/test tracing was used, including Core company locks, catalogue product locks, shared operation replay, stock posting and native component registration.
- Ruling: use the verified existing served checkout and this requested evidence ledger, honoring the explicit no-commit/push/deployment boundary instead of adding worktree/ledger infrastructure. Cost if wrong: checkout custody would require relocating this exact uncommitted manifest; unrelated work is preserved.
- Ruling: implement exactly the approved two-service structure plus StockService operations. Cost if wrong: future workflow reuse may need a separately approved refactor; no speculative coordinator was added.
- Preflight: SO revision items supply immutable commercial/location snapshots; reservations bind those exact items; DOs consume only their own approved lines. Existing stock posting must protect both physical and reserved bounds. A required catalogue price means the existing permanent currency lock already applies.

## Runtime and schema identity

| Item | Verified identity |
| --- | --- |
| Compose | `foodywesria`; `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml` |
| Served URL/theme | `https://wesria.local.test`, `vmod` |
| PHP/nginx bind mounts | Both serve `/Users/stev/htdoc/vmod_wesria:/var/www/html` |
| PHP | `8.2.34`; image `sha256:5ade0667a0ae5d7d555f5b0ea237094381bf6fc55d8ef31430f4848d37fb9866` |
| Nginx image | `sha256:cf0b9341d232ba527fecfbf887d8515a80d5012e04cb044799f71240c2ee35b1` |
| MySQL | `8.0.46`; image `sha256:b7e118f56c5963e079252e0d6e2978c9c010eb7fc7aaaec100e367796b5dd08f` |
| Development | `mysql-dev / vmod_wesria_dev`, current user `vmod_wesria_dev@%` |
| Test | `mysql-test / vmod_wesria_test`, schema-limited user; disposable tmpfs; marker `vmod_wesria_phase1` |
| Applied versions | Core `1.0.3`, ERP `1.0.3` |

Docker inspection, guarded seed output and a read-only application bootstrap verified these identities. Migration `1.0.3` adds sales orders/revisions/items, delivery orders/items and stock reservations; extends balances with `reserved`, and movements with nullable delivery-item relationship/unique delivery posting identity. Existing receipt identities remain. No tables/schema/data were reset. Additive synthetic data is retained. Seed credentials are private, mode 0600 at `/private/tmp/wesria-phase5-acceptance.json`.

## Commands and final results

All commands run from the target checkout. The observer credential travels privately on stdin and is used only to read lock instrumentation. Application writes retain the guarded schema user.

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpunit -c plugins/vmod/erp/phpunit.xml --do-not-cache-result

# Executed separately/serially for each named harness:
for harness in check-sales-concurrency check-purchasing-concurrency check-erp-concurrency; do
  "${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' | \
    "${wesria_compose[@]}" --profile test run --rm -T php-test php "tests/vmod/$harness.php"
done

# Executed once after implementation/review repair:
"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test

"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpcs --standard=phpcs.xml plugins/vmod/erp \
  tests/vmod/check-sales-concurrency.php tests/e2e/seed-phase5.php --extensions=php
node --check themes/vmod/assets/app.js
node --check tests/e2e/phase5.mjs
git diff --check
# Each new manifest file is additionally checked with:
git diff --no-index --check /dev/null <new-file>

umask 077
"${wesria_compose[@]}" exec -T php-tech php tests/e2e/seed-phase5.php > /private/tmp/wesria-phase5-acceptance.json
# This run resumed the same space throughout:
ego-browser nodejs <<'EOF'
globalThis.vmodE2eSpaceId = 33;
await import('/Users/stev/htdoc/vmod_wesria/tests/e2e/phase5.mjs');
EOF
# Space 33 is now finished. Future independent acceptance starts the tracked runner without that override.
shasum -a 256 output/ego-lite/phase-5/*.png
```

| Gate | Result |
| --- | --- |
| Focused ERP/MySQL | **26 tests, 440 assertions**, pass, no skips |
| Phase 5 forced races | **12 exact waits observed**, pass; physical/reserved/effective-delivery reconciliation |
| Affected Phase 4 races | **9 exact waits observed**, pass |
| Adjacent catalogue races | **6 exact waits observed**, pass |
| Aggregate `composer test` | One invocation, exit 0; **59 Vmod tests, 548 assertions**, eleven unsafe-target guards, five Core races |
| Framework | **421 executed / 425 total**, 2,287 assertions; four pre-existing SQLite-only date skips unchanged |
| Scoped PHPCS / JS / whitespace | Pass, exit 0; tracked and new manifest files checked |
| Egolite | **9 scenario groups, 10 inspected screenshots, zero runtime exceptions/console errors** |
| Independent review | One P1 fixed with RED→GREEN check; no remaining confirmed findings or deferred minors |

Logs remain under ignored `output/ego-lite/phase-5/`: `erp-tests.txt`, `mysql-sales.txt`, `mysql-purchasing.txt`, `mysql-catalogue.txt`, `composer-test.log`, `phpcs.txt`, `report.json`, and `dev-reconciliation.json`. No test framework/dependency was added.

## Domain/lifecycle and reconciliation proof

`SalesReservationTest` covers frozen snapshots/repricing, exact decimals/overflow and inexact base conversion, zero/missing catalogue prices, override reasons and drift-free mode/tax toggles; approval-time eligibility versus post-approval retirement; same-product duplicate lines, split locations, multiple DOs, foreign-line/tenant/branch denial; separate write/approve/dispatch/read permissions without catalogue/inventory grants; server-derived actors, stale version/context, matching/changed/revoked retries, full audit-failure rollback.

The worked example asserts movements after each step: opening 10 → reserve 6 (still one movement) → dispatch 2 (on-hand 8, reserved 4, two movements) → close-short/release 4 (on-hand 8, reserved 0, still two movements). Reopen releases historical reservation rows and voids draft DOs; reapproval uses new revision item identities. Full reversal restores stock/reservations but retains `first_dispatch_at`; manual closure rejects correction. Unsafe replacement and physical overflow leave document/reservation/stock data unchanged.

Shared-stock checks reject adjustment/GRN reduction of 5 at on-hand 10/reserved 6; reduction of 4 succeeds. Correction can move consumption between approved lines at their fixed locations; browser-supplied replacement locations are ignored. Movement identities remain unique.

Final read-only development reconciliation spans **7 balances, 3 reservation rows, 32 movements, 22 SOs and 20 DOs** including retained earlier fixtures: **zero** physical-ledger mismatches, reservation-ledger mismatches, bounds failures, effective-delivery mismatches or duplicate receipt/delivery posting identities. Race harnesses independently reconcile their test-company records after each contention group.

## Forced MySQL observation

The holder runs an actual service operation inside an uncommitted outer transaction. An independently booted worker identifies its connection. A read-only observer joins `performance_schema.data_lock_waits`, threads and locks and requires that exact waiter/blocker/schema/table with `LOCK_STATUS = WAITING` **before** releasing the holder. Workers also assert the company lock was the first transactional read. Simultaneous start alone is not accepted.

Final sales run (blocker connection `11266`):

```text
Scarce-stock approvals: observed WAITING on vmod_erp_stock_balances (waiter 11269, blocker 11266); waiter HTTP 409; company lock first.
Identical approval replay: observed WAITING on vmod_erp_operations (waiter 11270, blocker 11266); waiter HTTP 200; company lock first.
Identical dispatch replay: observed WAITING on vmod_erp_operations (waiter 11271, blocker 11266); waiter HTTP 200; company lock first.
Competing over-dispatch: observed WAITING on vmod_erp_sales_orders (waiter 11272, blocker 11266); waiter HTTP 409; company lock first.
Close short before dispatch: observed WAITING on vmod_erp_sales_orders (waiter 11273, blocker 11266); waiter HTTP 409; company lock first.
Approval before unsafe adjustment: observed WAITING on vmod_erp_stock_balances (waiter 11274, blocker 11266); waiter HTTP 409; company lock first.
Adjustment before scarce-stock approval: observed WAITING on vmod_erp_stock_balances (waiter 11275, blocker 11266); waiter HTTP 409; company lock first.
Approval before unsafe GRN correction: observed WAITING on vmod_erp_stock_balances (waiter 11276, blocker 11266); waiter HTTP 409; company lock first.
GRN correction before scarce-stock approval: observed WAITING on vmod_erp_stock_balances (waiter 11277, blocker 11266); waiter HTTP 409; company lock first.
Full reversal before competing stock reduction: observed WAITING on vmod_erp_stock_balances (waiter 11278, blocker 11266); waiter HTTP 409; company lock first.
Concurrent SO numbering: observed WAITING on vmod_erp_document_counters (waiter 11279, blocker 11266); waiter HTTP 200; company lock first.
Revocation before approval: observed WAITING on vmod_core_companies (waiter 11280, blocker 11266); waiter HTTP 403; company lock first.
```

The final twelve-wait rerun adds connection IDs to evidence after formatting; the application behavior is unchanged. The nine purchasing and six catalogue waits also pass, including first-balance creation, inbound replay/over-receipt, closure, adjustment/correction, numbering, first-stock/UOM and revocation.

## Browser evidence and inspected captures

TaskSpace **33**, verified host URL/checkouts above, synthetic company **15**, authorized branch **27**; restricted branch and second-company access tested. SO **19** is the main lifecycle record; DO **1/2** were original deliveries, **4** the correction, **3** a rejected excess draft. SO **22** proves reopen/reapproval/void; SO **20** insufficient approval; SO **21** a valid zero-price saved draft.

Passed groups:

1. Sales search and pagination.
2. Priced-only selection, nested validation/focus, preview/save/reload.
3. Stable conversion, frozen snapshots/reprice; real CSRF, stale context/version.
4. Zero-price save, failed/successful approvals and protected adjustments.
5. Retired masters remain dispatchable; partial/full dispatch consumes own reservations once.
6. Over-dispatch/retry rejection; correction/full reversal and permanent historical freeze.
7. Delivery search/pagination; reopen releases and voids drafts, reapproval and void.
8. Tenant/branch isolation, restricted actions, denied replay and inventory privacy.
9. Mobile layouts, native fields and keyboard order.

Real missing-CSRF requests use otherwise-valid saved-draft input and return 419; stale context/version return 409, restricted actions 403, foreign company/branch records 404. Native controls perform save, approval, dispatch, correction, reversal, reopening and closure; direct AJAX covers replay/negative security cases. The browser confirms the inventory sequence 10/6/4 → 8/4/4 → 4/0/4 → 5/1/4 → 10/6/4 → 10/0/10 (on-hand/reserved/available). Protected decrease 5 fails; 4 succeeds and is restored for the lifecycle. All four master types are retired after approval and restored after reversal.

All ten captures were opened and inspected. Desktop is **1440×900**; mobile **390×844**. Native error focus and preserved input are visible; mobile forms fit the viewport and wide tables scroll within their cards. Tabler/October assets loaded; no horizontal page overflow. TaskSpace `finish({keep: []})` completed once after inspection.

| Capture | SHA-256 |
| --- | --- |
| `sales-validation.png` | `31de944d0ee8a80cdd30b8d6b153b132671ea62d72ba2e1551706c8d884fed98` |
| `sales-revisions.png` | `bf097db8010db5334c8c94235360f2f2b347ba8a7a0cc5c62e49389b3ebfce13` |
| `approval-insufficient.png` | `be71473917e9eabbf6739000237d37f9fbe518c12d8a2be6efcc8f7bd34e49cd` |
| `inventory-reservations.png` | `3282ac63ad4f7539daf7568d74b5e6efe85588a9c9dac3dedccb686e6ec3be84` |
| `sales-completed.png` | `43c66b0278266bbb5906c28253c8e1662e9bb754870baf0ab222213f2cff9bc2` |
| `delivery-correction.png` | `df59634b4e583ae5c794cc7c3db25e9215da914a6901fcadc6cdbe2aba733bb5` |
| `sales-reader.png` | `58f7189f8297a4237d50dfc54e2f6a984e58c8a93d80f1af6ad7ab2f81a0c08e` |
| `mobile-sales.png` | `4c84dd97b11cf16320f39e5d9ef30737f6345d4396260cda041b39525f202449` |
| `mobile-delivery.png` | `d852c0650a0077086e338cb237ab0e0cc38f4b476ab64531d6b5ee1939cf7ec9` |
| `mobile-inventory.png` | `835bd9265e67c1bd06a7cc5712afd539d3623db4566f51502fa3eaa8b5887a5a` |

## Independent review and repairs

Fresh-context `phase5_final_review` independently reviewed the tracked/new implementation against baseline and the revised contract, including shared locks/replay and adjacent inbound protection. It found **P1**: the new component was initially placed in plugin metadata instead of `registerComponents()`. `testNativeSalesComponentIsRegistered` reproduced the missing mapping (one failure), then passed (two assertions) after the mapping moved. Both pages and their AJAX workflows then passed egolite; final focused/aggregate suites passed. No other confirmed findings and no deferred minors.

The initial browser observation was misdiagnosed as a stale manifest; only the derived local `storage/cms/manifest.php` was refreshed. The actual registration fix above resolved it. No business data was reset. Initial PHPCS formatting diagnostics were repaired only in the five reported files; final scope is clean.

## Exact changed-file manifest

**33 files** relative to `/Users/stev/htdoc/vmod_wesria`; unrelated skeletons and ignored output are excluded.

```text
docs/evidence/phase-5-sales-reservations-2026-10-15.md
docs/runtime-phase-1.md
docs/vmod-wesria-erp-mvp-plan-2026-10-15.md
plugins/vmod/erp/Plugin.php
plugins/vmod/erp/classes/DocumentOperation.php
plugins/vmod/erp/classes/DocumentTotals.php
plugins/vmod/erp/components/Sales.php
plugins/vmod/erp/models/DeliveryOrder.php
plugins/vmod/erp/models/DeliveryOrderItem.php
plugins/vmod/erp/models/SalesOrder.php
plugins/vmod/erp/models/SalesOrderItem.php
plugins/vmod/erp/models/SalesOrderRevision.php
plugins/vmod/erp/models/StockBalance.php
plugins/vmod/erp/models/StockReservation.php
plugins/vmod/erp/services/DispatchService.php
plugins/vmod/erp/services/PurchasingService.php
plugins/vmod/erp/services/SalesService.php
plugins/vmod/erp/services/StockService.php
plugins/vmod/erp/tests/SalesReservationTest.php
plugins/vmod/erp/updates/create_sales_reservation_tables.php
plugins/vmod/erp/updates/version.yaml
tests/e2e/phase5.mjs
tests/e2e/seed-phase5.php
tests/vmod/check-sales-concurrency.php
themes/vmod/assets/app.js
themes/vmod/layouts/default.htm
themes/vmod/pages/delivery.htm
themes/vmod/pages/inventory.htm
themes/vmod/pages/sales.htm
themes/vmod/partials/delivery-line.htm
themes/vmod/partials/document-action.htm
themes/vmod/partials/document-list.htm
themes/vmod/partials/sales-line.htm
```

No remaining Phase 5 acceptance checks. The framework's four existing SQLite skips are explicitly recorded above; all Phase 5 correctness/concurrency checks ran on MySQL. At acceptance, work remained uncommitted for human review; no commit, merge, push, deployment or Phase 6 work had been performed.


## Separately authorized Git delivery

The user subsequently requested “Git commit merge and push.” Release scope is the exact 33-file manifest above. Fresh remote lookup found `origin/main` at `1197f9b2e2b7cc951ff3636a8c891110bf0291b0`, matching local `main`; a separate merge is unnecessary. Commit message: `add Phase 5 sales reservations and dispatch`. Refresh remote ancestry immediately before the normal fast-forward push, then verify remote HEAD. Preserve unrelated `docs/plugin-skeletons/`. These are the observed pre-release state and authorized procedure; the final commit/remote result is reported after delivery. No deployment is authorized.

The matching target graph remains unavailable. Scoped Git change detection confirms the manifest, and the fresh-context independent review above covers the code impact. A separate release `composer test` invocation verifies the tree being committed; its log is `output/ego-lite/phase-5/release-composer-test.log`, distinct from the single implementation-acceptance aggregate run.

Release verification passed (exit 0): 59 Vmod tests / 548 assertions, eleven unsafe-target guards, five Core races; framework 425 total / 2,287 assertions with four unchanged SQLite skips. Application file hashes match the accepted tree; only release-custody documentation changed before staging.
