# Food Court Circularity — phased implementation plan

> For agentic workers: read this file and the linked project contracts. Implement only the selected phase, using the writing-plans execution workflow appropriate to that phase. Keep writes sequential; independent read-only reviews may be delegated. Record the phase's evidence before advancing. This document does not authorize implementation, a commit, push or deployment.

**Date:** 2026-10-20, Asia/Kuala_Lumpur.

**Status:** Phases 1–4 complete locally. See [Phase 2 evidence](evidence/circularity-phase-2-2026-10-20.md), [Phase 3 evidence](evidence/circularity-phase-3-2026-10-21.md) and [Phase 4 evidence](evidence/circularity-phase-4-2026-10-21.md). Phase 3 started from clean `main` at `2fe3953`; Git commit, merge and push were separately authorized after acceptance. Phase 4 qualified baseline `1937e16` on 2026-10-22; its changes remain uncommitted. Phase 5 remains not started.

**Inspected baseline:** `cfe3812`, `/Users/stev/htdoc/vmod_wesria`.

**Goal:** Let management staff record food-court measurements and view accurate current totals through the existing frontend, with a circularity API plugin that can later move to another host.

**Architecture:** A framework-only `Vmod.Circularity` provider owns its data and API. A small `Vmod.CircularityUi` plugin retains Wesria identity, permissions and frontend integration. They communicate through real HTTP from the first phase.

**Stack:** Existing PHP 8.2, October 4.3, Laravel 12, MySQL 8, October AJAX and Tabler 1.5.1. No new runtime dependency.

## 1. Confirmed scope and boundaries

### Product decisions

- Management staff enter measurements. There is no stall/supplier/collector self-service in this MVP.
- One Core company is the management workspace; one Core branch represents one food court. Circularity owns simple stall/shared-area records within that court, not another court registry.
- Capture food waste, packaging waste, mixed waste, reusable items issued and reusable items returned. Keep mixed waste separate; never estimate its food/packaging composition.
- The input form offers **Daily total** by default and **Individual weighing/collection** as an alternative.
- An input group is **court + area + measurement date + measurement type**, across its destinations. Each group uses one active input mode. Remove all active entries in that group before changing its mode. Other groups can use different modes.
- In daily mode, maintain one active total per destination within the group. In individual mode, allow multiple readings for the same destination; each is a separate entry.
- Staff may edit or remove entries, with confirmation before removal. Keep removed rows internally using soft deletion; provide no restore endpoint, trash screen or restore screen.
- Inputs and results remain editable/current: no review queue, approval, period close, frozen payload, immutable report or combined circularity score.
- Manual forms with optional notes/reference text only. Uploads, imports, devices, POS, ERP transactions and carbon calculations are outside this MVP.
- Record a physical quantity once. A shared area represents a measurement location, not a court-wide subtotal to add to already-recorded stall quantities.
- Phase 2 grilling confirmed immutable entry court ownership: remove/recreate to correct the court. Dates may be today or earlier in the reporting timezone. Area names are trimmed and unique per court, including inactive rows, using the existing case-insensitive name comparison. The Active checkbox can reactivate the same area.

### Ownership

| Part | Owns | Allowed dependencies |
| --- | --- | --- |
| `Vmod.Core` | Frontend users, companies, branches, memberships and grants | Remains generic; no circularity dependency |
| `Vmod.Erp` | Existing commercial documents, reservations and stock | Unchanged |
| `Vmod.Circularity` | Areas, entries, validation, API and summaries | October/Laravel and existing platform libraries only |
| `Vmod.CircularityUi` | Workspace authorization, HTTP client and native frontend components | Core; no provider model, service or database access |
| `themes/vmod` | Navigation, capture forms, lists and dashboard | Existing October/Tabler assets |

The provider and UI connector are separate plugins so the provider can move while the UI remains in Wesria. Use the existing development database with `vmod_circularity_*` tables. No cross-plugin foreign keys, joins, model imports, shared PHP contracts or fallback direct calls. External company/branch/frontend-user references are opaque strings; preserve their source namespace when moving hosts. Namespace, company and branch references must have no leading/trailing whitespace: reject ambiguous authority instead of trimming it, because the installed case-sensitive MySQL collation pads spaces.

The [generic ERP plan](vmod-wesria-erp-mvp-plan-2026-10-15.md) remains authoritative for ERP behavior. Apply the [runtime contract](runtime-phase-1.md), [plugin standards](october-plugin-standards-design-2026-03-30.md) and [frontend form standards](october-frontend-form-handling-standards-2026-04-30.md). Event OS is an API-boundary reference, not a source of circularity formulas or frozen-record requirements.

## 2. Lean data, API and UI contract

### Data and calculations

- Use two domain tables: `vmod_circularity_areas` and `vmod_circularity_entries`. Do not introduce a group table, workflow engine, event ledger or replay-history table.
- Areas carry provider ID, source namespace, tenant/branch references, name, stall/shared-area type, active flag, version and timestamps. Deactivation prevents new entries or moves into that area; existing measurements remain visible and editable.
- Entries carry a stable UUID, area, measurement date, type, mode, quantity, destination, optional notes/reference, frontend actor references, version, timestamps and `deleted_at`. Store tenant/branch scope explicitly and enforce agreement with the scoped area.
- Measurement codes: `food_waste`, `packaging_waste`, `mixed_waste`, `reusable_issued`, `reusable_returned`. Modes: `daily_total`, `individual`.
- Waste uses nonnegative kilograms, at most three decimal places. Reusable items use nonnegative whole counts. Unit is determined by type, not submitted as an independent authority. Explicit zero is valid; an absent entry is not zero.
- Store quantities as `DECIMAL(15,3)` and exchange strings; reject floats, exponent input and overflow before SQL. Scope references use case-sensitive collation. The sole internal foreign key is entry → area, without cascading deletion.
- Waste destinations: `unknown`, `landfill`, `incineration`, `recycling`, `composting`, `anaerobic_digestion`, `animal_feed`, `other`. Count entries use `not_applicable`. These describe recorded destinations, not independently verified treatment or recovery.
- Store measurement dates as dates. Default entry date and dashboard month use configurable reporting timezone `Asia/Kuala_Lumpur`; preserve the application's existing UTC timestamp configuration.
- Summaries sum active saved quantities in the provider, using exact decimal arithmetic/SQL aggregation. Show each waste stream separately and separate issued/returned counts. Never subtract them into an inventory balance or reject returns merely because they exceed issues in the selected date range.
- No data shows “No data”; explicitly recorded zero shows zero. Service failure shows an unavailable state, never zero. Do not infer recovery rates, prevention, reuse cycles, coverage or a score.

### API boundary

Use native plugin `routes.php` and a small concrete HTTP client; no SDK, repository abstraction, OAuth server or new authentication dependency.

| Endpoint under `/api/circularity/v1` | Purpose |
| --- | --- |
| `GET /health` | Protected service/version check; no environment or credential disclosure |
| `GET /options` | Provider-owned measurement types, units, modes, destination options, reporting timezone and today |
| `GET/POST /areas`, `PATCH /areas/{id}` | Scoped area listing, creation, rename, deactivation and reactivation |
| `GET /entries`, `GET /entries/{uuid}` | Scoped filtering, pagination and current entry lookup |
| `POST /entries`, `PATCH/DELETE /entries/{uuid}` | Create, edit and soft-remove |
| `GET /summary` | Current totals by date range, permitted court/area and recorded destination |

- A server-held bearer credential identifies the trusted Wesria client and binds it to a configured source namespace. The provider delegates user authorization to that client; it does not query Core or accept browser credentials.
- The bridge generates `X-Circularity-Context` as JSON containing `tenant_ref`, `branch_refs`, `actor_ref` and `permissions`, from freshly authorized Core state. It never forwards a browser-supplied copy. The provider validates this context and checks scope/actions on every operation, including duplicate and removed-record lookups. Health needs only service authentication; other endpoints need scoped context.
- Reads require `circularity.read`; mutations require both `circularity.read` and `circularity.write`. A selected workspace branch narrows reads; a company-wide view includes only granted branches. Empty branch grants grant no access. Mutations identify a concrete authorized branch.
- The UI uses `Auth::getUser()`, `Workspace`, CSRF and the existing workspace-context header. No frontend flow introduces `backend_users` or backend authentication.
- Authorization occurs immediately before dispatch. Revocation prevents later dispatches; an already dispatched save may finish. Do not hold a Core lock across HTTP or claim a distributed transaction.
- Use a configured URL, two-second connect timeout and ten-second total timeout. Do not forward browser cookies, follow redirects with credentials, automatically retry mutations, or silently fall back to database access. HTTPS is required outside explicitly configured local/testing internal-network URLs.
- Use safe JSON field errors and ordinary 401/403/404/409/422/429/5xx handling. Check status and response shape in the client. Preserve entered values on validation, conflict and transport failure.

### Save, edit and removal integrity

- Every create form receives a UUID in its URL and hidden input that survives failed/uncertain submissions and reloads. A duplicate UUID may return the active current entry only when its current normalized measurement fields match the complete submitted create fields. Version alone does not reject replay; differing current fields or removal do. Duplicate creation never writes or resurrects. Do not store the original payload to implement replay.
- For daily mode, an existing combination loads automatically only while quantity, reference and notes remain untouched. Otherwise show **Open existing total** and retain inputs. An existing-entry edit never switches UUID automatically. A save conflict returns only allowlisted conflict metadata; the frontend constructs its own scoped links.
- Edits and removals require the displayed row version. Stale versions fail without overwriting; successful mutations increment the version. Uncertain outcomes are checked by UUID before any new submission.
- Lock the scoped parent area before each entry mutation, then re-read active entries for the affected group. Enforce one active mode and daily destination uniqueness inside that transaction. Every writer, including removal, follows this protocol.
- Changes to area/date/type/destination revalidate the resulting group. Moving areas locks both authorized areas in ascending ID order and rechecks the source entry/version before writing. No partial move or cross-tenant reassignment.
- Removed rows do not contribute to summaries, block a new daily entry or prevent a mode change. Preserve their UUIDs. A deliberate new entry gets a new UUID.
- Authorized removed UUID lookup returns a minimal removed marker. Unknown/out-of-scope UUIDs return 404. **Check saved entry** is read-only and never replaces inputs or advances the displayed version. Failed lookup stays uncertain; 404 does not prove an earlier in-flight save cannot finish. Any later create attempt retains its UUID.
- Lock area updates on the same parent row and require their displayed version. Handle cross-area UUID insert races through the unique index, transaction rollback and fresh scoped lookup. Require one mode per group across destinations; edits cannot change their mode.
- Do not rely on a MySQL unique index containing nullable `deleted_at` to enforce active-row uniqueness. The area lock and active-row check are the required concurrent-write protection.
- Add this intentional-ceiling comment beside the lock: “Entry writes serialize per area; sufficient for manual capture. Introduce narrower group locks only if measured contention warrants it.”

### Frontend behavior

Use one native capture form for both modes, with date, area, measurement type, input mode, destination where applicable, quantity, optional reference and notes. Show the active group's mode; disable changing it while active entries exist and explain that removal is required to switch. Values remain editable.

Use `/circularity` for the live overview, `/circularity/entries` for capture/list/edit and `/circularity/areas` for area management. Reuse the existing layout, validation, focus/error handling, workspace-switch recovery and protected-history behavior. Do not build a second recovery framework. Require confirmation only for removal, not ordinary saves.

## 3. Implementation phases

| Phase | Independently testable deliverable | Status |
| --- | --- | --- |
| 1 | Environment, API boundary and frontend access foundation | Complete locally |
| 2 | End-to-end measurement capture and correction | Complete locally |
| 3 | Live totals and completed frontend views | Complete locally |
| 4 | Integrated MVP qualification | Complete locally |
| 5 | Independent-hosting rehearsal | Not started |

### Phase 1 — Environment and API/access foundation

**Files/responsibility:** New plugin registration/config/routes under `plugins/vmod/circularity` and `plugins/vmod/circularityui`; one Core permission-registration extension in `plugins/vmod/core/classes/Permissions.php`; connector-owned grant migration; focused plugin tests. Runtime changes belong only to the owned external Compose, Nginx and PHP-FPM configuration in `/Users/stev/htdoc/docker_foody_wesria`.

**Consumes:** Current Core identity and runtime contracts.

**Produces:** Protected health endpoint, authenticated HTTP client, scope-validation middleware and registered frontend permission keys.

- [x] Recheck checkout, owned services, mounted source, PHP/MySQL identity and current FPM configuration. Bring the disposable test service online; do not recreate the development schema or change shared databases.
- [x] Add one separate FPM pool/listener inside `php-tech`, with Nginx routing the API prefix to that listener. Preserve the existing UI listener. Use internal `http://nginx-tech/api/circularity/v1` for the local client; do not assume the public local hostname loops back through Traefik from PHP.
- [x] Keep API routes outside CMS/session middleware and omit browser cookies. Reserve separate workers for API calls; do not add an application container or another MySQL service.
- [x] Add the native `vmod.core.extendPermissions` event to the existing registry; the UI connector contributes its two keys. Core never imports either circularity plugin. Use frontend grants, not October backend `registerPermissions()`.
- [x] Under a bounded maintenance window, apply the idempotent grant migration before serving requests with the expanded registry. Compare roles against an explicit pre-change permission list, not current `Permissions::all()` or `isFullAdministrator()`. Add keys only to roles already holding every preceding key; preserve other permissions and all branch grants. Lock the affected company and record the system grant change. Do not call the administration wrapper whose full-admin invariant is being repaired.
- [x] Configure private client credential, provider credential hash and stable source namespace outside Git. Add the real HTTP health call and validated request context without a generic client interface.
- [x] Prove provider-only boot without Core, ERP or the connector, using isolated plugin/cache/storage paths. Validate context denial, missing credentials and existing administrator operations.
- [x] Test Nginx/FPM routing and the synchronous HTTP path with constrained worker counts in a disposable runtime. Run an egolite smoke check of existing login/workspace/ERP navigation.

**Gate:** Actual HTTP succeeds through the separate API listener; missing/invalid credentials fail; the provider boots independently; administrator/delegation behavior and existing UI remain usable. No measurement feature is claimed yet.

### Phase 2 — First complete measurement workflow

**Files/responsibility:** Provider area/entry models, additive migrations, one explicit entry service and API handlers; connector component/client methods; native capture/list/area pages; plugin tests and the circularity egolite runner.

**Consumes:** Phase 1 transport, identity context and grants.

**Produces:** Areas and all five measurement types, usable from the existing frontend through the API.

- [x] Add the two tables and provider-owned options. Validate scoped IDs, input shape, dates, mode, type, destination and quantity before persistence.
- [x] Implement area management plus create/list/read/edit/remove operations, UUID duplicate protection, row versions and the area-lock protocol.
- [x] Deliver the first real form in this phase, including both input modes, mixed waste, existing-daily-total editing and the remove-before-mode-switch rule.
- [x] Reuse native validation and current frontend recovery behavior. An ambiguous response must preserve the form/UUID and direct the user to check the saved entry.
- [x] Verify same-group mode conflicts, duplicate daily rows, valid multiple individual readings, deleted-UUID replay, stale edits and authorized cross-group edits on disposable MySQL.
- [x] Run egolite save → reload → edit → remove, mode switching, read-only permissions, foreign branch rejection and unavailable-service scenarios. Inspect desktop/mobile screenshots.

**Gate:** Management can capture and correct real measurements via HTTP, without double counting from duplicate creates or mixed modes. No approval, snapshot or restore UI exists.

### Phase 3 — Live totals and finished views

**Files/responsibility:** Provider summary query/endpoint; connector overview/filter handling; existing circularity pages/tests.

**Consumes:** Current active entries and provider options.

**Produces:** Filtered current totals and completed native overview/list screens.

**Confirmed Phase 3 decisions (2026-10-21):** Only the overview defaults to the reporting month's first day through today. The measurement list retains its all-history default; overview links carry their selected range. Destination filters apply uniformly to all metrics, so a waste destination leaves reusable metrics with no matching data. Existing exact-date capture lookups and legacy list links remain supported. No schema, permission or writer changes are required.

- [x] Add date-range, permitted-court and area filtering, with the current reporting month as default. Paginate record lists using existing native conventions.
- [x] Calculate totals only in the provider. Show three separate waste totals, destination breakdowns and separate reusable issued/returned counts.
- [x] Distinguish missing data, explicit zero, unknown destination and API failure. Changes/removals must affect the next read without a rebuild or background job.
- [x] Add a known example: food waste daily totals 10.125 kg composting + 2.000 kg unknown yield 12.125 kg; mixed waste remains separate; 8 issued and 12 returned are displayed as independent valid counts.
- [x] Verify filter boundaries, edited/removed quantities, exact decimal sums and no-access/empty states. Check keyboard operation, responsive tables and stale workspace/history recovery in egolite.

**Gate:** Displayed totals reconcile exactly to the filtered active entries. No composite score, net stock balance or verified-recovery claim appears.

### Phase 4 — Integrated MVP qualification

**Current gate:** Accepted locally on 2026-10-22. Focused tests, 15 observed MySQL races, standalone/native-browser HTTP, actual cached Back/Forward navigation, real timeout/revocation recovery, screenshot inspection, scoped checks and aggregate regression pass. See [exact results and limitations](evidence/circularity-phase-4-2026-10-21.md).

**Confirmed history behavior:** Every persisted history return to `/circularity` or its children reloads through server authorization before revealing content. Discard unsaved edits; current permissions determine editable, read-only or denied access. Other applications retain their existing history handling.

**Files/responsibility:** Focused existing tests plus one small synchronized circularity concurrency check, browser runner and phase evidence.

**Consumes:** Completed capture and overview.

**Produces:** A qualified local MVP, with limitations recorded separately from deployment readiness.

- [x] Run focused provider/connector tests, then actual HTTP integration, then affected Core/ERP checks and the existing aggregate test command.
- [x] Force and observe MySQL races for concurrent first modes, daily duplicates, independent individual readings, repeated UUIDs, stale edits/removals and opposite-direction area moves.
- [x] Verify removed rows never reappear from a replay; deleting all active group rows permits a new mode; a rejected move leaves both groups unchanged.
- [x] Exercise actual timeout/uncertain-save recovery without automatic write retries. Prove authorization is checked at dispatch and later revoked requests are denied; do not claim an in-flight request was cancelled.
- [x] Complete egolite journeys for capture, correction, mixed waste, mode conflict, summary reconciliation, workspace switching, read-only users, mobile and keyboard use.
- [x] Inspect screenshots, run scoped PHP/JS/whitespace checks and record exact runtime, schema, commands, outcomes and unresolved limitations.

**Gate:** All required checks pass or the phase stays incomplete. **The operational MVP ends here.** No commit, push, deployment or following phase is implied.

### Phase 5 — Portable product / extraction rehearsal

**Files/responsibility:** Provider packaging/configuration notes, a disposable extraction check and evidence. No new business feature.

**Consumes:** Qualified API contract and circularity-owned data.

**Produces:** Demonstrated independence of host, Core/ERP schema and frontend installation.

- [ ] Use a disposable framework-only October instance and independently guarded test database containing framework/provider tables only. Retain the existing test guard; do not weaken it to accept arbitrary hosts/schemas. If the current owned disposable service/schema is reused, run this rehearsal serially with all other test processes stopped.
- [ ] Install only the provider, then transfer circularity-owned tables/data and its migration state. Preserve source namespace, tenant/branch references, entry UUIDs, versions and soft-deleted rows. Leave source data intact.
- [ ] Point the unchanged connector's configured URL at that API. Repeat the contract and principal frontend journeys with synthetic records.
- [ ] Compare counts, IDs, current values and summaries, including mixed mode groups and removed-UUID rejection. Verify no query/import/file dependency on Core, ERP or the connector.
- [ ] Document required credentials, TLS, framework/runtime prerequisites, storage/cache separation and migration state. Document a future cutover with write pause, backup, reconciliation and rollback accounting for any post-cutover writes.

**Gate:** The same frontend works with the independent provider and reconciled data. Actual hosting migration remains separately authorized.

## 4. Verification and review focus

Use the owned PHP 8.2 test container and guarded disposable MySQL. Plugin tests extend `Vmod\Tests\ApplicationTestCase` during colocated acceptance; standalone-provider boot checks must also prove Core/ERP are unavailable. Keep a small runnable check for non-trivial invariants, using existing PHPUnit/native assertions rather than another framework.

Run future focused suites with:

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpunit -c plugins/vmod/circularity/phpunit.xml --do-not-cache-result
"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpunit -c plugins/vmod/circularityui/phpunit.xml --do-not-cache-result
"${wesria_compose[@]}" --profile test run --rm -T php-test composer test
ego-browser nodejs < tests/e2e/circularity-phase3.mjs
```

The plugin configurations, HTTP proof and concurrency harness now exist from Phases 1–2. Browser checks use the phase-specific runner, including `tests/e2e/circularity-phase3.mjs` for Phase 3. Phase 4 extends integrated qualification; Phase 3 does not add concurrency scenarios. Never broaden database guards or use development data for destructive/concurrency tests.

Review these failure classes explicitly:

1. **Scope leakage:** every ID, UUID, option, summary, conflict and removed-record lookup respects the authenticated client/tenant/branch scope.
2. **Duplicate or overlapping records:** mode/daily uniqueness and stable UUIDs remain correct under simultaneous requests.
3. **Unknown network outcome:** a timeout does not mean rollback; no replay overwrites an edit or resurrects a removed entry.
4. **Access rollout:** old administrators remain administrators; delegated grants do not expand; stale workspace forms fail.
5. **Misleading totals:** mixed waste, zero/missing data, recorded destination and unmatched issue/return counts retain their distinct meanings.

The target graph was unavailable during planning; direct implementation/caller/test inspection was used. Resolve graph identity/freshness again before implementation, and use the project-approved source fallback if it remains unavailable.

## 5. Evidence and resumption

Keep one progress table above and append a compact evidence entry after each executed phase: date, checkout/commit, runtime/schema identity, changed-file manifest, checks/results, browser evidence and remaining gate. A failed, skipped or unavailable required check is not a pass.

**2026-10-20 — Planning/grilling only:** Product questions resolved for dual input modes, mode-switch boundaries, mixed waste and removal. Technical review resolved plugin ownership, HTTP worker isolation, permission rollout, authorization timing and lean write protection. This file is the only planning deliverable; no application code, schema, runtime, tests or deployment was changed or executed by writing it.

**2026-10-21 — Phase 3 accepted locally:** Exact live summaries, native overview and range-filtered lists complete. Focused tests: 29/789 assertions; aggregate Vmod: 100/1,767 assertions; framework: 425 tests with four existing skips. Real HTTP and eight browser scenario groups pass; twelve screenshots inspected. [Evidence, manifest, commands and simulation limits](evidence/circularity-phase-3-2026-10-21.md). No schema or writer changes. Phase 4, commit, push and deployment remain outside this execution.

**2026-10-22 — Phase 4 accepted locally:** Docker recovered and all required qualification ran serially on PHP 8.2.34 / MySQL 8.0.46. Focused: 29 tests / 789 assertions; 15 observed Circularity races; standalone and browser HTTP pass. One egolite TaskSpace passed seven groups, five actual cached history returns, real committed/absent timeouts and revocation; 19 screenshots inspected, zero JavaScript exceptions. Core: 16/89; aggregate Vmod: 100/1,767 plus five Core concurrency scenarios; framework: 425/2,287 with four existing SQLite skips. Scoped checks and independent review pass. [Evidence, receipts, manifest and limits](evidence/circularity-phase-4-2026-10-21.md). Temporary runtime closed; changes delivered uncommitted. No push, deployment or Phase 5 work.

Later score methodology, performance rates, evidence uploads, partner self-service and external integrations need separate specifications. Do not implement weights, denominators, accounting effects or environmental claims from assumptions.
