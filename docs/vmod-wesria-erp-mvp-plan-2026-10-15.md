# Vmod Wesria ERP MVP implementation plan

> For agentic workers: use the writing-plans execution workflow appropriate to the authorized phase. Execute sequentially; independent read-only reviews may be delegated. Never run parallel write-heavy phase implementations. Complete the phase's checks and record evidence before marking it complete.

**Date:** 2026-10-15 (Asia/Kuala_Lumpur).
**Goal:** Build a reusable generic ERP foundation with frontend workspaces, purchasing, sales, reserved stock, fulfilment and operational document history.
**Architecture:** `Vmod.Core` owns frontend identity, companies/branches, access and audit context. `Vmod.Erp` owns parties, catalogue, document workflows, reservations and stock. A new `themes/vmod` uses October components/AJAX and adapts the supplied Tabler 1.5.1 assets (Bootstrap 5.3.8). ERP depends on Core; Core has no ERP or FoodCourt dependency.
**Tech stack:** PHP 8.2, October CMS 4.3, Laravel 12, MySQL, existing PHPUnit and decimal arithmetic through compatible `brick/math`.
**Spec/evaluation:** [Source evaluation](vmod-wesria-erp-evaluation-2026-10-15.md). This plan is authoritative for implementation behavior; the evaluation records dated evidence and rationale.

## 1. Fixed scope and execution boundaries

- Target: `/Users/stev/htdoc/vmod_wesria`.
- Read-only source: `/Users/stev/htdoc/netsemailparsing` at **`1d5f54ff55f8c1de225da53b00052f7e38f21752`**, complete tree. Recheck any reference worktree before reading it.
- PHP 8.2 with the target's October 4.3/Laravel 12 generation; no unrequested framework upgrade/downgrade.
- Fresh development/test databases and synthetic fixtures. Preserve the existing `foodywesria` database and all source data.
- Write new application behavior under `Vmod`; use `vmod_core_*` and `vmod_erp_*` for custom tables. Framework/auth tables such as `users` keep their native names. No copied Gaia/Vector tree, namespace-only conversion, legacy migration history or parser/print fixtures.
- MVP includes company/branch workspaces, account administration, roles/permissions, customers/suppliers, catalogue/package/UOM/prices, PO/GRN, SO reservations/DO dispatch, basic stock, audit, invoice references and ordinary printing.
- Defer FoodCourt-specific flows, rental/GTO/settlement, invoice issuance, payments/credits accounting, GL, valuation, lot/expiry strategies, manufacturing, fleet, UBS, marketplaces, AI, print-request production and inbox parsing. Authentication/recovery email remains in scope.
- Preserve unrelated dirty files. Current documentation creation does not authorize application work. A future implementation prompt authorizes only its selected phase. No automatic commit, merge, push or deployment.
- For an existing symbol, use the correct repository's current graph for impact analysis before editing. If unavailable, report the limitation and trace implementation/callers/tests directly; never silently trust the source repository's graph as the target's graph. Report any high-risk contract or runtime blast radius first.

### Progress and resumption

| Phase | Deliverable | Status |
| --- | --- | --- |
| 1 | PHP 8.2 runtime, reproducible dependencies and safe test bootstrap | Complete — fresh runtime, MySQL and egolite acceptance; see evidence |
| 2 | Frontend identity, workspace and permission foundation | Complete — isolated MySQL and egolite acceptance; see Phase 2 evidence |
| 3 | ERP master data, decimal totals and tax snapshots | Not started |
| 4 | Purchasing, receiving and inbound stock | Not started |
| 5 | Sales approval reservations and partial dispatch | Not started |
| 6 | Complete ERP UI, references, printing and acceptance | Not started |

At phase completion, change only that row and append an evidence entry under section 8. A row is complete only after all of its mandatory gates pass. If interrupted, record the completed work and remaining check; the next run rechecks files and resumes instead of recreating them. A blocked check is not a pass. The recommended prompt runs one phase per invocation.

## 2. Shared contracts

### Identity, ownership and permission

- `Vmod\Core\Models\User` is the frontend user on `users.id`. Configure the native auth provider to use it. The application-facing `Vmod\Core\Facades\Auth::getUser()` delegates to the native current-user operation; use explicit imports in Vmod code, without replacing backend authentication.
- `Workspace::hasAccess(string $permission): bool` is the UI/read check. Every service action also enforces the required permission, current membership, allowed branch, record ownership and workflow state. Authorization happens before returning an idempotent replay result as well as before a new write.
- One role per company membership; roles contain explicit granted permission keys and default to denial. Branch access is explicit. Company-level views aggregate only branches the actor is authorized to access. Per-user permission overrides, implicit cross-company inheritance and frontend superuser bypasses are omitted.
- All transactions have a company and a concrete branch. Locations belong to that branch. Parties/products/rates are company-owned; a foreign key submitted by the browser never grants access. A company's supplier/customer is a counterparty record, not automatic authority over another tenant account.
- Retain `X-Workspace-Context` protection. Server-owned context rotates on switch; missing/stale mutation context returns 409 without writes. Revalidate membership and permissions each request. CSRF protection remains enabled for real frontend requests.
- Tenant administrators manage their company's memberships and roles. They may invite a user but cannot enumerate global accounts or reset another tenant's user's credentials. Existing-user invitations require acceptance by the intended authenticated account; email matching alone does not grant membership. Prevent removing/demoting the last active company administrator.
- Phase 2 confirmed authority: a manager may grant only permissions and branches they hold, and may edit existing grants only when those grants also fit their authority. Editing a shared role checks every affected membership and pending invitation. Keep at least one active administrator holding every registered permission and every company branch. Only these full-access administrators create branches; creation atomically grants the new branch to every existing full-access administrator. Disable/restore memberships directly, retaining history and leaving the global account and other companies unchanged.
- Core administration serializes on one company-row lock, with fresh authorization inside the transaction. This deliberate administration-only bottleneck may be split only if measured contention warrants it. Native CMS session blocking serializes requests from the same session; form context is captured at render time and rotates on workspace switch. Auth, invitation acceptance and self-account actions require their own identity/token checks and CSRF, without requiring a workspace.
- Invitations have one pending record per company/email, an opaque hashed token and a configurable seven-day expiry. Resend replaces the token; explicit revocation invalidates it. Issued invitations survive sender access removal. Acceptance checks current company/role/branches and invitation validity; roles remain live configuration, with no invitation permission overrides. GET creates no user or membership. New users submit name/password/confirmation once; native user creation, grants and consumption commit atomically, then native sign-in runs. If the email already exists or becomes registered, the intended account must sign in and explicitly accept; its credentials are never overwritten. Mail failure preserves the pending invitation and reports failure.
- RainLab.User 3.7.2 supplies password validation/hashing, login throttling, sessions and recovery. Core fixes redirects and gives generic recovery responses. Successful recovery invalidates native persist codes for all frontend sessions and requires a fresh login. Public registration is disabled; native account deletion and unrelated handlers are not exposed. Personal settings allow name/password changes; email changes and global account administration remain outside Phase 2.
- Bootstrap each new company through an operator-only `php artisan vmod:bootstrap` command, restricted to the explicitly owned local/test environment for this MVP. In one transaction create the company, initial branch, owner role and a new frontend owner account using native password handling. Prompt for secrets without echo; never provide fixed credentials or accept them in command history. Refuse existing company identifiers or account emails rather than overwriting/reassigning them. Additional memberships use the invitation flow. There is no public bootstrap route.
- Frontend audit uses explicit `actor_user_id`/action-specific frontend actor fields referencing `users.id`, source and timestamp. Never accept an audit actor from the browser. Backend-only actor fields stay null for frontend operations, including when an admin session also exists.
- Use scoped list queries and scoped parent-child lookups for reads, writes, print views and attachments. Unknown/foreign records return a safe not-found response; known forbidden actions return 403; stale state returns 409; field validation uses October's native validation response.

Permission keys are registered once, shared by handlers/services and tested. Use `workspace.manage`, `users.manage`, `roles.manage`, `catalogue.read/write`, `purchaseorder.read/write/approve/close`, `goodsreceipt.read/write/confirm/correct`, `salesorder.read/write/approve/close`, `deliveryorder.read/write/dispatch/correct`, `inventory.read/adjust`, `invoice_reference.read/write`. Reopening/revising an approved order requires its `write` and `approve` permissions. Document printing requires its read permission and ownership. The initial owner is explicitly granted these keys; no wildcard bypass is needed.

### Master data, quantities and snapshots

- Use tenant-owned parties with customer/supplier flags; products with one base UOM; packages with one direct positive conversion to that base; supplier offers; stock locations; configurable named tax rates.
- Support stock products in this first foundation. Non-stock/service-product workflows, nested conversions, lots, expiry, valuation and automatic cross-tenant catalogue synchronization are deferred.
- Quantities, conversion factors and unit prices support up to four decimal places. Reject excess precision or values exceeding storage capacity. Base quantities must be exactly representable at four decimal places; do not silently round stock away. Use decimal strings/BigDecimal, never floats for money or stock decisions.
- Company currency defaults to MYR and is configurable before its first posted transaction. No FX or mixed-currency order is supported. Two-decimal document amounts are the MVP currency contract.
- Draft creation captures party details, product SKU/description, package/UOM/conversion, unit price and tax label/rate/mode. Catalogue edits never refresh a saved document implicitly. An authorized draft edit/reprice creates an audited revision. Approval freezes the active revision; later printed documents use that revision, not current master data.
- Record catalogue price/rate changes with the frontend actor and time. Editing a document price away from its offered/list price requires an explicit reason. No pricing-tier engine, discount engine or compound charges are introduced.

### Confirmed tax rule

The user confirmed: **one optional tax percentage per line, document-level tax-inclusive/exclusive pricing, configurable rates and immutable historical values; defer multiple/compounded taxes and tax accounting.**

- `price_mode` is `exclusive` or `inclusive` for the whole PO/SO; default `exclusive`. A line may have no tax (0%) or one configured rate. Store label and numeric rate snapshots rather than relying only on a mutable rate ID. Use nonnegative percentage rates bounded to 100%, up to four decimal places, as the MVP input constraint.
- Let `money(x)` round a decimal to two places, half-up; `q` is quantity, `p` is the snapshotted unit price and `r` the snapshotted percentage.
- Exclusive: `net = money(q * p)`; `tax = money(net * r / 100)`; `gross = net + tax`.
- Inclusive: `gross = money(q * p)`; `net = money(gross / (1 + r / 100))`; `tax = gross - net`.
- Sum rounded line net/tax/gross amounts for document totals. Inclusive tax is the difference so `net + tax = gross` always holds. A zero rate yields zero tax in either mode.
- Persist the document mode, line snapshots and calculated amounts. Neither tax-master edits nor a later document default change can alter historical values. DO/GRN references inherit the originating order snapshots; they do not reprice from current catalogue data or post tax accounting.
- Changing an approved order's mode/rates follows the explicit order-revision rules below; keep the prior approved revision in history.

Reference examples: `3 × 10.00 @ 6%` exclusive is net 30.00, tax 1.80, gross 31.80; inclusive is net 28.30, tax 1.70, gross 30.00. At `1 × 0.05 @ 10%` exclusive, half-up gives tax 0.01 and gross 0.06. These are calculation examples, not prescribed statutory tax rates.

### Confirmed stock reservation rule

The user confirmed: **reserve stock at SO approval, deduct physical stock on dispatch.**

- Track stock by company, branch, location and product in base UOM. `available = on_hand - reserved`; require `on_hand >= reserved >= 0`.
- Select the dispatch location for each stock line before approval. Approval reserves the complete order atomically or fails with no approval/reservation writes. No overbooking, partial approval or automatic expiry is introduced.
- A reservation identifies the exact SO line and location. Two SO lines for the same product remain distinct. Record approved, dispatched and released base quantities; outstanding reservation is their difference. Balance reservations must equal the sum of outstanding line reservations.
- Approval changes reserved/available only; it produces no physical stock movement. Repeating approval with the same normalized input returns the existing result; different content under the same key is a conflict.
- A draft DO does not add a reservation or deduct stock. Confirmed dispatch consumes only its line quantities from its parent SO's reservations and decreases on-hand by the same amounts, in one transaction. It cannot consume another order's stock. Partial dispatch leaves the remainder reserved.
- Voiding an unfulfilled SO, or short-closing its unfulfilled remainder, releases only outstanding reservations once. Void its draft DOs in the same transaction. Already dispatched goods stay dispatched.
- Before any dispatch, an explicit reasoned reopen-to-draft releases reservations and voids draft DOs atomically; edit then reapprove under a new revision with fresh availability checks. After partial dispatch, preserve approved line/price/location snapshots: close the remainder and create a new SO for changed/additional demand rather than silently editing the partially fulfilled order.
- No lot allocation engine is needed. One selected location per SO line is sufficient; multiple lines can represent explicit splits. Reallocation automation remains deferred.

Worked balance: start on-hand 10/reserved 0; approve 6 → 10/6/available 4; dispatch 2 → 8/4/available 4; close remaining 4 → 8/0/available 8.

### Posting, corrections and concurrency

- Use separate PO, SO, GRN and DO models/items rather than a generic workflow engine. PO and SO may work independently; a new PO must not manufacture a supplier-side SO in another tenant.
- PO: draft → approved → partially received → completed. GRN: draft → confirmed; confirmation posts stock. SO: draft → approved → partially delivered → completed. DO: draft → dispatched. Explicit `voided` and `closed_short` terminal states retain history rather than deleting it.
- PO `purchaseorder.close` permits a reasoned void before any receipt or close-short of an approved, partially received order. Preserve received quantities, record the unreceived remainder as closed, and void draft GRNs atomically; reject later confirmation against that order. Before any confirmed receipt, reopening an approved PO to draft requires `purchaseorder.write` plus `purchaseorder.approve`, a reason and voiding draft GRNs; reapproval creates a new revision. After receiving begins, close the remainder and create a new PO for changed demand. Reject GRN corrections against a manually voided/short-closed PO; corrections on open or automatically completed POs may recompute progress, including reopening an automatically completed PO to partial.
- Reject over-receipt/over-dispatch using cumulative confirmed, non-voided quantities against the approved line. Drafts may be changed without stock effects; recheck everything at confirmation.
- One transaction owns the parent state, child document, movements/reservations and audit. Lock parent/lines and affected stock rows in deterministic order. Use a unique posting identity per document line/action and operation-specific idempotency keys with a normalized payload fingerprint. Do not rely solely on disabled buttons or duplicate HTTP detection.
- Opening balances, receipts, dispatches and adjustments create immutable stock movements. Updates to the cached balance and ledger must commit together. Unique balance keys handle concurrent first receipts; an empty stock row is not a reason to skip locking/uniqueness protection.
- Negative adjustments and receipt reversals may use only available stock: they must preserve all outstanding SO reservations, not merely leave physical stock nonnegative.
- Correct posted GRNs/DOs through a permissioned, reasoned reversal-and-replacement transaction. Retain original rows and link their reversals/replacements. Reversal/replacement retries are idempotent. Recompute effective parent progress from non-voided documents. A correction that would violate availability or approved quantities fails entirely.
- For a DO correction on an open or automatically completed SO, undo its reservation consumption, then consume the replacement quantities atomically; any newly unfulfilled approved quantity remains reserved. An automatically completed order becomes partial when appropriate. Reject correction of a manually voided/short-closed order; do not silently reopen it. No physical stock may be restored merely because an invoice/order was cancelled: the explicit correction must record the actual stock correction and reason.
- Manual adjustments require a reason, actor and optional original-document reference; they do not silently change PO/SO fulfilment quantities. Full commercial returns/credit workflows are deferred.

## 3. Target structure and implementation approach

| Area | Planned location | Responsibility |
| --- | --- | --- |
| Core plugin | `plugins/vmod/core` | User model/auth facade, Workspace, company/branch/membership/role, frontend audit context and migrations |
| ERP plugin | `plugins/vmod/erp` | Parties, products/packages/offers/rates, locations, PO/SO/GRN/DO, stock/reservations, invoice references, services/components and migrations |
| Frontend | `themes/vmod` | Native operations layout, auth/workspace pages, searchable lists, document forms/detail/print views |
| Verification | Each plugin's `tests/` and `phpunit.xml`; ERP MySQL check | Small behavior-focused regression checks using installed PHPUnit/October test base; no new test framework |

Keep validation at the request boundary and domain invariants in the owning service. Prefer native `Validator` with October validation exceptions and allowlisted payloads. Validate array/row shape before normalization. Use the existing framework for authentication, sessions, CSRF, pagination and transactions. Only introduce a helper where multiple real call sites need it. Service errors must propagate so transactions roll back; safe user errors must not reveal paths, SQL or secrets.

Every phase includes its usable frontend slice; Phase 6 integrates and polishes it rather than exposing an untested backend-only system at the end. Read the local frontend/plugin standards when applicable, applying the documented Vmod/identity/version corrections. In Phase 6, write the missing target document standard from the agreed behavior before building printable views.

### Frontend and browser acceptance

- Adapt `/Users/stev/htdoc/resources/tabler/tabler-1.5.1` into `themes/vmod` whenever suitable: layout/navigation, auth, forms, tables, alerts, modal/offcanvas and responsive controls. Actual theme work starts in Phase 2.
- Copy only needed distributable assets and retain license notices; record source checksums when copied. Keep the resource directory unchanged. Exclude demo content/customizers/social login/public registration and unused vendor libraries.
- Tabler 1.5.1 bundles Bootstrap 5.3.8 in an ES module. Load one theme entry module and expose its exported Bootstrap API for October callbacks; do not load a second Bootstrap bundle. Keep October AJAX, validation, CSRF, permissions and workspace-context protection.
- Every phase runs egolite through `ego-browser nodejs` against the verified served application. Use the small runnable script in `tests/e2e/phase1.mjs` as the foundation, extending coverage with each phase's usable slice. No additional browser framework.
- Phase 1 proves frontend/backend-login rendering, assets, navigation/reload, desktop/mobile layout and runtime-error checks. Later phases add successful save/reload journeys, validation preservation, keyboard/focus, forbidden/stale-context requests and real-request CSRF checks. Record scenario counts, viewport, URL/runtime identity and screenshots; unavailable or failed browser checks leave the applicable phase incomplete.
- Browser proof supplements isolated MySQL transaction/concurrency checks; neither substitutes for the other.

## 4. Phase tasks and acceptance gates

Use the smallest regression check that fails when the phase's non-trivial behavior breaks. Implement → focused checks → relevant integration checks; broaden only for an unresolved risk or changed shared dependency. Existing source tests are examples, not target passes.

### Phase 1 — Runtime and reproducible foundation

**Changes:** target `composer.json`, `composer.lock`, `.gitignore`, test bootstrap/configuration and relevant runtime/standards documentation. The approved Phase 1 repair scope also includes the demo codeblocks entry/bundle and native report/Tailor test fixture corrections needed for acceptance; no framework production API or schema changes. External runtime changes are limited to `/Users/stev/htdoc/docker_foody_wesria` after reporting the exact diff/blast radius and obtaining any required filesystem approval. Only dedicated Wesria services may be rebuilt/restarted; shared networks/databases/other applications are preserved.

- [x] Recheck Git state, source SHA, actual container mounts, Compose authority and existing database configuration without exposing credentials.
- [x] Pin the runtime to PHP 8.2 and set Composer's platform to the verified 8.2 patch. Inspect a targeted dependency resolution for PHP 8.2 plus a compatible RainLab.User v3 release; retain October 4.3/Laravel 12 and compatible PHPUnit. Track the application lockfile. Do not use platform-requirement bypasses.
- [x] Provision a new, explicitly owned development schema and isolated disposable MySQL test service/schema; record their identities and connection method without secrets. Keep the existing `foodywesria` schema untouched. Synthetic seeding must be explicitly guarded to local/test ownership.
- [x] Establish plugin test bootstrap/configuration with explicit test database selection and a guard against ordinary development/production databases. Keep a separate framework suite. Configure `composer test` to include the Vmod suite when its tests exist.
- [x] Reconcile stale version/scope/runtime/identity statements in project standards. Preserve unrelated instructions and documents. Resolve RainLab installation/licensing/network prerequisites rather than assuming source vendors can be copied.
- [x] Verify PHP 8.2, `composer check-platform-reqs`, repeat lockfile installation, clean boot, application test bootstrap, framework tests and Phase 1 egolite checks. Record the exact runtime, resolved packages, database guards and browser evidence.

**Gate:** a repeatable PHP 8.2 boot/test baseline with a tracked compatible lockfile, no live-data changes and no excluded application plugin installed. If package resolution or access is blocked, Phase 1 stays incomplete.

### Phase 2 — Core identity and workspaces

**Files:** Core plugin/model/facade/Workspace/service/migration files; auth/workspace pages under `themes/vmod`; `plugins/vmod/core/tests/WorkspaceAccessTest.php` and plugin test configuration.
**Consumes:** Phase 1 native frontend auth and test runtime.
**Produces:** configured Vmod frontend user, `Auth::getUser()`, `Workspace::hasAccess()`, server-owned company/branch context and actor/audit context described in section 2.

- [x] Add focused checks for guarded company/owner bootstrap and duplicate rejection; login/logout/recovery; Vmod user hydration; role/branch grants; cross-tenant denial; missing/stale context; revocation; invitation acceptance and last-admin protection.
- [x] Build company/branch/membership/role, the operator-only bootstrap command and workspace selection with explicit tenant scoping. Derive audit actors server-side and keep backend actors separate. Record bootstrap as a CLI-origin system event linked to the created owner; do not invent a logged-in frontend actor.
- [x] Add account/membership administration and usable auth/workspace screens with native validation, CSRF and safe redirects. Disable public self-registration for this internal foundation; membership invitations control onboarding.
- [x] Run the Core tests plus browser checks of login, switching and a direct forbidden/stale AJAX request. Use a test mail sink for recovery/invitations; local acceptance does not assert external inbox delivery.

**Gate:** two synthetic companies with restricted branches cannot read/write one another's records, stale forms cannot mutate the new workspace, and an admin session cannot change the frontend actor.

### Phase 3 — Master data and commercial calculation

**Files:** ERP party/product/package/offer/tax/location models, migrations and components; `classes/DocumentTotals.php`; `tests/MasterDataTest.php`, `tests/DocumentTotalsTest.php` and ERP test configuration.
**Consumes:** Core identity, permissions and context.
**Produces:** scoped catalogue/locations and `DocumentTotals::calculate(array $lines, string $priceMode): array`, a pure decimal calculation returning line/document net, tax and gross as decimal strings. Input lines contain validated quantity, unit price and tax-rate snapshots; the result contains no authentication or persistence side effects.

- [ ] Add checks for tenant-owned relationships, inactive foreign offers, malformed arrays, duplicate identifiers within a tenant, exact conversion and amount boundaries.
- [ ] Implement the minimal master data and its searchable CRUD screens. Restrict master-data changes to authorized company context.
- [ ] Implement the section 2 tax formulas and snapshot payloads. Test both 3 × 10 @ 6% examples, the half-cent example, zero tax, mixed zero/nonzero line rates, different rates on separate lines, sum-of-rounded-lines and no float drift.
- [ ] Verify that saved snapshots survive price, package and tax-master edits; an explicit draft reprice is a new audited revision.

**Gate:** the catalogue and calculations work independently of any order/parser/print integration; inclusive/exclusive rounding and snapshot integrity pass.

### Phase 4 — PO, GRN and inbound stock

**Files:** ERP purchase/receipt/document-line and stock-balance/movement models/migrations; purchasing/receiving/stock services; frontend purchasing screens; `tests/PurchasingStockTest.php`.
**Consumes:** scoped parties/products/locations, immutable commercial snapshots and totals.
**Produces:** authorized PO creation/approval, partial GRN confirmation, append-only inbound stock, adjustments and correction history. Reusable stock posting accepts only authorized, scoped domain records; browser-supplied actor/tenant/stock totals never become authority.

- [ ] Add checks for PO approval/frozen snapshots, pre-receipt revision, partial/full receiving, over-receipt, terminal void/close-short rejection, duplicate supplier delivery references within company/supplier, missing permission and foreign product/location IDs.
- [ ] Implement transactionally numbered documents, GRN posting, opening balances, adjustments and close-short behavior. A GRN requires a supplier delivery reference; uploads are optional evidence and, if supported, private and scoped.
- [ ] Implement reasoned GRN reversal/replacement. Posted history remains; unsafe reversal fails without partial changes. Check ordinary on-hand integrity now; add outstanding-reservation protection when Phase 5 introduces reservations.
- [ ] Run the ERP focused suite and isolated MySQL checks for first-balance creation, duplicate confirmation and rollback. Verify purchasing/receiving screens preserve errors and submitted input.

**Gate:** a partial receipt updates only the intended stock once, a full receipt completes the PO, and corrections/duplicates preserve a reconciled movement ledger.

### Phase 5 — SO reservation and partial DO dispatch

**Files:** ERP sales/delivery/document-line and line-reservation models/migrations; sales/dispatch/reservation services; sales/delivery screens; `tests/SalesReservationTest.php`; `tests/mysql_stock_check.php`.
**Consumes:** Core authorization, commercial snapshots and Phase 4 stock/balance posting.
**Produces:** the confirmed SO-approval reservation and DO-dispatch contract in section 2. Bind every reservation to a concrete authorized SO line/location; reject generic/unrelated owners and actor impersonation.

- [ ] Add the 10-on-hand / reserve-6 / dispatch-2 / release-4 example as a runnable regression, asserting balances and movement counts at every step.
- [ ] Implement all-or-nothing approval, explicit pre-dispatch reopen/reapproval, partial DO dispatch, short-close/release, cancellation and guarded correction/replacement.
- [ ] Test two distinct lines containing the same product, multiple DOs against one line, wrong-order reservation consumption, mismatched retry payloads, repeated approval/dispatch/release and stale document revision.
- [ ] Extend Phase 4 negative-adjustment/GRN-correction checks: reserving 6 from on-hand 10 must reject a reduction of 5 and permit a reduction of 4. Existing reservations cannot be spent by adjustments or other orders.
- [ ] Run real MySQL concurrency checks using independent connections/processes synchronized by a barrier: competing approvals for scarce stock; concurrent partial dispatches exceeding the same reservation; replayed posting; cancellation versus dispatch. Assert invariants and unique physical movements, not timing alone.
- [ ] Verify the UI separately labels on-hand, reserved and available, shows the reserved location, and reports failed approval/dispatch without misleading success.

**Gate:** no overselling under concurrency; approval leaves physical stock unchanged; dispatch consumes only its exact reservation once; partials/corrections/releases preserve quantities and history. SQLite-only results cannot complete this phase.

### Phase 6 — Operational UI, references, printing and acceptance

**Files:** ERP invoice-reference model/service/components, protected document views, completed `themes/vmod` navigation/forms, target document standard, `tests/DocumentAccessTest.php` and `tests/FoundationInstallTest.php`.
**Consumes:** all prior phases and their verified domain operations.
**Produces:** usable generic ERP journeys and a fresh-install acceptance record.

- [ ] Add scoped external invoice references (number/date/amount/currency and operational-document links), duplicate detection and audited edits. No invoice issuance, payment balance or accounting entry is created.
- [ ] Complete searchable paginated lists, details, forms and permission-based navigation. Reuse native controls/AJAX; add no new UI library solely to reproduce the source theme.
- [ ] Write the target document standard from the confirmed scope, then build authenticated PO/SO/DO/GRN HTML previews with browser print/save-as-PDF. Mark drafts/voids clearly; use historical snapshots. Server PDF generation/bulk archives remain deferred.
- [ ] Verify mobile and keyboard operation, focus return, escaped input, validation preservation, safe errors, forbidden direct AJAX/print/file requests and real-request CSRF behavior. Test uploads only if that optional evidence surface was implemented.
- [ ] Verify fresh installation using October/Laravel, the explicitly approved RainLab.User release and compatible dependencies locked in Phase 1, plus Vmod.Core/Vmod.Erp. Exercise the guarded company/owner bootstrap. Run schema/runtime scans for legacy table prefixes, class strings, aliases, permissions, commands, routes, template codes and excluded plugins. Historical citations and third-party license notices are allowed; generic words such as SVG vector are not brand failures.
- [ ] Run the complete Vmod suite, required MySQL check and focused browser journey: company setup → catalogue → PO → partial/full GRN → SO approval/reservation → partial/full DO → invoice reference → printed history. Check stock and tax snapshots after reload.

**Gate:** all listed MVP flows pass on the proven PHP 8.2 target with fresh synthetic data, no excluded runtime dependency and no unexplained balance/snapshot differences. Report local qualification separately from deployment or external-provider acceptance.

## 5. Runnable verification contract

Commands below are for implementation time, after the named files exist. Execute inside the verified PHP 8.2 application/test container at `/var/www/html`, with disposable test credentials supplied through the controlled environment, never printed in commands/logs.

```sh
php -v
composer check-platform-reqs
php vendor/bin/phpunit -c plugins/vmod/core/phpunit.xml --do-not-cache-result
php vendor/bin/phpunit -c plugins/vmod/erp/phpunit.xml --do-not-cache-result
php plugins/vmod/erp/tests/mysql_stock_check.php
composer test
```

Phase 1 records the exact container invocation and owned test database identity. Later runs reuse that invocation after rechecking PHP version and mount provenance. Before Phase 5, run only existing applicable checks; do not call a missing check a pass.

The MySQL check must require an explicitly owned disposable database, reject ordinary application database names and fail rather than skip when MySQL/two-worker execution is unavailable. The check may mutate only its synthetic test data. Plugin test configurations must select application plugins rather than the root framework's fixture-plugin path. Keep production/frontend CSRF enabled; test helpers do not prove real-request protection.

## 6. Review focus

1. Another browser tab switches workspace or an administrator revokes membership between form display and submit: Phase 2 rejects stale/unauthorized writes.
2. A malformed items array, foreign supplier or stale package/rate is submitted: Phase 3/4 validates shape and ownership before normalization and preserves stored snapshots.
3. A second order, adjustment or GRN reversal tries to spend reserved stock: Phase 5 protects `on_hand >= reserved` under real concurrent requests.
4. Approval/dispatch/correction is retried with the same or changed payload: Phase 4/5 verifies exact linkage, replay identity and atomic results.
5. A product/rate/user is edited or deactivated after posting: Phase 3/6 keeps readable historical documents and audit evidence without granting current write access.

## 7. Deferred product roadmap

After the foundation is accepted, plan supplier/customer portals, advanced pricing/replenishment, richer reservation allocation and server document generation as separate increments. FoodCourt then adds courts/stalls and multi-supplier procurement on top of ERP contracts. Rental/GTO, deductions/reimbursement and POS/accounting adapters follow explicit business rules and independent acceptance. None of these later capabilities is implied complete by this MVP.

## 8. Implementation evidence

**2026-10-15 — Phase 1 complete (local qualification).** Fresh PHP 8.2.34/MySQL 8.0.46 verification, unchanged-lock installation/platform checks, preserved development boot and an empty disposable MySQL install (0 → 65 tables, zero users) pass. Eleven unsafe pre-boot targets are rejected; Vmod passes 19 tests/31 assertions. Framework passes 421 of 425 tests on SQLite (2,287 assertions); its four SQLite-only date cases all execute successfully within the MySQL report run (23 tests/178 assertions, no skips). The four formerly skipped Tailor checks now execute. Egolite passes ten desktop/mobile scenarios with ten validated, visually inspected screenshots and zero runtime errors after the minimal demo bundle repair and graceful browser restart. `composer.lock` is tracked/staged; nothing was committed. Three existing SVG sanitizer advisories remain disclosed local limitations. Exact changed files, commands, database identities, screenshot hashes and results are in [Phase 1 evidence](evidence/phase-1-foundation-2026-10-15.md). Next: **Phase 2 — Core identity and workspaces using Tabler**, not started.

**2026-10-15 — Phase 2 complete (local qualification).** `Vmod.Core` and `themes/vmod` deliver native RainLab.User 3.7.2 identity/recovery, company/branch workspaces, explicit delegated grants, guarded bootstrap, memberships, invitations and frontend audit actors. All confirmed decisions are recorded in section 2. Additive Core migrations 1.0.1/1.0.2 are applied only to the owned dev/test schemas. Final container `composer test` passes 11 unsafe-target guards, 33 Vmod tests/108 assertions and five synchronized independent-process MySQL races; 421 framework tests execute successfully (425 total, 2,287 assertions, four unchanged SQLite-only skips). Egolite records 18 passing scenarios, 12 visually inspected screenshots and zero runtime errors; twelve repeated simultaneous switch pairs each return 200/409. Native Laravel CSRF and database session blocking are qualified against real browser requests. Scoped PHPCS and whitespace checks pass. Mail uses only the local log sink. Exact commands, file manifest, review dispositions, checksums and limitations are in [Phase 2 evidence](evidence/phase-2-core-2026-10-15.md). Changes are uncommitted; no push/deployment. **Stopped after Phase 2; Phase 3 remains not started.**

Append one compact entry per executed phase with: date; exact target checkout/runtime; changed files; schema/database scope; commands and pass/fail counts; MySQL/browser proof where required; unresolved limitations; next phase. Include commit IDs only if commits were separately requested. Never mark a skipped, blocked or historical check as freshly passed.
