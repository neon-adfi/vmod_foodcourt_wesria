# Phase 3 catalogue evidence — 2026-10-15

Status: **Phase 3 complete locally**. MySQL, egolite and review gates passed. Git delivery subsequently authorized by the user; no deployment. Stop before Phase 4.

## Scope and execution record

- Approved scope: master data, nullable package sales prices, explicit supplier prices, fixed package conversions, base-UOM lock after first package, deactivation/restoration, permanent currency lock after first actual price, scalar snapshots and decimal totals.
- Persisted PO/SO revisions/repricing belong to Phases 4–5. No order/stock workflows in this phase.
- Baseline: `db4c5f9`, served checkout `/Users/stev/htdoc/vmod_wesria`; unrelated untracked `docs/plugin-skeletons/` preserved. No commit, push or deployment occurred during implementation acceptance.
- Ruling: implement in the explicitly approved served checkout, keeping the existing runtime mount and Git state. Track progress here rather than introducing a second plan/ledger hierarchy.
- Target graph is not indexed; existing Core implementation, callers, migrations, forms and tests traced directly.
- Native company locks coordinate ERP authorization with Core administration. ERP does not call the administration mutation wrapper or introduce a global exclusive catalogue lock.
- All monetary arithmetic uses installed brick/math 0.14.8; native decimal strings, no new dependency.

## Delivered behavior

Company-owned customers/suppliers, stock products, fixed-conversion packages, supplier offers and tax rates; branch-owned locations. One explicit service and native component provide scoped create/edit/deactivate/restore, search and pagination. Existing Tabler assets and native October validation/AJAX are reused.

Sales prices may be unset; zero remains a real price. Offers always require a price and retain supplier/package uniqueness when inactive. Catalogue prices are tax-exclusive. Any package locks its product's base UOM; package creation checks the displayed base UOM under the product lock. An actual package/offer price permanently locks company currency, including zero. Parent retirement controls availability without rewriting child flags; existing names/prices remain editable while ancestors are unavailable.

`DocumentTotals::calculate(array $lines, string $priceMode): array` uses decimal strings, half-up rounding and sums rounded lines. It accepts prices already expressed in the requested mode. Storage/validation bounds are `(19,4)` prices/conversions/quantities, `(7,4)` rates capped at 100%, and `(19,2)` amounts. Scalar snapshots survive serialization and later master edits; unset prices remain null and cannot be calculated. There is no order persistence, repricing/revision framework or stock posting in this phase.

## Runtime and schema identity

- Compose project `foodywesria`, authority `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`. Served URL `https://wesria.local.test/`, theme `vmod`.
- `php-tech` and `nginx-tech` both mount `/Users/stev/htdoc/vmod_wesria:/var/www/html`. Egolite fetched a random temporary marker from this exact checkout; the marker was then removed.
- PHP **8.2.34**; `foodywesria-php-tech:phase1`, image ID `sha256:5ade0667a0ae5d7d555f5b0ea237094381bf6fc55d8ef31430f4848d37fb9866`. Test runs use the owned `php-test` profile.
- Nginx image ID `sha256:cf0b9341d232ba527fecfbf887d8515a80d5012e04cb044799f71240c2ee35b1`.
- Both MySQL services run **8.0.46**, pinned reference `mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`, image ID `sha256:b7e118f56c5963e079252e0d6e2978c9c010eb7fc7aaaec100e367796b5dd08f`.
- Development: `mysql-dev / vmod_wesria_dev`, schema user `vmod_wesria_dev`. Test: `mysql-test / vmod_wesria_test`, schema user `vmod_wesria_test`, ownership marker `vmod_wesria_phase1`, disposable tmpfs. The historical `foodywesria` schema is untouched.
- Applied additive migrations: **Vmod.Core 1.0.3** (company `currency varchar(3)` and nullable `currency_locked_at timestamp`); **Vmod.Erp 1.0.1** (six master tables). Runtime column inspection confirms package conversion/sales price and offer price `decimal(19,4)`, tax rate `decimal(7,4)`. Core does not query ERP tables.
- Only the derived `storage/cms/manifest.php` was refreshed to discover the new plugin. No rollback/drop/reset was run. Synthetic acceptance records, including earlier interrupted runs, are preserved. Private browser account JSON is mode **0600** at `/private/tmp/wesria-phase3-acceptance.json` and is excluded from evidence.

## Checks and exact commands

Commands run from the target checkout. `wesria_compose` below abbreviates the exact repeated prefix:

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)

"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpunit -c plugins/vmod/erp/phpunit.xml --do-not-cache-result

"${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' | \
  "${wesria_compose[@]}" --profile test run --rm -T php-test php tests/vmod/check-erp-concurrency.php

"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test

"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpcs --standard=phpcs.xml \
  plugins/vmod/erp plugins/vmod/core/models/Company.php plugins/vmod/core/services/AdministrationService.php \
  plugins/vmod/core/updates/add_company_currency.php tests/vmod/check-erp-concurrency.php tests/e2e/seed-phase3.php --extensions=php

"${wesria_compose[@]}" exec -T -w /var/www/html php-tech composer check-platform-reqs
node --check themes/vmod/assets/app.js
node --check tests/e2e/phase3.mjs
git diff --check
```

| Check | Final result |
| --- | --- |
| Focused ERP MySQL suite | **7 tests, 109 assertions, pass** |
| Forced ERP lock waits | **6 scenarios, pass**, observed in `performance_schema.data_lock_waits` |
| Aggregate Vmod/Core | **40 tests, 217 assertions, pass**, plus 11 unsafe-target guard checks and five existing Core process races |
| Framework suite | **421 executed tests pass**, 425 total / 2,287 assertions; four unchanged SQLite-only date-aggregation skips |
| Scoped PHPCS | Exit 0, no diagnostics |
| Node syntax | Both files pass |
| Whitespace | Tracked diff and every scoped new file pass; new files checked with `git diff --no-index --check -- /dev/null <file>` (exit 1 without diagnostics is the expected new-file difference) |
| Served platform requirements | Exit 0, PHP/extensions compatible; command printed a Git ownership advisory, without affecting platform checks or changing global Git configuration |
| Egolite | **13 scenarios pass; nine screenshots inspected; zero JavaScript runtime exceptions/console errors** |

Focused tests cover tenant/branch isolation, permissions before malformed payload validation, inactive ancestors, restoration, duplicate inactive offers, scoped relationships, fixed conversion/base UOM, stale versions, audit actor/before-after records and rollback of both data and currency locking on an injected audit failure. Arithmetic includes exclusive `30.00/1.80/31.80`, inclusive `28.30/1.70/30.00`, half-cent tax, zero/mixed rates, rounded-line summation, no float drift, inexact stock rejection, maximum amount and summed-document overflow.

The aggregate suite ran after initial focused success, after review-driven production fixes, and after the final explicit foreign-supplier/package/inactive-offer rejection tests. Tests were not broadened beyond the required Phase 3/Core/framework gates. No new dependency or framework patch was introduced.

## Forced MySQL evidence

The harness opens a real holder transaction, invokes the service, and starts an independent PHP waiter. It **does not release the holder until MySQL reports that exact requesting connection blocked by that exact holder on the expected table with `LOCK_STATUS=WAITING`**. Each worker verifies that its first transactional read is the company lock. Parent-lock races establish an authorization snapshot before waiting, exercising MySQL REPEATABLE READ rather than only a sequential stale-value check.

The owned test service's observer credential is forwarded only through stdin for read-only `performance_schema` access; application writes still use the guarded schema user. No grants or persistent credentials were added.

| Holder → waiter | Observed locked table | Waiter result / invariant |
| --- | --- | --- |
| Currency MYR→SGD → first MYR price | `vmod_core_companies` | 409; no package inserted, SGD still unlocked |
| First SGD price → currency SGD→MYR | `vmod_core_companies` | 409; SGD and permanent lock retained |
| First unpriced package → base-UOM edit | `vmod_erp_products` | 409; original base unit retained |
| Winning record edit → same-version edit | `vmod_erp_products` | 409; winner's name/version retained |
| Base-UOM kg→g → stale kg package form | `vmod_erp_products` | 409; new UOM retained, no stale package inserted |
| Revoke catalogue.write → product write | `vmod_core_companies` | 403; no product inserted |

Final output: `ERP MySQL concurrency: 6 forced and observed lock waits passed; no schemas cleared.`

## Browser acceptance and inspected images

```sh
umask 077
"${wesria_compose[@]}" exec -T -w /var/www/html php-tech \
  php tests/e2e/seed-phase3.php > /private/tmp/wesria-phase3-acceptance.json
python3 -c 'import json; d=json.load(open("/private/tmp/wesria-phase3-acceptance.json")); assert d["a"]["company_id"] and d["b"]["company_id"]'
{ echo 'globalThis.vmodE2eSpaceId = 25;'; cat tests/e2e/phase3.mjs; } | ego-browser nodejs
```

The first browser invocation created **TaskSpace 25**, then every retry resumed it. The default sandbox could not reach egolite's bootstrap; the authorized browser command ran outside that sandbox. No installation upgrade, profile reset or new recovery space was used. TaskSpace 25 was finished after visual inspection, retaining `p1` on the catalogue at desktop width.

Final report: `output/ego-lite/phase-3/report.json`, timestamp `2026-10-15T08:19:01.514Z`. Coverage: served checkout provenance; search/pagination; all six resource forms and save/reload; native keyboard order and checkbox/decimal error focus; stale base-UOM form; blank/zero prices and permanent currency lock; duplicate inactive offers; unavailable-parent edits; retirement/restoration; stale record/context; real CSRF; read-only and tenant/branch denial; mobile navigation/forms/save. Expected negative responses are 403/404/406/409/419.

A supplemental browser request used the **fresh current-version package form**, removed `_token`, and returned **419**; reload confirmed its price/version unchanged. This rules out stale-version rejection masking the CSRF check.

All images were opened and inspected at desktop **1440×900** or mobile **390×844**. Native assets loaded, labels/feedback remained readable, mobile navigation and tables fit the viewport, and form controls remained accessible by scrolling. The runner checks viewport/PNG dimensions and absence of horizontal page overflow. SHA-256 values were independently recomputed:

| Image under `output/ego-lite/phase-3/` | SHA-256 |
| --- | --- |
| `desktop-search.png` | `f6b12c0b1c54ea72ebd207d4d9896ea1b42fb2849c758ffff97c06f1b9957143` |
| `desktop-party.png` | `f793cb5f80a799bd8674b664b86705a4cbe31ceee529940e420840071a7dee2c` |
| `desktop-validation.png` | `de2788ee197b7668738920ee1f4c75efc890246045f9549d9b0cfd315bbc18b5` |
| `desktop-currency-lock.png` | `1a558569af57b3cf81365cccefd05d21dc4bee7e0c4bc1db663aad5da7a03cee` |
| `desktop-offer.png` | `d344dcf74bd619be6417be27057e7b59ec493d492b7e73c62243347d7a460687` |
| `desktop-location.png` | `b546d709fcd5074e791af9b749c48820100f5d7e9756381d1b3211a764353719` |
| `mobile-packages.png` | `b6468d3cdf8189e1b3d217ffa18c3c4c31031310a32372f1b9dca80ecee19eed` |
| `mobile-company.png` | `71c29cd0426d0cef0ce74b7610f03e3f824efdd968086e6d6d12f326035e82a9` |
| `mobile-package-editor.png` | `41a98ee252d73be0b7a7594ba8e473e8bfb4a3f30b1c8d2c2b4df4683c92a27a` |

## Review and repair dispositions

An independent read-only review of the current files found **no remaining confirmed defects** after these repairs:

- Added stale displayed-base-UOM comparison and the reverse forced-wait race, avoiding a silent change in conversion meaning.
- Kept existing package/offer edits available with inactive ancestors while guarding new selections/snapshots.
- Moved catalogue and currency-setting validation behind context/permission checks, retaining locked reauthorization.
- Used native `ajax()->reload()` for existing-record saves: redirecting to the same fragment URL had saved data without refreshing the displayed version.
- Focused the visible party checkbox instead of its hidden fallback input; retained non-field conflict feedback in the existing page error region.
- Corrected acceptance-only mistakes: wait for a hidden record ID to exist rather than be visible; delete a copied CSRF token for the missing-token probe; obtain a read-only user's context from the existing workspace form.

Final aggregate/lint results above were collected after these changes. The canonical plan replaces the outdated posted-transaction currency threshold and Phase 3 persisted-revision requirement with the approved behavior.

## Exact scoped file manifest

```text
docs/evidence/phase-3-catalogue-2026-10-15.md
docs/runtime-phase-1.md
docs/vmod-wesria-erp-mvp-plan-2026-10-15.md
plugins/vmod/core/models/Company.php
plugins/vmod/core/services/AdministrationService.php
plugins/vmod/core/updates/add_company_currency.php
plugins/vmod/core/updates/version.yaml
plugins/vmod/erp/Plugin.php
plugins/vmod/erp/classes/DocumentTotals.php
plugins/vmod/erp/components/Catalogue.php
plugins/vmod/erp/models/Party.php
plugins/vmod/erp/models/Product.php
plugins/vmod/erp/models/ProductPackage.php
plugins/vmod/erp/models/StockLocation.php
plugins/vmod/erp/models/SupplierOffer.php
plugins/vmod/erp/models/TaxRate.php
plugins/vmod/erp/phpunit.xml
plugins/vmod/erp/services/CatalogueService.php
plugins/vmod/erp/tests/DocumentTotalsTest.php
plugins/vmod/erp/tests/MasterDataTest.php
plugins/vmod/erp/updates/create_catalogue_tables.php
plugins/vmod/erp/updates/version.yaml
tests/e2e/phase3.mjs
tests/e2e/seed-phase3.php
tests/vmod/check-erp-concurrency.php
themes/vmod/assets/app.js
themes/vmod/layouts/default.htm
themes/vmod/pages/catalogue.htm
themes/vmod/pages/company.htm
themes/vmod/partials/catalogue-fields.htm
```

Ignored screenshots/report and private credentials are not part of that source manifest. Existing unrelated `docs/plugin-skeletons/` was not edited. At the implementation acceptance checkpoint, HEAD was `db4c5f9` and nothing had been staged, committed, pushed or deployed. The user subsequently authorized committing and pushing this exact manifest. Local `main` and freshly fetched `origin/main` both matched that baseline, so no branch merge is needed. The target remains absent from the available code graph; direct scoped diff review is the fallback. Git delivery does not authorize deployment or Phase 4 implementation.

## Boundaries and next step

This is local qualification, not production deployment or human acceptance of live operations. The four existing framework SQLite skips remain disclosed; ERP transaction/decimal tests and forced races used MySQL with no skips. Native select lists intentionally suit the MVP catalogue size; replace with scoped AJAX search only if measured size warrants it. No unresolved Phase 3 gate remains. **Phase 4 is not started**; persisted purchasing/sales revisions and repricing remain future work.
