# Vmod Wesria ERP foundation evaluation

Date: 2026-10-15 (Asia/Kuala_Lumpur). Status: source evaluation finalized; application implementation has not started.

Implementation contract: [Vmod Wesria ERP MVP plan](vmod-wesria-erp-mvp-plan-2026-10-15.md). The plan is the single source of truth for the confirmed stock and tax rules, implementation defaults, phase gates and progress.

## 1. Objective and evidence boundary

Build a lean generic ERP foundation in `/Users/stev/htdoc/vmod_wesria`, suitable for later FoodCourt and other products. Reimplement selected application behavior under `Vmod`; do not copy the Gaia plugin tree or preserve its dependency chain through bulk renaming. Retain native October/Laravel and compatible third-party capabilities.

The source repository is `/Users/stev/htdoc/netsemailparsing`. The reference is its complete tree at **`1d5f54ff55f8c1de225da53b00052f7e38f21752`**, titled `record FoodCourt staging completion verification`, not merely that commit's diff. Its clean reference worktree was verified at `/Users/stev/.codex/worktrees/foodcourt-fulfilment-fixes/netsemailparsing`. Recheck HEAD before using that directory; otherwise use `git show <SHA>:<path>` from the source repository. The source's main checkout was on a different branch and contained unrelated changes.

No matching code graph existed for the target or this exact source worktree during evaluation. Pinned Git files were used instead. Historical graph entries and staging screenshots were not treated as current runtime proof.

The audit did not install packages, start services, run migrations/seeds, modify application code or change databases. Saving this evaluation and its companion plan changes documentation only.

## 2. Verified starting point

These observations were made on 2026-10-14/15; runtime and dependency facts must be rechecked at Phase 1.

| Area | Evidence | Consequence |
| --- | --- | --- |
| Target checkout | Initial commit `ef09ef701840882e7ccb2019dd1b7a03d56c43ea`; October demo plugin/theme; untracked `docs/` | This is a new application implementation. Preserve the existing documents. |
| Target dependencies | `composer.json` specifies PHP `^8.2`, October Rain `^4.3`, Laravel `^12.0`; local lock/vendor contain Rain 4.3.4, Laravel 12.66.0 and PHPUnit 9.6.36 | Keep the target framework generation; do not transplant October 3 application wiring. |
| Source dependencies | Pinned source specifies October 3.6/Laravel 9 and RainLab.User `^2.1` | Authentication, plugins, AJAX and migrations require version-specific review. |
| PHP runtime | Host CLI 8.5.3; `/Users/stev/htdoc/docker_foody_wesria/Dockerfile_php8` starts from `php:8.4-fpm` | Neither proves PHP 8.2 compatibility. |
| Dependency incompatibility | Locked Symfony clock, css-selector, event-dispatcher, filesystem, string and translation versions require PHP 8.4.1+; doctrine/instantiator 2.1.0 requires PHP 8.4 | Re-resolve incompatible dependencies with an explicit PHP 8.2 platform target. A Docker version change alone is insufficient. |
| Reproducibility | `.gitignore` ignores `composer.lock` | Track the resolved application lockfile when implementation begins. |
| Existing services | `foodywesria-php-tech-1` and `foodywesria-nginx-tech-1` were stopped; both mount the target at `/var/www/html` | No live application acceptance is claimed. |
| Compose authority | Container labels identify `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml` | Runtime changes affect the dedicated Wesria services outside the application repo. Recheck mounts; preserve shared services. |
| Existing database | Target configuration names MySQL database `foodywesria` | Treat it as existing user data. Fresh development and disposable test databases must use separate identities. |
| Test entry point | Root PHPUnit suite scans `modules/*/tests`, substitutes fixture plugins and disables CSRF | A root framework pass does not prove Vmod behavior or browser CSRF protection. |
| Arithmetic dependency | `brick/math` 0.14.8 is installed and declares PHP `^8.2` | Use decimal arithmetic; declare the dependency directly if application code relies on it. |

An attempted read-only `composer prohibits php 8.2.28 --locked --no-plugins --no-scripts` could not reach Packagist. The PHP incompatibilities were independently confirmed in local package metadata. No compatible replacement lockfile has been solved or tested yet.

## 3. Capability and dependency disposition

Source paths in this table are relative to the pinned source root. They are evidence references, not instructions to copy files. Destinations are proposed target namespaces.

| Source files / symbols | Behavior and dependencies | Disposition and destination |
| --- | --- | --- |
| `plugins/gaiafoodtech/core/classes/Workspace.php`; `models/User.php`, `Role.php`, `Workspace.php`; `classes/DocumentWorkspaceAccess.php` | Company/branch membership, permissions, workspace switching and context tokens. Legacy auth and merged permission state are intertwined. | **Rewrite → Vmod.Core.** Preserve ownership checks and stale-form rejection; simplify grants and membership structure. |
| `plugins/gaiafoodtech/core/Plugin.php::register/boot`; `classes/AuthManager.php` | Replaces the old auth singleton, registers Gaia aliases/commands, extends frontend and backend-related models; requires User, Location and Translate. | **Replace → native auth provider configuration plus minimal Vmod wiring.** No inherited company-manager behavior graph. |
| `plugins/gaiafoodtech/client/Plugin.php`, `models/Client.php`; `plugins/gaiafoodtech/supplier/Plugin.php`, `models/Supplier.php`, `SupplierItem.php` | Companies/branches are dynamically extended with client/supplier and catalogue relationships. | **Rewrite → Vmod.Erp parties and supplier offers.** Tenant-owned commercial counterparties; external suppliers need not be application users. |
| `plugins/gaiafoodtech/inventory/models/Product.php`, `ProductPackage.php`, `ProductVendor.php` | Products, recursive package conversion, prices and regenerating vendor/client data. Product flags include print-specific behavior. | **Rewrite → Vmod.Erp catalogue.** Direct base-unit conversion, explicit offers and historical snapshots; omit print flags and catalogue propagation. |
| `plugins/gaiafoodtech/order/classes/Order.php::confirmDirectGoodsReceiveNote/voidAndReplaceDirectGoodsReceiveNote`; `models/PurchaseOrderItem.php` | Scoped receipt confirmation, locks, supplier delivery identity, immutable supplier snapshots and correction history; coupled to several order types. | **Rewrite → Vmod.Erp purchasing/receiving.** Preserve quantity, authorization, retry and correction invariants with a single generic PO flow. |
| `plugins/gaiafoodtech/order/classes/Sales.php::createDeliveryOrder/addDeliveryOrderItemsUnlocked` | Calls PrintCore/FoodCourt guards, links PO/SO and logistics; deducts inventory during DO creation. | **Rewrite → Vmod.Erp sales/dispatch.** Use the confirmed reservation/dispatch lifecycle from the plan. |
| `plugins/gaiafoodtech/inventory/classes/Inventory.php` | Lots, storage, conversion, movement processing/reversal and allocation strategies. | **Rewrite → Vmod.Erp basic stock.** Retain transactional movements and reconciliation; defer lots, expiry, valuation and warehouse strategies. |
| `plugins/gaiafoodtech/inventory/classes/InventoryHoldService.php::reserve/consume/release` | Reserves availability separately from physical stock; consumes whole holds, groups reservation input by product and accepts generic model owners/actors. | **Rewrite → Vmod.Erp line-addressable reservations.** Partial DOs must consume an exact SO line's reservation, not every item in a hold. |
| `plugins/vector/utilities/classes/FormRequest.php` | Laravel validation adapted to October validation exceptions. | **Replace → native Validator/request handling.** Use a small shared helper only if a real integration gap remains. |
| `plugins/vmod/core/traits/HasAuditTracking.php` | Chooses audit actors through BackendAuth. | **Rewrite → explicit frontend actor tracking.** The existing Vmod.Core is a helper plugin, not the required auth/workspace foundation. |
| `plugins/gaiafoodtech/order/helpers/tax_helper.php`; `classes/Sales.php::collectTotal` | Inclusive/exclusive tax arithmetic uses floats; totals include discounts, charges and payment balances. | **Rewrite → one decimal totals calculation.** Use the confirmed single-tax-per-line contract; omit accounting balances and advanced charges. |
| `plugins/gaiafoodtech/order/components/DocumentSalesOrder.php`; corresponding document components and theme partials | Document-specific ownership checks and Renatio/DynamicPDF output. | **Rewrite → Vmod document views.** Keep protected preview/ordinary printing; MVP uses browser print/save-as-PDF. |
| `plugins/vmod/foodcourt/Plugin.php`, `classes/Access.php`, `BatchService.php`, `LinkedOrders.php`, `Fulfilment.php`, `Statement.php` | Court/stall ordering and native ERP orchestration; depends on Gaia Core/Client/Inventory/Order/Fulfillment/Logistic, RainLab.User, Vmod.Core and UBS. | **Defer application; reuse behavior/test lessons only.** FoodCourt must later depend on the generic ERP, not vice versa. |
| `plugins/vmod/ubs`, PrintCore/printproduction/bridges, Gaia emailparser, commerce, logistics and associated providers/jobs | Specialized accounting transport, print production, inbox ingestion, marketplaces and fleet behavior. | **Exclude from MVP runtime, schema, dependency graph and fixtures.** |

### Concrete extraction hazards

- `GaiaFoodTech.Order` explicitly requires EmailParser. `order/updates/update_sale_orders_add_extract_id.php` adds an FK to `gaiafoodtech_emailparser_extracts`. `vmod/foodcourt/tests/OrderingTest.php:38` creates that otherwise unused table to satisfy migrations. Reusing migration/test bootstraps would retain the unwanted subsystem.
- `Sales.php` contains runtime `class_exists` branches into PrintCore and FoodCourt. Removing plugin declarations alone would not remove the coupling.
- Inventory/client/supplier plugins dynamically extend CompanyManager and company/branch models. Inventory also registers an order-document morph mapping. Recreate concrete relationships without these cross-plugin boot dependencies.
- Source inventory holds have no partial-consume quantity parameter. Their input merges identical products and their items do not preserve SO-line identity. Source `consume()` validates a generic document owner, not a binding to the exact owning SO. Copying it would not satisfy the confirmed partial-delivery contract.
- The source Vmod audit trait can select a backend actor whenever an admin session exists. Frontend ERP actions must select only the authenticated frontend user, even when both sessions exist.
- Source normalization filters input arrays before validating their shape. Validate scalars/arrays and row structure before indexing/filtering; malformed payloads must produce field errors, not exceptions or silently missing lines.
- Names are structural: namespaces, plugin codes, tables/FKs, stored morph classes, permissions, aliases, config keys, commands, document-template codes and theme URLs. A text replacement is not a migration design.

## 4. Standards and compatibility decisions

The root AGENTS file still describes October 3.6/Laravel 9, source-repository graph identity and FoodCourt-first scope. For this initiative the user's generic ERP scope and verified target framework take precedence. Reconcile those descriptions during the foundation phase; preserve useful permission and tenant rules.

The frontend standard says “Prefer `Vector\Utilities\Classes\FormRequest`” and names a Gaia frontend User model. Keep the validation/error behavior but target native validation and Vmod identity. The plugin standard says `created_by_id` is a “nullable FK to `backend_users`”; this is an admin-only contract, not permission to put frontend actors into that field. Its catch-and-return-null examples must not turn failed transactions into apparent success. The notification standard requires Gaia notification/FCM infrastructure; that subsystem is outside MVP scope. Authentication/recovery mail remains in scope.

`docs/create-document-standard.md` exists in the pinned source but not the target. Its useful requirements are server-side authorization, transactional creation, clear void behavior and printable documents. Its Gaia, Vector, Renatio and UI helper specifics are reference material rather than mandatory dependencies for the new project.

RainLab.User v3 uses Laravel authentication and a configurable user provider model. Its upgrade guide maps `getUser()` to `user()` and `name/surname` to `first_name/last_name`. Configure `Vmod\Core\Models\User` and preserve the requested application spelling `Auth::getUser()` through a minimal Vmod facade delegating to the native current-user operation. Phase 1 must verify a compatible release and dependency solution; Phase 2 must test a fresh login/request returning the Vmod user type.

Official references checked during the evaluation:

- [October 4 system requirements](https://docs.octobercms.com/4.x/setup/installation): “PHP version 8.2.0 or higher”. This does not certify the target's current lockfile.
- [RainLab.User upgrade guide](https://github.com/rainlab/user-plugin/blob/master/UPGRADE.md) and [auth configuration](https://github.com/rainlab/user-plugin/blob/master/config/auth.php).
- [October AJAX validation](https://docs.octobercms.com/4.x/ajax/features/validation.html) and [exceptions](https://docs.octobercms.com/4.x/extend/system/exceptions.html).

Use version-specific official documentation again when implementing uncertain APIs; no source-major-version assumption is approval to change framework versions.

## 5. Test evidence worth translating

Translate assertions, not entire legacy test classes or their dependency-heavy fixtures:

- Core `tests/units/WorkspaceContextTest.php`, `TenantGuardTest.php`, `LoginTest.php`: membership, switching, stale context, permission revocation and actor identity.
- Order `tests/units/SupplierCatalogPurchaseOrderTest.php`: scoped supplier/product selection, immutable commercial identity, invalid payloads and all-or-nothing writes.
- Inventory `tests/unit/StockManagerGuardTest.php`: permission and ownership checks at mutation boundaries.
- Inventory `tests/unit/InventoryHoldServiceTest.php`: physical versus available balances, insufficient-stock denial, reserve/release/consume retries. Add new partial-consumption and exact-SO-line tests.
- FoodCourt `tests/OrderingTest.php`: safe cross-tenant errors, duplicate submissions, workflow state and receiving correction history. `AmountsTest.php` supplies examples, not the target decimal implementation.

The pinned `docs/evidence/foodcourt-staging-e2e-2026-10-08/completion.md` reports 16 focused retests plus 23 retained original passes. It records illustrative staging flows, not fresh tests of this rewrite. Source FoodCourt receiving is not automatically a stall stock ledger; its court binds one vendor/merchant pair and its statement reconciles supplier invoices rather than implementing rental/GTO reimbursement.

## 6. MVP and later-product boundary

The MVP consists of the two custom plugins and one theme defined in the plan: workspace/users/access, parties/catalogue, PO/GRN, SO reservations/DO dispatch, stock/audit, external invoice references and ordinary printing. Fresh schema and synthetic data only. There is no source-data migration or source table-rename bridge.

Later additions, each requiring its own requirements and acceptance:

1. Supplier/customer portals, advanced pricing, document automation, richer reservation allocation and replenishment.
2. FoodCourt courts/stalls, genuinely multi-supplier procurement and appropriate consolidation on top of the generic ERP.
3. Rental/GTO, deductions, reimbursement and replaceable POS/accounting integrations.

Invoice issuance, payments/credit accounting, GL, valuation, lots/expiry, manufacturing, fleet, marketplaces, AI, print-request production and inbox parsing remain deferred or excluded. Future integrations should use concrete adapters when needed; the MVP does not need a speculative integration framework.
