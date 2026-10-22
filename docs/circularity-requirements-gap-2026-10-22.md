# Circularity — gap against NETSECO requirements and reports available now

**Date:** 2026-10-22, Asia/Kuala_Lumpur
**Baseline:** `main` @ `c169d4d` (Circularity Phases 1–5 complete locally)
**Status (updated 2026-10-23):** Phases 6–7 complete locally and merged/pushed to `main` in `a8c13a2`; the All permitted courts fix and regression are in `8303df2`. See [qualification evidence](evidence/circularity-reporting-2026-10-22.md). Phase 8 used cooking oil is complete locally, uncommitted on `codex/circularity-phase8-oil`; see [acceptance evidence](evidence/circularity-phase-8-2026-10-23.md). Other capabilities remain behind §6 gates. No deployment has occurred; later phases, commit/push and deployment need separate authorization.

**Requirement sources** (proposal documents, not validated specifications):

| Code | File in `/Users/stev/htdoc/nets_os_resource/foodcourt/` | Used for |
| --- | --- | --- |
| RAI | `NETSECO_Circular_Food_Court_OS_Conversation.md` | 13 layers, 13 formal reports, dashboards, scorecards |
| OS27 | `NETSECO_Circular_Food_Court_OS_v2027_Conversation.md` | 27-layer architecture, 8 domains |
| FCI | `NETSECO_FCI_Conversation_Export.md` | 7-dimension index, evidence levels, gates |
| M118 | `NETSECO_MAKANISM_118_Conversation_and_48_Page_Proposal.md` | Pilot pages: tenant scorecard, dashboard, evidence, 90-day pilot |

**Current contract:** [circularity MVP plan](vmod-wesria-circularity-mvp-plan-2026-10-20.md).

---

## 1. Summary

- **Captured now:** 6 manual measurement types per court + area + date: food waste (kg), packaging waste (kg), mixed waste (kg), used cooking oil handed over (net kg), reusable items issued (count), reusable items returned (count). Each waste entry has one *recorded* destination.
- **Reported now:** Overview shows 6 totals and destination tables. `/circularity/reporting` adds period comparison, area/stall breakdown, daily trend and raw court comparison with the same scope/date/destination filters and record drilldowns.
- **Coverage:** the data touches 3 of 13 RAI layers (Physical, Data and Recovery), and only partly. It can seed 3 of 13 formal reports, and only as visibility (maturity L1–L2). It cannot calculate FCI™: 0 of 7 dimensions are fully computable.
- **Selected low-effort gain:** Section 3B items 1–3 and 7 are delivered in Phases 6–7 using existing measurements. Ratios, inferred capture gaps and performance claims remain excluded; the contract still says "Do not infer recovery rates, prevention, reuse cycles, coverage or a score" (plan §2).
- **Blocked reports:** 43 read-only reports, each mapped to the API data, module or feature that unlocks it (Section 3C). Section 3D catalogues the 13 API data items, 7 modules, 7 features and 1 plan permission, ranked by reach, with plan conflicts marked.
- **Main blockers for later reports:** no denominator (meals or transactions), no evidence level, no ERP link, no cost, no reusable-asset identity, no material or stream breakdown, and no link between an area and a tenant.

---

## 2. What exists today

### Inputs (`vmod_circularity_entries`)

| Field | Values | Note |
| --- | --- | --- |
| Court | Core branch (opaque `branch_ref`) | One branch = one food court |
| Area | `stall` or `shared_area`, name, active flag | **Not linked** to an ERP Party, stall owner or tenant |
| Date | Measurement date (no time) | Reporting timezone: KL |
| Type | `food_waste`, `packaging_waste`, `mixed_waste`, `used_cooking_oil`, `reusable_issued`, `reusable_returned` | Mixed waste is never split into components |
| Mode | `daily_total` or `individual` | |
| Quantity | kg (3 dp) or whole count | Explicit zero ≠ no data |
| Destination | `unknown`, `landfill`, `incineration`, `recycling`, `composting`, `anaerobic_digestion`, `animal_feed`, `other`, `not_applicable` | *Recorded*, not verified |
| Reference, notes | Free text | Unstructured; a manifest number has no meaning to the system |
| Actor, version, timestamps, soft delete | — | Edit trail is current-state only, with no history |

### Outputs

| Page | Shipped content |
| --- | --- |
| `/circularity` | 6 measurement totals; destination table per waste type; filters (court, area, destination, date range, quick ranges); drill-down to records |
| `/circularity/reporting` | Period comparison, area/stall breakdown, recorded-date trend, raw court comparison; explicit missing data, zero and service-unavailable states |
| `/circularity/entries` | Record list, capture, edit and remove |
| `/circularity/areas` | Area management |
| API `GET /summary` | Legacy totals unchanged; optional `group_by=area\|date\|branch` adds top-level `group_by` and `groups` with the same six-metric totals shape (Phase 8 requires a matching provider/connector). |

### Adjacent data in ERP (not connected to circularity)

Products/SKUs, supplier offers, purchase orders, goods receipts, sales orders, delivery orders and stock. This is procurement data that RAI L07 and FCI D1 need. The current ownership rules forbid joins and shared models between `Vmod.Erp` and `Vmod.Circularity`.

---

## 3. Reports we can provide now

### 3A. Shipped (no change)

| Page | Report | RAI target it seeds | Maturity |
| --- | --- | --- | --- |
| Circularity overview | **Measurement totals**: food, packaging and mixed kg; reusable issued and returned | #08 Waste & Recovery, #07 Reuse (counts only) | L1 Visibility |
| Circularity overview | **Recorded destination breakdown** per waste stream | #08 Waste, Recovery & Verified Destination (the "recorded" part only) | L2 Measurement |
| Capture / Overview / Reporting | **Used cooking oil handed over**: net kg, recorded destination, period/area/date/court totals | B12, RAI #08 (recorded quantity only) | L2 Measurement |
| Measurements | **Filtered record list**: per area, date, type and destination | L03 Data (raw dataset) | L1 |

### 3B. Existing-data reports (Phases 6–7 selection)

Items 1–3 and 7 use Reporting with optional backward-compatible `/summary` grouping. The earlier weekly aggregation, ratios and area-by-date capture grid are not selected. No input or schema change is needed.

| # | Proposed page > report | Derivation | Serves | Blocked by plan wording? |
| --- | --- | --- | --- | --- |
| 1 | Reporting > **Area / stall breakdown** | `GROUP BY area_id`; active empty areas and inactive recorded history | M118 p19 Tenant Scorecard (waste columns only), RAI #04 seed | No |
| 2 | Reporting > **Daily trend** | `GROUP BY measurement_date`; recorded dates only | RAI L12 historical comparison seed | No |
| 3 | Reporting > **Period comparison** | Two summary calls; selected inclusive period vs the preceding equal-length period | FCI "Δ" signal (raw totals only, not normalized) | No |
| 4 | Overview > **Destination mix %** (share of each stream's kg per recorded destination) | Ratio over existing totals | M118 p34 "Recovery: separated / collected / verified" (only the *recorded* part) | **Yes**: a share of non-landfill destinations reads as a recovery rate. Allowed only if labelled "recorded destination share" with explicit consent. |
| 5 | Overview > **Reusable returned ÷ issued** | Ratio of 2 totals | RAI §14 return-rate seed | **Yes**: the plan forbids implying a return rate, because issue and return are not linked per asset and can exceed 100%. |
| 6 | Measurements > **Capture gaps / data-quality list**: areas with no entry per date; share of `unknown` destination; share of mixed waste | Anti-join areas × dates; ratios | RAI L03 Data Completeness, L04 Evidence seed, FCI anti-gaming "excluded streams" | **Yes** ("coverage") |
| 7 | Reporting > **Court comparison** (raw totals, not normalized) | `GROUP BY branch_ref` | RAI §20 site comparison seed | No; label it "not normalized for footfall" |

**Delivered scope:** 1–3 and 7, plus factual unknown-destination and mixed-waste drilldowns. Empty areas/courts say "No matching records for these filters." This is not proof of a missed collection. Items 4–6 require separate business decisions; no percentages, score, coverage or verified recovery claim was added.

### 3C. Blocked reports and what unlocks them

Each blocked report is a read-only view. The last three columns name what must exist before it can ship. Codes refer to the unlock catalogue in 3D.

- **API data:** a new field, enum value or resource on the provider API.
- **Module:** a new bounded capability with its own records.
- **Feature:** behaviour on top of existing or new data.

All rows also need a `/summary` grouping (area, date, branch, sub-type) and a CircularityUi report view; these are not repeated per row.

| ID | Page > blocked report | API data | Module | Feature |
| --- | --- | --- | --- | --- |
| B1 | Food waste > kg per meal / per 1,000 meals | A1 | — | — |
| B2 | Food waste > by category (prep / unsold / plate / inedible) | A2 | — | — |
| B3 | Food waste > by cause | A3 | — | — |
| B4 | Food waste > cost of food waste (RM) | — | MOD1 | — |
| B5 | Food waste > avoidable vs unavoidable | A2 | — | — |
| B6 | Food waste > edible-surplus redistribution rate | A10 | — | — |
| B7 | Food waste > food input intensity (kg purchased ÷ meals) | A1 | MOD4 | — |
| B8 | Materials > packaging waste by material | A4 | — | — |
| B9 | Materials > material intensity per meal | A1 | — | — |
| B10 | Materials > recyclable → collected → accepted → recovered chain | A6, A7 | MOD2, MOD4 | F1 |
| B11 | Materials > material input vs output balance | A4 | MOD4 | — |
| B13 | Recovery > verified recovery rate | A6, A7 | MOD2 | F1 |
| B14 | Recovery > contamination rate | A8 | — | — |
| B15 | Recovery > capture rate / sorting accuracy | A8 | — | — |
| B16 | Recovery > partner scorecard, chain of custody, recovery certificate | A6, A7 | MOD2 | F1, F5 |
| B17 | Reuse > return rate per asset or cycle | — | MOD3 | — |
| B18 | Reuse > cycles per asset, loss rate, breakage | — | MOD3 | — |
| B19 | Reuse > cost per use, wash cost, wash water and energy | A12 | MOD1, MOD3 | — |
| B20 | Reuse > break-even achievement | — | MOD3, MOD6 | — |
| B21 | Reuse > reuse penetration (reusable-served ÷ eligible transactions) | A1 | — | — |
| B22 | Reuse > single-use items avoided (baseline − actual) | A11 | — | F2 |
| B23 | Tenant > Tenant Circularity Scorecard (9 dimensions) | A6, A8, A9, A13 | MOD4 | F7 |
| B24 | Tenant > benchmark normalized per tenant (kg per meal) | A1 (stall level), A9 | — | — |
| B25 | Tenant > approved-SKU adoption | A9 | MOD4 | — |
| B26 | Procurement > circular procurement coverage, problematic-material elimination | — | MOD4 | — |
| B27 | Procurement > Control Tower with a waste/recovery column | — | MOD4 | — |
| B28 | Supplier > Supplier Circularity Scorecard | A6 | MOD4 | — |
| B29 | Value > disposal and hauling cost, avoided disposal cost | — | MOD1 | — |
| B30 | Value > recovered material revenue | — | MOD1 | — |
| B31 | Value > Circular Value Ledger, ROI, TCO | — | MOD1 | F2 |
| B32 | Value > circular value per meal | A1 | MOD1 | — |
| B33 | Evidence > KPI evidence status (verified / estimated / missing) | A6 | — | — |
| B34 | Evidence > Evidence Confidence | A6 | — | F4 |
| B35 | Evidence > audit chain (score → calculation → entry → physical evidence) | A6 | — | F1, F3 |
| B36 | Index > FCI™ score and dimensions | A1, A2, A4, A6, A8 | MOD3, MOD4, MOD5, MOD7 | P |
| B37 | Executive > Circularity Command Center (FCI, Δ, confidence, top risks and actions) | via B36 | MOD5, MOD7 | — |
| B38 | Carbon > kgCO₂e per meal | A1 | MOD6 | — |
| B39 | Resources > water and energy per meal | A1, A12 | — | — |
| B40 | Pilot > normalized baseline vs pilot | A1 | — | F2 |
| B41 | Improvement > intervention effectiveness, learning rate | — | MOD5 | F2 |
| B42 | Pilot > success gates (functional, financial, behavioural, circular, evidence) | A13 | MOD1, MOD5 | F6 |
| B43 | Behaviour > participation, return and sorting compliance | A13 | — | — |
| B44 | Portfolio > normalized court comparison | A1 | — | — |

Notes:

- For B27, ERP already holds SKU, supplier, price, order volume and stock. It has no material, reorder-point or lead-time field, so MOD4 must add those to ERP Product.
- Any **rate** (recovery, contamination, return, penetration) also needs plan §2 amended. The plan says "Do not infer recovery rates, prevention, reuse cycles, coverage or a score".

### 3D. Unlock catalogue

"Unlocks alone" is the original proposal mapping, conditional on the decisions in §6; it is not implementation authority. Meals cannot supply eligible-transaction denominators, and an evidence label alone cannot establish verification or confidence. "Plan conflict" marks items the current [MVP plan](vmod-wesria-circularity-mvp-plan-2026-10-20.md) forbids or excludes.

**API data**

| Code | Requirement | API shape (provider, `/api/circularity/v1`) | Owner | Reports | Unlocks alone | Plan conflict |
| --- | --- | --- | --- | ---: | --- | --- |
| A1 | Meals or transactions served | New entry type `meals_served` (count, `not_applicable`) per area/day; `/summary` returns per-meal intensity when present | Provider | 11 | B1, B9, B21, B44 | No (manual; POS stays external) |
| A2 | Food-waste category | `sub_type` on `food_waste`: `prep`, `unsold`, `plate`, `inedible` | Provider | 3 | B2, B5 | No |
| A3 | Food-waste cause | `cause` on `food_waste`: `overproduction`, `spoilage`, `portion`, `customer_leftover`, `other` | Provider | 1 | B3 | No |
| A4 | Packaging material | `sub_type` on `packaging_waste`: `paper`, `pet`, `pp`, `aluminium`, `glass`, `other` | Provider | 3 | B8 | No |
| A5 | Used cooking oil — delivered in Phase 8 | New entry type `used_cooking_oil` (kg) | Provider | 1 | B12 | No |
| A6 | Evidence level | `evidence_level` on entries: `documented`, `measured`, `verified` | Provider | 9 | B33 | No |
| A7 | Collector and manifest | `partner_id`, `manifest_ref` on waste entries (needs MOD2) | Provider | 3 | — | No |
| A8 | Contamination / sorting inspection | New resource `/inspections`: stream, inspected kg, contaminant kg, correctly sorted kg | Provider | 4 | B14, B15 | No |
| A9 | Area ↔ tenant | Opaque `party_ref` on `/areas` (no ERP join) | Provider | 3 | — | No |
| A10 | Surplus redistribution | Destination `redistribution`; food `sub_type` `edible_surplus` | Provider | 1 | B6 | No |
| A11 | Single-use items issued | New entry type `single_use_issued` (count) | Provider | 1 | — | No |
| A12 | Water and energy | New entry types `water_m3`, `energy_kwh` | Provider | 2 | — | Outside FCI; supporting indicator |
| A13 | Behaviour observations | New resource `/observations`: behaviour type, observed count, compliant count | Provider | 3 | B43 | No |

**Modules**

| Code | Module | Scope | Owner | Reports | Unlocks alone | Plan conflict |
| --- | --- | --- | --- | ---: | --- | --- |
| MOD1 | Value Ledger | `/rates` per court and period: disposal fee/kg, hauling per collection, recovered value/kg, food cost/kg; cost and value calculation | Provider | 7 | B4, B29, B30 | No |
| MOD2 | Recovery Partner registry | `/partners`: name, streams accepted, destination type | Provider | 3 | — | No |
| MOD3 | Reuse Asset | Asset registry (QR/serial), lifecycle events: issued, returned, washed, lost, retired | Provider | 5 | B17, B18 | Yes: plan has counts only, no asset identity |
| MOD4 | ERP procurement feed | ERP pushes purchase/GRN lines (SKU, qty, supplier, `party_ref`) to a provider endpoint; ERP Product gains material, reorder point and lead time | ERP + Provider | 9 | B26, B27 | Yes: crosses the ERP/provider boundary; API only, no joins |
| MOD5 | Intervention log | `/interventions`: court, area, start date, change, target metric, status, outcome | Provider | 4 | — | Yes: plan has no decision records |
| MOD6 | Factor library | Carbon and break-even factors with source and version | Provider | 2 | — | Yes: carbon is outside the MVP |
| MOD7 | FCI engine | Versioned methodology, dimension weights, gates | Provider | 2 | — | Yes: plan forbids a combined score |

**Features**

| Code | Feature | Owner | Reports | Plan conflict |
| --- | --- | --- | ---: | --- |
| F1 | Evidence attachments (upload, per entry) | Provider + UI | 4 | Yes: uploads are outside the MVP |
| F2 | Baseline lock (frozen totals for a court and period) | Provider | 4 | Yes: plan forbids frozen payloads and immutable reports |
| F3 | Entry revision history (`GET /entries/{uuid}/revisions`) | Provider | 1 | Yes: plan forbids an event or history table |
| F4 | Evidence Confidence calculation | Provider | 1 | Needs a methodology decision |
| F5 | Recovery certificate PDF | UI | 1 | No; follows `docs/create-document-standard.md` |
| F6 | Pilot gate checklist (five gates per intervention) | Provider + UI | 1 | No |
| F7 | Tenant roll-up and weighting | Provider | 1 | No |
| P | Plan permission for a combined score | Decision | 1 | Yes |

**Ranking by proposed reach:** A1 (11), A6 (9), MOD4 (9), MOD1 (7), MOD3 (5). Reach is not effort or readiness: A1 needs denominator/scope rules, and A6 needs verification authority and proof semantics. A field or enum alone does not unlock those reports. A5 used cooking oil is delivered; its confirmed rules and coordinated provider/client contract are in the Phase 8 plan.

---

## 4. Gap matrix

### 4.1 RAI 13 layers

Legend: ● covered · ◐ partial or seed · ○ none

| Layer | Status | Have | Main gap |
| --- | --- | --- | --- |
| L01 Physical | ◐ | Areas (stall/shared), courts | Bins, wash stations, equipment, assets |
| L02 Identity | ○ | Area ID only | Tenant/supplier/SKU/material/batch/waste-stream/asset IDs; area ↔ tenant link |
| L03 Data | ◐ | Manual waste and reusable counts | Transactions/meals, procurement link, utilities, completeness metrics |
| L04 Evidence | ○ | Free-text reference | Evidence level (L0–L3), attachments, collector manifests, destination proof |
| L05 Intelligence | ○ | — | Out of MVP scope (AI) |
| L06 Decision | ○ | — | Intervention/decision records |
| L07 Transaction | ○ (ERP has it) | ERP PO/GRN/SO/stock | No circularity linkage; no deposits or credits |
| L08 Lifecycle | ○ | — | Source → recover journey per material |
| L09 Recovery | ◐ | Waste kg × recorded destination | Stream split (PET/PP/paper/glass/metal), contamination, collector, verified outcome |
| L10 Behaviour | ○ | — | Sorting accuracy, participation, interventions |
| L11 Value | ○ | — | Cost and price inputs (hauling fee, recovered value) |
| L12 Learning | ◐ (derivable) | Date history | Trend (3B-2) exists; no intervention-effectiveness link |
| L13 Compounding | ○ | — | Needs L06 + L12 first |

### 4.2 RAI 13 formal reports

| # | Report | Now | Missing input |
| --- | --- | --- | --- |
| 01 | Executive Intelligence | ○ | Most layers |
| 02 | Food Court Performance | ◐ L1 totals | Denominator, cost, trend (derivable) |
| 03 | Procurement & Supplier | ○ | ERP ↔ circularity link, material attributes on Product |
| 04 | Tenant Circularity Performance | ◐ via 3B-1 | Area ↔ tenant mapping, per-tenant meals/sales, segregation quality |
| 05 | Material, Foodware & Packaging | ○ | Material and stream classification |
| 06 | Food Waste Intelligence & Prevention | ◐ kg only | Category (prep/unsold/plate/inedible), cause, RM value, meals |
| 07 | Reuse System Performance | ◐ counts | Asset identity, washed/lost/retired events, wash cost |
| 08 | Waste, Recovery & Verified Destination | ◐ recorded destination | Collector, manifest, contamination, verification evidence |
| 09 | Carbon & Resource | ○ | Factors, energy and water (out of MVP scope) |
| 10 | Circular Value & ROI | ○ | Costs and savings |
| 11 | Behaviour & Participation | ○ | Behaviour metrics |
| 12 | Evidence, Verification & Assurance | ○ | Evidence level + attachments |
| 13 | Improvement & Compounding | ○ | Baseline lock, interventions, outcomes |

### 4.3 FCI 7 dimensions

| Dim | Weight | Computable now? | Why |
| --- | ---: | --- | --- |
| D1 Circular Procurement & Inputs | 18% | No | No input-side data in circularity |
| D2 Material & Packaging | 16% | No | Packaging is one kg bucket; no material, no "recyclable vs recovered" chain |
| D3 Food Resource | 18% | No | No food purchased, meals or surplus/redistribution split |
| D4 Reuse & Use Intensity | 16% | No | No per-asset cycles or loss; counts only |
| D5 Recovery & Closed-Loop | 14% | Partial input only | Recorded destination exists; capture, sorting, contamination and verification are missing |
| D6 Behaviour & Execution | 10% | No | — |
| D7 Learning & Improvement | 8% | No | No intervention records |
| Evidence Confidence (parallel) | — | No | No evidence level |

The current plan also forbids "combined circularity score". FCI needs a contract change, not only data.

### 4.4 M118 pilot pages (the nearest commercial need)

| Page | Need | Gap |
| --- | --- | --- |
| p19 Tenant Scorecard | 9 dimensions per tenant | Can show only waste kg per stall area (3B-1) |
| p22 Food Waste | prep/unsold/plate/inedible; kg/day, kg/tenant, kg/meal, RM, cause, destination | Category, meals, RM and cause are missing; kg/day and kg/tenant are derivable |
| p23 Material Recovery | material → segregation → collection → partner → destination → evidence | Material, collection, partner and evidence are missing |
| p34 Management Dashboard | FCI, procurement, material, food waste, recovery, reuse, financial, evidence, actions | Food waste, recovery (recorded) and reuse (counts) only |
| p35 Evidence L1/L2/L3 | Evidence status per KPI | Missing |
| p40 Pilot Days 1–30 baseline | Procurement, SKU usage, waste composition, food waste, costs | Waste composition and costs are missing; procurement exists in ERP but is not linked |

---

## 5. Closing the gaps: options

### MVP+ (selected, lean reporting increment)

Phases 6–7 add one CMS page and change four production files: the existing navigation map, Capture component, provider CaptureApi and HTTP CircularityClient. Native GET filters, report selector and Tabler tables reuse existing permissions, workspace/history protections, dates and decimal arithmetic. No schema, dependency, CSS or JavaScript change.

The provider calculates optional area/date/branch breakdowns and overall totals from one grouped read. Period comparison uses two current reads; there is no frozen snapshot. Previous-only service failure preserves current data. Legacy or malformed grouped responses show unavailable, never an empty/zero report. No 366-day cap was introduced.

Acceptance and runtime evidence: [Phases 6–7 qualification](evidence/circularity-reporting-2026-10-22.md). Phase 8 adds used cooking oil in five existing production files with no schema, dependency or asset changes. [Phase 8 acceptance](evidence/circularity-phase-8-2026-10-23.md) includes disposable HTTP/Egolite write and failure journeys plus read-only Circularity Human Test desktop/mobile verification. Later capabilities still require separate decisions.

### Final product (from the RAI, FCI and OS27 sources)

Waste-stream and material registry; reusable-asset identity and lifecycle events; ERP procurement feed into circularity through the API (not joins); cost and value ledger; evidence attachments and verification workflow; intervention and decision records; methodology-versioned FCI with gates; portfolio aggregation. AI, carbon and POS stay out of scope until separately requested (AGENTS.md).

---

## 6. Boundaries before subsequent phases

Resolve business logic and user-flow questions with a human before each capability. Technical implementation choices should continue to use existing native seams and the fewest files.

| Later capability | Required decision before starting |
| --- | --- |
| Used cooking oil | Delivered in Phase 8: actual net kg at collector handover; separately weighed stall oil or one shared area for pooled oil; existing destinations, Unknown when unknown; known past handovers allowed. Existing food/mixed records exclude oil and remain unchanged. Strict six-metric provider/client compatibility and desktop/mobile acceptance passed locally. Independent version skew remains unsupported. |
| Categories, materials and causes | Define daily-total identity and how totals/components avoid double counting; preserve unclassified history. |
| Meals and intensity | Define denominator, scope, operator and missing/zero handling. Meals do not substitute for eligible transactions or establish reuse penetration. |
| Evidence and recovery | Define verification authority, proof requirements, collector/manifest semantics and corrections. Status labels alone cannot establish assurance or confidence. |
| Tenant mapping and ERP | Define historical reassignment, source documents, units, corrections and idempotent API exchange. Preserve the HTTP-only boundary. |
| Value, assets and FCI | Approve cost/baseline rules, asset lifecycle and scoring methodology separately. FCI remains last. |

Ratios, inferred capture gaps, frozen baselines and performance claims remain outside the selected phases. Each later capability needs its own bounded plan, accepted business rules and executable acceptance gate before implementation.
