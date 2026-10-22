# Circularity Phase 8 — Used Cooking Oil Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task only after the business gate below is resolved and implementation is explicitly authorized. Keep writes sequential.

**Status:** Implemented and accepted locally on 2026-10-23 after explicit user authorization. [Acceptance evidence](../../evidence/circularity-phase-8-2026-10-23.md). Changes remain uncommitted; no push or deployment.

**Goal:** Record used cooking oil once as an independent measured kg stream and reconcile it through existing capture, Overview and all four Reporting views.

**Architecture:** Extend the existing provider-owned measurement types and strict HTTP connector contract. Reuse existing persistence, exact-decimal summaries, permissions, forms and report tables. Core and ERP remain unchanged.

**Tech stack:** Existing PHP 8.2 / October 4.3 / Laravel 12 / MySQL 8 / Tabler / installed Egolite; no new dependency.

**Spec:** `docs/vmod-wesria-circularity-mvp-plan-2026-10-20.md`, especially the subsequent-capability gate; `docs/circularity-requirements-gap-2026-10-22.md`, A5/B12 and §6. Baseline inspected: `main` at `8303df28a1cb34c3694199d857256edda6fc0f4f`.

## Confirmed business rules

The user confirmed both grilling rounds on 2026-10-23.

| Decision | Proposed minimum | Status |
| --- | --- | --- |
| Measurement | Actual net kg, excluding the container. No inferred litre conversion. | Q1 confirmed |
| Event and date | One measurement at collector handover; internal transfers are not additional quantities. | Q2 confirmed |
| Existing history | Oil is not included in current food/mixed records. Preserve those entries; no reclassification is needed. | Q3 confirmed |
| Area attribution | Use a stall only for separately weighed stall oil; pooled oil belongs to one shared collection area, with no estimated allocation. | Q4 confirmed |
| Destination | Reuse existing choices: Unknown if downstream destination is unknown; otherwise the reported destination. Notes/reference may hold collector details without claiming verification. | Q5 confirmed |
| Historical oil entry | Allow manually entered past handovers with known actual kg/date under existing date rules. No automatic backfill. | Q6 confirmed |

Management staff remain the operators under the existing confirmed MVP scope. Daily total remains the default: the total handed over for that area/date/type/destination; individual mode records separate handovers. These modes remain mutually exclusive within the existing group. Collector identity is not a new uniqueness dimension. There is no collector or stall self-service.

If future requirements introduce litres, both generation and handover tracking, or structured partner/manifest records, stop and re-scope with the human. This phase records handed-over oil, not oil generated, on-site stock or verified recovery.

## Global constraints

- Keep provider calculations and the HTTP-only boundary; no shared provider models/contracts, Core/ERP joins or new integration.
- Existing kg validation, decimal-string exchange, explicit zero, missing data and unavailable states apply unchanged.
- Reuse daily-total/individual modes, scoped area locking, UUID/version protection, editable current records and soft removal. No new grouping dimension or lifecycle.
- Preserve `circularity.read` / `circularity.write`, fresh workspace authorization, foreign-court rejection and history protection.
- No schema, dependency, permission, navigation, CSS or JavaScript change is expected. No rates, recovery assurance, costing, inventory balance or combined score.
- No development-data backfill or destructive acceptance tests. No deployment; any later commit/push requires separate authorization.

## Technical grilling — resolved

| Challenge | Decision and consequence |
| --- | --- |
| Is a new module/table required? | No. Existing `measurement_type` is a string; `quantity` already stores exact kg. Add `used_cooking_oil` with label `Used cooking oil`, unit `kg`, after the existing waste types. |
| Does aggregation need rewriting? | No. `CaptureApi` derives options, filters and all summary/group buckets from `EntryService::TYPES`. Existing quantity/destination arithmetic stays provider-owned. |
| Does capture need new JavaScript? | No. The type selector uses provider options; `circularityControls()` already chooses decimal input and waste destinations from the unit. |
| Is a sixth metric backward compatible? | Not with the current connector: it requires exactly five. Treat this as an explicit API-contract expansion. Provider and connector must be used as a matched update; no legacy-response normalization, SDK or version-negotiation framework. |
| What happens with an old provider? | The new connector must reject missing/malformed oil support in `/options` and five-metric summaries with the existing unavailable behavior. Never synthesize a zero or No data oil bucket. Old connector/new provider is also unsupported. If independently upgraded consumers are required, re-plan compatibility before implementation. |
| What is a valid new summary? | Exactly the six known metric keys, including oil `kg`; apply the same shape to each group and both periods, independent of JSON key order. Existing five metrics retain their meanings. Oil with zero entries is null; a recorded zero is `0.000`. |
| Will tables need redesign? | No. Keep native responsive tables. Derive the two grouped empty-row colspans from `summary.totals|length` and that length plus the label column. |
| Is new locking needed? | No. Measurement type is already part of the daily/mode group. Oil remains independent of food/mixed waste; business rules govern recording the same physical material once. |

**Shared-contract blast radius:** `/options`, returned entry types and summary/group metric sets expand. Existing independent clients expecting five types cannot be silently kept compatible. No change to authentication, routes or stored legacy entries.

## File budget

Five existing production files; no new production file:

1. `plugins/vmod/circularity/classes/EntryService.php` — add the canonical kg type.
2. `plugins/vmod/circularityui/classes/CircularityClient.php` — options capability check, entry/filter allowlists and exact six-metric total/group validation. Keep fixed known type validation in this connector; do not create a shared cross-plugin registry.
3. `plugins/vmod/circularityui/components/Capture.php` — accept the oil measurement filter; preserve blank-court normalization and drilldown scope.
4. `themes/vmod/pages/circularity-reporting.htm` — derive empty-state colspans.
5. `themes/vmod/pages/circularity-entries.htm` — native `<details>` guidance beside the capture type control, with summary `Used cooking oil guidance`; no JavaScript or new styles. Content: “Record net kg at collector handover, excluding the container. Use a stall only when its oil is weighed separately; record pooled oil once in a shared area. Use Unknown if the downstream destination is unknown. Enter known past handovers using their actual date. Collector details may go in reference/notes; the entry does not verify recovery.”

Reuse existing tests: `plugins/vmod/circularity/tests/CaptureTest.php`, `plugins/vmod/circularityui/tests/BridgeTest.php`, `tests/vmod/check-circularity-http.php`, `tests/e2e/circularity-reporting.mjs`. No new browser runner or runtime.

At completion, update the existing phase contract and gap document and add one compact phase evidence file. The original planning pass changed only this plan and the two existing contract documents; implementation followed on explicit user instruction.

## Review focus

- Double counting across handover, transfer, stall/shared locations or historical food/mixed entries: settle semantics before code; document the operator rule without claiming automatic duplicate detection across different measurements.
- Version mismatch: missing oil capability, old five-metric response, malformed units, missing/extra keys and inconsistent grouped shapes must fail safely.
- Scope: oil filters and drilldowns must retain court/area/date/destination scope, including blank All permitted courts and selected-workspace narrowing.
- Numeric identity: exact decimal sums, zero versus missing, count metrics and existing waste streams must remain distinct.
- UI integrity: a sixth column and empty rows must remain readable and scrollable at 390px, with accessible controls and no hidden browser errors.

## Task 1 — Close planning

- [x] Record Q1–Q3 answers and ask their dependent questions Q4–Q6.
- [x] Record Q4–Q6 answers and exact event/date, measurement, actor/area attribution, historical-entry and destination rules; settle capture instructions.
- [x] Add Phase 8 to the authoritative phase contract without changing the shipped five-type baseline. Correct the gap document's misleading A1/A6 “cheapest unlocks” claim and stale delivery status.
- [x] Obtain confirmation that the bounded plan captures the intended workflow. Stop here unless implementation is separately requested.

## Task 2 — Extend the existing capture/report contract as one bounded change

**Consumes:** Current `EntryService::TYPES`, `CaptureApi` option/entry/summary endpoints, `CircularityClient::request()`, `Capture::onRun()` and existing native templates.

**Produces:** `used_cooking_oil` entries and six-metric ungrouped/grouped summaries using existing endpoints and protections.

- [x] Add focused failing assertions in `CaptureTest.php`: oil input/formatting, `10.125 + 2.000 = 12.125`, `0.000` versus null, filtered and grouped totals, create/edit/remove, existing daily uniqueness/mode rules and scoped rejection. Legacy metrics remain unchanged.
- [x] Add failing assertions in `BridgeTest.php`: six-type options/entries/summaries, malformed/missing oil rejection, old five-type provider rejection, grouped key order, dynamic empty-table colspans and oil drilldowns. Retain the All permitted courts regression.
- [x] Run focused tests and verify failure is due to unsupported oil/old shape, not fixture setup.
- [x] Make only the five production changes in the file budget. No runtime is considered compatible with only one side updated.
- [x] Update current response fixtures to six metrics; keep explicit five-type fixtures only for compatibility rejection checks. Run focused tests to green.
- [x] Extend the existing HTTP fixture/check with oil entries and exact ungrouped/area/date/branch reconciliation; preserve guarded database identity, provider-only boot and existing scenarios.

## Task 3 — Egolite desktop/mobile acceptance and phase close

Use installed Egolite and `tests/e2e/circularity-reporting.mjs` with the existing guarded browser runtime, disposable MySQL and synthetic accounts. Keep one TaskSpace throughout acceptance; resume it after interruption and finish it exactly once after visual inspection and a successful harness exit. Do not install another browser or change the Human Test account/data for destructive journeys.

- [x] Sign in → workspace → Circularity. Verify oil is available in capture with kg/decimal input and correct destination behavior. Open the native help by keyboard and check the confirmed recording instructions. Exercise create, reload, edit and remove through native UI; the next read reflects each change.
- [x] On desktop 1440×900 and mobile 390×844, verify Overview and all four Reporting views. Reconcile oil totals and record links; assert the five existing metrics retain their fixture values.
- [x] Include oil in current and previous periods, explicit zero, no matching records, active empty areas/courts and inactive recorded history. Daily reports show recorded dates only.
- [x] Apply All permitted courts and a selected court; switch reports, refresh, reset and drill down with area/date/destination filters. Report controls must not leak into measurement filters.
- [x] Check read-only access, denied access, foreign court/area rejection, workspace changes and actual Back/Forward after revocation using the existing controls.
- [x] Exercise real provider unavailability, including previous-period-only failure; current successful results remain visible and failures never become zero. Verify old/malformed provider contracts in focused connector tests and real supported contracts through HTTP.
- [x] Inspect desktop/mobile screenshots for capture and every report, six-column scrolling and empty-state alignment. Check keyboard navigation, page overflow and runtime exceptions.
- [x] Require focused tests → real HTTP/browser acceptance → owned-container aggregate suite → scoped PHP/JS/whitespace checks. Run database-owning suites serially after the browser fixture closes.
- [x] Record revision/source hashes, runtime/schema/browser identity, exact commands/results, screenshots and limitations in `docs/evidence/circularity-phase-8-2026-10-23.md`; update the two existing contract documents. Mark complete only after every required gate passes.

Commands, from the repository root:

```sh
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml --profile test run --rm -T php-test php vendor/bin/phpunit -c phpunit-vmod.xml --filter Circularity --do-not-cache-result
bash tests/vmod/check-circularity-http.sh --browser
ego-browser nodejs < tests/e2e/circularity-reporting.mjs
```

The HTTP command owns the disposable fixture and waits for acceptance. Follow its existing completion control, require exit 0, then run the aggregate `composer test` in the owned `php-test` container as documented in `docs/runtime-phase-1.md`. Use scoped PHPCS for changed PHP, `node --check tests/e2e/circularity-reporting.mjs`, and `git diff --check`. A skipped required check is not a pass.

## Planning evidence and stop boundary

Source inspection confirms four core production seams plus one capture-help template; target graph unavailable, so implementation/callers/tests were traced directly. Self-review covered API mismatch, group shape/order, zero/missing, legacy streams, scope, daily identity and responsive tables. No business blocker remains within the confirmed scope. No application source, runtime, data, test or deployment change has occurred in this planning pass. Future collector registry, evidence, conversion factors, multi-stage tracking, cost, ERP, ratios and FCI require separate decisions.

**Execution close:** All planned gates passed; see linked evidence for commands, counts and runtime identities. Rulings: reuse this checkout on a new `codex/` branch so the mounted Human Test runtime sees the same source; run write/revocation/outage journeys only in disposable MySQL and keep Human Test verification read-only. Independent review reported no actionable findings. The initial planning-only paragraph above is historical.
