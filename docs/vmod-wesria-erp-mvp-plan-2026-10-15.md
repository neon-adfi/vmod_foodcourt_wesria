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
| 3 | ERP master data, decimal totals and tax snapshots | Complete — forced-wait MySQL and egolite acceptance; see Phase 3 evidence |
| 4 | Purchasing, receiving and inbound stock | Complete locally — MySQL, egolite and independent review pass |
| 5 | Sales approval reservations and partial dispatch | Complete locally |
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

- Use company-owned parties with customer/supplier flags, products with one base UOM, packages with one direct positive conversion, supplier offers and named tax rates; locations belong to a branch. `catalogue.read/write` governs these records. Location lists/changes require the selected branch, or only granted branches in company view.
- Each package has an independent nullable sales price. Blank remains unpriced; zero is an explicit price. Each supplier/package pair has one explicit current offer price, including inactive offers: edit/restore the existing offer. All catalogue prices are tax-exclusive.
- Package conversion is fixed at creation; use a new package for a different size. Product base UOM locks once any package or stock movement exists, including inactive/unpriced packages and stock subsequently reduced to zero. Names and prices remain editable. Deactivate/restore records without deletion or cascading flag changes; restoring a parent resumes children whose own active flags remain enabled.
- Support stock products in this first foundation. Non-stock/service-product workflows, nested conversions, lots, expiry, valuation and automatic cross-tenant catalogue synchronization are deferred.
- Quantities, conversions and unit prices use `DECIMAL(19,4)`, rates `(7,4)` bounded to 100%, and amounts `(19,2)`. Reject malformed input, floats, excess precision and overflow. Base quantities must be exactly representable at four decimal places; do not silently round stock away. Use decimal strings and installed `brick/math`.
- Company currency defaults to MYR. Only a full administrator may change it, until the first actual package/offer price (including zero) atomically sets permanent `currency_locked_at`. Clearing prices or deactivation never unlocks it; an unpriced package does not lock it. Core owns the settings/fields and never queries ERP tables. No FX or mixed-currency order is supported. Two-decimal document amounts are the MVP currency contract.
- Draft creation captures party details, product SKU/description, package/UOM/conversion, unit price and tax label/rate/mode. Catalogue edits never refresh a saved document implicitly. An authorized draft edit/reprice creates an audited revision. Approval freezes the active revision; later printed documents use that revision, not current master data.
- Record catalogue price/rate changes with the frontend actor and time. Editing a document price away from its offered/list price requires an explicit reason. No pricing-tier engine, discount engine or compound charges are introduced.
- Phase 3 proves independent serializable scalar snapshot payloads, preserving unset prices as unset; calculations require an explicit price. Persisted PO/SO revisions, draft repricing and historical document display are Phase 4–5 responsibilities.
- ERP uses shared company locks for ordinary catalogue changes and exclusive locks for first-price writes, coordinating with Core's administration lock without calling its mutation wrapper. Select lock mode by an advisory read before the transaction; acquire the company lock as its first database read, then reauthorize and compare expected currency. Lock relevant parents and use current locking reads for mutable guards. Package creation submits the displayed `expected_base_uom`; reject a stale unit after acquiring the product lock. Compare/increment record versions under lock; reject stale edits with 409. Preserve CSRF, workspace context and server-derived actors; check context/permission before payload validation. Inactive ancestors restrict new selections/snapshots, not editing existing names/prices.

### Confirmed tax rule

The user confirmed: **one optional tax percentage per line, document-level tax-inclusive/exclusive pricing, configurable rates and immutable historical values; defer multiple/compounded taxes and tax accounting.**

- `price_mode` is `exclusive` or `inclusive` for the whole PO/SO; default `exclusive`. A line may have no tax (0%) or one configured rate. Store label and numeric rate snapshots rather than relying only on a mutable rate ID. Use nonnegative percentage rates bounded to 100%, up to four decimal places, as the MVP input constraint.
- Let `money(x)` round a decimal to two places, half-up; `q` is quantity, `p` is the snapshotted unit price and `r` the snapshotted percentage.
- Exclusive: `net = money(q * p)`; `tax = money(net * r / 100)`; `gross = net + tax`.
- Inclusive: `gross = money(q * p)`; `net = money(gross / (1 + r / 100))`; `tax = gross - net`.
- Sum rounded line net/tax/gross amounts for document totals. Inclusive tax is the difference so `net + tax = gross` always holds. A zero rate yields zero tax in either mode.
- `DocumentTotals::calculate(array $lines, string $priceMode): array` returns ordered line and document net/tax/gross as two-decimal strings. Input prices already use the requested document mode; the calculator performs no implicit catalogue-price conversion.
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
- PO `purchaseorder.close` permits a reasoned void before any receipt or close-short of an approved, partially received order. Preserve received quantities, close the entire unreceived remainder through the terminal header state, and void draft GRNs atomically; reject later confirmation against that order. Before any confirmed receipt, reopening an approved PO to draft requires `purchaseorder.write` plus `purchaseorder.approve`, a retained audit reason and voiding draft GRNs. Reopening creates a draft revision; approval freezes the saved revision the user reviewed. After receiving begins, close the remainder and create a new PO for changed demand. Reject GRN corrections against a manually voided/short-closed PO; corrections on open or automatically completed POs may recompute progress, including reopening an automatically completed PO to partial.
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

- [x] Add checks for tenant-owned relationships, inactive foreign offers, malformed arrays, duplicate identifiers within a tenant, exact conversion and amount boundaries.
- [x] Implement the minimal master data and its searchable CRUD screens. Restrict master-data changes to authorized company context.
- [x] Implement the section 2 tax formulas and snapshot payloads. Test both 3 × 10 @ 6% examples, the half-cent example, zero tax, mixed zero/nonzero line rates, different rates on separate lines, sum-of-rounded-lines and no float drift.
- [x] Verify that serialized scalar snapshots survive subsequent price, package and tax-master edits, preserving unset prices; persisted PO/SO snapshots and audited repricing belong to Phases 4–5.
- [x] Verify unpriced packages lock base UOM but not currency; the first package/offer price permanently locks currency. Cover inactive ancestors, restoration, duplicate inactive offers, permissions, branch/tenant scope, stale versions and audit rollback.
- [x] Force and observe independent-process MySQL lock waits for currency versus first price, base-UOM edits versus first package, and revocation versus catalogue writes. Simultaneous starts alone are insufficient. Run focused ERP tests, then aggregate `composer test`, scoped PHPCS and whitespace checks.
- [x] Run egolite on the verified served checkout: save/reload, search/pagination, blank prices, retirement/restoration, validation preservation/focus, restricted access, stale context, real CSRF, keyboard and mobile. Inspect screenshots, record hashes and runtime errors.

**Gate:** the catalogue and calculations work independently of any order/parser/print integration; inclusive/exclusive rounding, scalar snapshot integrity, forced-wait MySQL and egolite acceptance all pass. Keep ordinary models/migrations, one explicit `CatalogueService`, one native component and `DocumentTotals`; no generic CRUD engine, repositories, dependencies, order scaffolding or snapshot persistence framework.

### Phase 4 — PO, GRN and inbound stock

**Files:** ERP purchase/receipt/document-line and stock-balance/movement models/migrations; purchasing/receiving/stock services; frontend purchasing screens; `tests/PurchasingStockTest.php`.
**Consumes:** scoped parties/products/locations, immutable commercial snapshots and totals.
**Produces:** authorized PO creation/approval, partial GRN confirmation, append-only inbound stock, adjustments and correction history. Reusable stock posting accepts only authorized, scoped domain records; browser-supplied actor/tenant/stock totals never become authority.

- [x] Add checks for PO approval/frozen snapshots, pre-receipt revision, partial/full receiving, over-receipt, terminal void/close-short rejection, duplicate supplier delivery references within company/supplier, missing permission and foreign product/location IDs.
- [x] Implement transactionally numbered documents, GRN posting, opening balances, adjustments and close-short behavior. A GRN requires a supplier delivery reference; attachments remain outside Phase 4.
- [x] Implement reasoned GRN reversal/replacement. Posted history remains; unsafe reversal fails without partial changes. Check ordinary on-hand integrity now; add outstanding-reservation protection when Phase 5 introduces reservations.
- [x] Run the ERP focused suite and isolated MySQL checks for first-balance creation, duplicate confirmation and rollback. Verify purchasing/receiving screens preserve errors and submitted input.

**Gate:** a partial receipt updates only the intended stock once, a full receipt completes the PO, and corrections/duplicates preserve a reconciled movement ledger.

### Phase 4 confirmed implementation decisions (2026-10-15)

- A PO's supplier and branch are fixed after its first save. New lines require an active supplier offer; overrides require a reason. Ordinary edits retain scalar snapshots. Explicit selected-line repricing refreshes offer prices only and creates an audited revision.
- Inclusive mode converts the exclusive offer price. Preserve source price/mode/tax as an exact rational calculation anchor; mode and tax changes preserve its before-tax value, rounding the displayed unit price half-up to four decimals once. Totals are calculated from that displayed price, so four-decimal quantization may affect rounded totals at large quantities. Repeated toggles never replace the anchor. No implicit catalogue refresh occurs.
- Existing saved orders remain approvable/receivable after supplier/product/package/tax retirement. New selections/repricing need active masters; receiving/replacement destinations must be active. A reversal may debit a retired original location.
- Each GRN belongs to one PO. Supplier references are trimmed and compared case-insensitively within company/supplier. Drafts do not reserve references; confirmation claims them permanently for the receipt chain. A correction may change the reference; old/new claims remain with that chain and block another receipt.
- Correct a confirmed receipt by an atomic reversal and optional replacement. An empty replacement is a full reversal. Check net stock change per product/location while retaining full reversal/replacement movements.
- Keep first_received_at after any reversal. Effective zero receipts may return PO progress to approved, but cannot unlock reopening/repricing. Close-short may close the entire remaining quantity after a full reversal; manual terminal closure blocks confirmation/correction.
- Opening stock is allowed once per product/location before its first movement. Later corrections are reasoned increases/decreases. Package-less stock uses base UOM and permanently locks it on first movement. Reject zero quantities, floats, excess precision, overflow and inexact conversions. Forms carry the displayed expected UOM.
- Reuse existing permissions without a catalogue.read prerequisite for purchasing/receiving selectors. Three explicit services own PO, GRN and stock operations. Shared company lock is the first transactional read, followed by fresh authorization. Operation identities, parent documents, sorted master/product locks and sorted balance keys coordinate writes; current locking reads protect mutable guards. Shared product locks coordinate stock with exclusive catalogue UOM edits.
- Action-specific UUID operation receipts and normalized submitted-business-input hashes protect retries. Scope/authorize before replay, recognize identical replay before stale-version rejection, and reject changed payloads with 409. Immutable movement identities provide a second duplicate guard. Transactional company/type counters produce PO-000001 and GRN-000001 without resets.
- Native Tabler list/detail/form screens preserve errors/input/focus, use existing CSRF/workspace protection and saved-draft review before approval/confirmation. Attachments, sales/reservations, accounting and printing remain outside Phase 4.
- Completion requires focused ERP checks; forced/observed independent-process MySQL waits for first balance, duplicate confirmation, over-receipt, closure, adjustment/correction, numbering, revocation and first stock/UOM; aggregate composer test; scoped PHPCS/syntax/whitespace; egolite with inspected screenshots/runtime errors; independent review. No commit, push or deployment.

### Phase 5 — Sales, reservations and dispatch

**Baseline:** `1197f9b`. **Status:** complete locally. Scoped Git delivery was subsequently authorized; no deployment or Phase 6.

**Structure:** ordinary SO/immutable revision/item, DO/item and line-reservation models. Two new services (`SalesService`, `DispatchService`); explicit internal `reserve`, `release`, `postDispatch` operations extend `StockService`. Reuse `DocumentOperation`, counters, Workspace, Audit, Brick and native October/Tabler forms. No separate reservation coordinator, workflow framework or dependencies.

**Confirmed commercial rules:**
- Customer and branch become fixed after the first draft save. Every saved line requires an active authorized source location in that branch.
- New lines require an active package with a non-null catalogue sales price; zero is valid, blank unavailable even for an override. Overrides require a reason.
- Ordinary edits retain commercial snapshots. Selected-line repricing uses active priced masters and creates an audited revision. Shared `DocumentTotals::convertUnitPrice` retains the exact source price/mode/tax anchor, uses rational arithmetic and rounds only the displayed unit price to four decimals. `calculate()` stays unchanged.
- Approval requires current active customer/product/package/location and a non-null current catalogue price, without replacing saved price/tax snapshots. It approves the saved revision and reserves every line atomically, or leaves all lines unreserved and the SO draft.
- Reopening before any dispatch requires write plus approve and a reason; release reservations, void draft DOs and create a fresh revision. Void draft or never-dispatched approved SOs. After dispatch, close-short releases the complete remainder. Preserve `first_dispatch_at` permanently, including after full reversal.

**Stock and delivery rules:**
- Store one reservation per approved revision item, its fixed location and approved/effectively dispatched/released base quantities. Retain released history. Aggregate demand for duplicate product/location lines.
- Add `reserved DECIMAL(19,4)` to balances. Maintain `available = on_hand - reserved`, `on_hand >= reserved >= 0`, and balance reservations equal summed outstanding line reservations. Approval/release post no physical movements. Dispatch decreases physical and reserved quantities equally.
- Existing adjustments and GRN corrections must preserve reservations. Internal posting takes server-loaded domain records inside the owning transaction; submitted actors, owners, balances and signed deltas are not authority.
- DOs reference one SO's exact approved revision/items, inherit customer details and fixed source locations, and have generated numbers, a required date and optional notes. No external-reference system. A saved draft has no stock effect; dispatch rechecks outstanding own-line reservations.
- Approved reservations remain dispatchable after customer/product/package/location retirement. Corrections reverse a current DO and optionally post a linked complete replacement atomically. Only that SO's approved lines and their fixed locations may be used. Restore original consumption before testing replacement quantities; check final net balances per stock key while recording complete immutable reversal/replacement movements. Smaller replacement or full reversal restores the difference as reserved stock.
- Recompute effective progress. Full reversal can return to approved but never unlocks commercial revision. Manual void/close-short blocks dispatch and correction. Add unique delivery-item/action movement identities while retaining receipt identities.

**Transaction and UI contract:**
- Context/permission precede payload validation; shared company lock is the first transactional read, followed by fresh authorization. Lock operation identity, parent SO, children/reservations, sorted masters/products/locations, then sorted stock keys. Use current locking reads and unique missing-balance creation.
- Scope and authorize before replay; matching action-specific UUID/fingerprint retries return the prior result before stale-version rejection, changed input returns 409. Documents/reservations/balances/ledger/audit commit together. Native counters generate SO/DO numbers without recycling or yearly reset.
- One explicit sales component provides `/sales` and `/delivery` searchable paginated list/detail/form/history views. Reuse native row/error JavaScript, preserve inputs/focus and saved-draft review before approval/dispatch. Workflow selectors require workflow permission, not `catalogue.read`. Approval needs `salesorder.approve`; reopening also needs `salesorder.write`.
- Reads are company/authorized-branch scoped. SO details expose only their order quantities/reservations. Stock totals require `inventory.read`; inventory shows on-hand, reserved and available. Keep action permissions separate.

**Acceptance gates:**
- [x] Domain/lifecycle checks: exact quantities/overflow, zero/blank prices, reasoned override and drift-free conversions, frozen snapshots/repricing, approval eligibility/retirement, isolation/permissions/stale context/version, matching/changed/revoked retries, atomic audit rollback; duplicate products/split locations/multiple DOs, wrong-order consumption, reopen/reapproval, terminal closure and full-reversal history lock.
- [x] Prove `10 on-hand → reserve 6 → dispatch 2 → release 4`, with movement counts. Reject reductions of 5 and permit 4 from on-hand 10/reserved 6 through adjustment and GRN correction. Reconcile both ledgers and unique physical posting identities.
- [x] Forced MySQL waits: independently running processes identify the exact blocking connection/table before release. Cover scarce approvals, approval/dispatch replay, over-dispatch, closure/dispatch, approvals versus adjustment/GRN correction, correction/stock writes, permission revocation; run affected prior harnesses.
- [x] Focused ERP suite then one final `composer test`; scoped PHPCS, JavaScript syntax and tracked/new-file whitespace.
- [x] Egolite on the verified served checkout/runtime/schema: save/reload, pricing/repricing, failed/successful approval, partial/full dispatch, replay rejection, reopening/close-short, corrections/full reversal, retirement, protected adjustments, search/pagination, restricted access, stale context/version and real CSRF using otherwise-valid requests; keyboard/focus/mobile, inspected screenshot hashes and runtime errors.
- [x] Independent review and exact evidence in [Phase 5 evidence](evidence/phase-5-sales-reservations-2026-10-15.md).

**Gate:** mark complete only after MySQL, egolite and independent review pass. Preserve additive data if interrupted and list remaining checks. Stop before Phase 6.

### Phase 6 — Operational UI, references, printing and acceptance

**Files:** ERP invoice-reference model/service/components, protected document views, completed `themes/vmod` navigation/forms, target document standard, `tests/DocumentAccessTest.php` and `tests/FoundationInstallTest.php`.
**Consumes:** all prior phases and their verified domain operations.
**Produces:** usable generic ERP journeys and a fresh-install acceptance record.

- [x] Add scoped external invoice references (number/date/amount/currency and operational-document links), duplicate detection and audited edits. No invoice issuance, payment balance or accounting entry is created.
- [x] Complete searchable paginated lists, details, forms and permission-based navigation. Reuse native controls/AJAX; add no new UI library solely to reproduce the source theme.
- [x] Write the target document standard from the confirmed scope, then build authenticated PO/SO/DO/GRN HTML previews with browser print/save-as-PDF. Mark drafts/voids clearly; use historical snapshots. Server PDF generation/bulk archives remain deferred.
- [x] Verify mobile and keyboard operation, focus return, escaped input, validation preservation, safe errors, forbidden direct AJAX/print/file requests and real-request CSRF behavior. Test uploads only if that optional evidence surface was implemented.
- [x] Verify fresh installation using October/Laravel, the explicitly approved RainLab.User release and compatible dependencies locked in Phase 1, plus Vmod.Core/Vmod.Erp. Exercise the guarded company/owner bootstrap. Run schema/runtime scans for legacy table prefixes, class strings, aliases, permissions, commands, routes, template codes and excluded plugins. Historical citations and third-party license notices are allowed; generic words such as SVG vector are not brand failures.
- [x] Run the complete Vmod suite, required MySQL check and focused browser journey: company setup → catalogue → PO → partial/full GRN → SO approval/reservation → partial/full DO → invoice reference → printed history. Check stock and tax snapshots after reload.

**Gate:** all listed MVP flows pass on the proven PHP 8.2 target with fresh synthetic data, no excluded runtime dependency and no unexplained balance/snapshot differences. Report local qualification separately from deployment or external-provider acceptance.

### Phase 6 confirmed decisions (2026-10-16)

- One invoice reference fixes company, branch, purchase/sales direction and counterparty after saving. It records an external number/date and a nonnegative two-decimal gross amount (zero allowed), with currency derived from linked order snapshots. No allocation, matching-total requirement or accounting effect.
- Require one or more concrete document links. New links accept approved/partial/completed PO/SO, confirmed GRN or dispatched DO; exclude draft, voided and closed-short orders. Retain existing links through later transitions. Pin the selected order revision or exact fulfilment record and originating revision; show current status separately.
- Trim outer number whitespace and uppercase for active comparison, preserving internal whitespace/punctuation and trimmed display text. MySQL enforces company/direction/counterparty/active-number uniqueness across branches through a nullable active key. Reasoned void releases it; reasoned restore checks it while retaining historical links. Voided references are read-only. Correct ownership by voiding and recreating.
- Reuse DocumentOperation unchanged. Lock company, operation, reference/existing links, then sorted new order parents before fulfilment children. Validate additions under those locks; translate duplicate conflicts to 409. Each successful operation has the existing operation audit plus one service-local detailed before/after/link audit, atomically.
- Invoice permissions expose minimal document identity/status. Full documents and printing require their own read permission and branch access. `/invoice-references` uses native Tabler forms, search/filter, 15-row pagination and audit history.
- `/documents/:kind/:id/print` is authenticated escaped HTML with private/no-store responses. PO/SO accept a parent-validated revision; history and invoice links pin it. PO/SO include saved commercial totals; GRN/DO show quantities only. Print each saved revision/original/replacement individually with state, reasons and correction references. New revisions copy issuer snapshots; new GRN items capture location names; DO reuses SO location snapshots. Old rows remain unchanged and explicitly disclose current-value fallback when snapshots are absent. See [document standard](create-document-standard.md).
- Acceptance includes an independently verified empty disposable MySQL install, guarded owner bootstrap, unchanged-lock/platform checks, runtime/schema scans, independent-process observed waits, the UI-created principal workflow, private browser PDFs, mobile/keyboard/security checks, independent review and final aggregate tests. No commit, push, deployment or provider integration is authorized.

## 5. Runnable verification contract

Commands below are for implementation time, after the named files exist. Execute inside the verified PHP 8.2 application/test container at `/var/www/html`, with disposable test credentials supplied through the controlled environment, never printed in commands/logs.

```sh
php -v
composer check-platform-reqs
php vendor/bin/phpunit -c plugins/vmod/core/phpunit.xml --do-not-cache-result
php vendor/bin/phpunit -c plugins/vmod/erp/phpunit.xml --do-not-cache-result
composer test
```

Phase 1 records the exact container invocation and owned test database identity. Later runs reuse that invocation after rechecking PHP version and mount provenance. Run the guarded `tests/vmod/check-erp-concurrency.php` and `tests/vmod/check-purchasing-concurrency.php` with the observer credential privately supplied on stdin; [Phase 4 evidence](evidence/phase-4-purchasing-stock-2026-10-15.md) gives the exact invocation. Phase 5 adds its reservation races. Run only existing applicable checks; do not call a missing check a pass.

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

**2026-10-15 — Phase 3 complete (local qualification).** `Vmod.Erp` delivers the approved master data, native Tabler catalogue screens, exact decimal totals and independent scalar snapshots. Core owns the new currency settings/permanent first-price lock. Additive Core 1.0.3 and ERP 1.0.1 migrations apply only to the owned dev/test schemas in `/Users/stev/htdoc/vmod_wesria`, served by PHP 8.2.34/MySQL 8.0.46. Focused ERP passes 7 tests/109 assertions; six independent-process races **force and observe** MySQL row-lock waits. Final `composer test` passes 40 Vmod tests/217 assertions, eleven unsafe-target guards and five Core races; 421 framework tests execute (425 total, 2,287 assertions, four unchanged SQLite-only skips). Egolite TaskSpace 25 passes 13 scenarios with nine visually inspected screenshots and zero JavaScript runtime errors; a fresh current-version CSRF probe returns 419 without writes. Scoped PHPCS, syntax and tracked/new-file whitespace checks pass. Independent review has no remaining confirmed defects. [Phase 3 evidence](evidence/phase-3-catalogue-2026-10-15.md) records all 30 files, exact commands/runtime identities, review repairs, observed race outcomes and screenshot hashes. Implementation acceptance completed before Git delivery. The user subsequently authorized the scoped commit and push on `main`; no branch merge is needed because local and fetched remote baselines match. No deployment. **Ready for Phase 4; not started.**

**2026-10-15 — Phase 4 complete (local qualification).** Purchasing, partial/full receiving, reference-chain corrections/full reversal, opening stock and adjustments are implemented with immutable commercial revisions and an append-only reconciled ledger. ERP 1.0.2 adds ten tables in the owned dev/test schemas; Core remains 1.0.3. Focused ERP passes **14 tests/197 assertions**. Nine purchasing races force and observe exact MySQL waits; all six adjacent catalogue races pass. The single final aggregate run passes **47 Vmod tests/305 assertions**, eleven unsafe-target guards, five Core races and the framework suite (421 executed/425 total, 2,287 assertions, four unchanged SQLite skips). Egolite TaskSpace 28 passes **12 scenarios**, with **eight inspected screenshots** and zero JavaScript runtime errors. Final development reconciliation finds zero balance mismatches or duplicate receipt postings. Independent review's reopen-audit finding is repaired and verified; scoped PHPCS, JavaScript syntax and tracked/new-file whitespace pass. [Phase 4 evidence](evidence/phase-4-purchasing-stock-2026-10-15.md) records the exact files, runtime/schema identities, commands, race outcomes, repairs and screenshot hashes. Implementation acceptance completed without commit/push/deployment. The user subsequently authorized the scoped Phase 4 commit and fast-forward push on `main`, including the existing Phase 3 commit; verify remote ancestry before delivery. **No deployment. Stopped before Phase 5.**

Append one compact entry per executed phase with: date; exact target checkout/runtime; changed files; schema/database scope; commands and pass/fail counts; MySQL/browser proof where required; unresolved limitations; next phase. Include commit IDs only if commits were separately requested. Never mark a skipped, blocked or historical check as freshly passed.


**2026-10-15 — Phase 5 complete (local qualification, uncommitted).** Sales revisions/repricing, atomic line/location reservations, partial/full delivery, corrections/full reversal and permanent dispatch history lock are implemented with two explicit services and StockService extensions. ERP `1.0.3` adds six tables plus balance/movement fields. Focused ERP passes **26 tests/440 assertions**. Twelve exact sales waits, nine affected purchasing waits and six adjacent catalogue waits pass; physical and reserved balances reconcile. The single final aggregate run passes **59 Vmod tests/548 assertions**, eleven unsafe-target guards and five Core races; framework 421 executed/425 total, 2,287 assertions, four unchanged SQLite skips. Egolite TaskSpace 33 passes **nine scenario groups**, **ten inspected screenshots** and zero JavaScript runtime errors. Independent review found one component-registration defect, repaired with a failing-first regression check and verified rendered/AJAX workflows. Scoped PHPCS, JavaScript and tracked/new-file whitespace pass. [Phase 5 evidence](evidence/phase-5-sales-reservations-2026-10-15.md) records the exact manifest, runtime/schema, commands, waits, reconciliation and screenshot hashes. **No commit, push or deployment. Stopped before Phase 6.**


**Phase 5 Git delivery authorization:** after local acceptance the user requested commit, merge and push. The exact 33-file manifest is authorized for `main`; local and remote pre-release heads match `1197f9b`, so no separate merge is needed. See the [delivery custody record](evidence/phase-5-sales-reservations-2026-10-15.md#separately-authorized-git-delivery). This does not authorize deployment or Phase 6.


**2026-10-16 — Phase 6 complete (local qualification, uncommitted).** Native invoice references, fixed ownership, revision-pinned concrete links, active duplicate enforcement, reasoned edits/void/restore and atomic detailed audit are implemented. Protected PO/SO/GRN/DO HTML/browser printing preserves snapshots and discloses missing history. ERP `1.0.4` is additive in the owned development/test schemas. Fresh installation proves **0 → 97 tables and zero accounts** before guarded bootstrap acceptance. Focused checks pass **9 tests / 392 assertions**. The final aggregate passes **68 Vmod tests / 940 assertions**, eleven unsafe-target guards and five Core races; framework **421 executed / 425 total, 2,287 assertions, four existing SQLite skips** remain separate. Invoice races pass **8 exact observed waits**, alongside **9 purchasing, 12 sales and 6 catalogue waits** run serially. Eg olite TaskSpace **43** passes **10 scenario groups**, with **20 inspected screenshots, five inspected browser PDFs and zero observed JavaScript runtime errors**. Independent review's omitted print-UOM finding and screenshot review's mobile wrapping defect are repaired with failing-then-passing browser checks. Development physical/reserved balances and invoice audits reconcile. Scoped PHPCS, syntax and whitespace pass. [Phase 6 evidence](evidence/phase-6-references-printing-2026-10-16.md) records the 34-file manifest, exact commands, runtime/schema identities, waits, review dispositions, hashes and Composer advisory-refresh limitation. **No commit, push or deployment. Generic MVP locally qualified; later product work remains deferred.**
