# Unhappy-path egolite campaign — final evidence

All 50 journeys attempted: **34 pass, 10 fail, 6 blocked**. All IDs have final dispositions, but evidence qualification remains incomplete; product acceptance has **not passed**. No unauthorized access, duplicate posting, or stock/reservation corruption was observed in these campaign cases. This is bounded test evidence, not a claim that all defects are excluded.

Baseline: `478f4a7575b8a85501761d6c01e7241a0b01d361`. Target: https://wesria.local.test/. Egolite TaskSpace **58**, campaign `87dc451bdd`, 16 October 2026. Existing human-testing accounts/data and 13 unrelated plugin-skeleton files were outside scope. No application changes, dependency changes, migration, schema reset, commit, push, deployment, or external email delivery.

## Scope and runtime custody

Exact source manifest:

- `tests/e2e/unhappy-paths.mjs` — one resumable campaign runner, native UI plus controlled frontend requests.
- `tests/e2e/seed-unhappy-paths.php` — one guarded additive fixture/read-only reconciliation helper.
- `docs/evidence/unhappy-paths-2026-10-16.md` — this evidence and decisions.

Owned Compose `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`, project `foodywesria`; PHP/nginx tech services bind this checkout to `/var/www/html`. PHP **8.2.34**, MySQL **8.0.46**, host **mysql-dev**, schema **vmod_wesria_dev**, theme **vmod**. No mysql-test reset or migration was part of this campaign. Browser bootstrap required an authorized outside-sandbox retry; no browser upgrade or profile reset.

Synthetic companies: A **19** (two branches), B **20** (foreign-company probes), C **21** (initially unlocked currency). Actors: owners A/B/C, delegated manager, operator, clerk without posting/approval, invoice-only user, dedicated recovery user, plus invited synthetic recipients. `report.json` supplies sanitized actor/workspace ID aliases and principal fixture IDs. Private passwords/tokens/cookie jars remain outside evidence in mode-0600 temporary files.

Bootstrap identities/roles, an expired invitation/reset token, legacy missing-snapshot examples, a controlled foreign-currency revision, and a 60-line print fixture were guarded fixtures. Principal party/product/package/offer/tax/location, PO/GRN/SO/DO/invoice workflows and pagination records were created through native UI. These fixture exceptions are not counted as UI creation proof. Existing historical data was not rewritten to simulate missing snapshots.

Two tabs share browser cookies and test stale context only. Background competitors authenticate separately via native frontend HTTP handlers, with session-cookie inequality asserted without logging values. Browser races establish observed winner/conflict behavior, **not MySQL lock-wait proof**. Fault interception was scoped to campaign URLs and restored in finally blocks; no shared services were stopped.

## Actionable findings

| Finding | Journeys | Severity / classification | Reproduction and observed effect | Recovery / proposed action |
|---|---|---|---|---|
| F01 Validation messages outlive corrected input | 02 | Medium; must-have UX | Submit incorrect current password, correct it, then submit invalid replacement. Previous field error remains and misidentifies the cause. | Valid credentials remain intact. Clear only obsolete errors on each response and focus the current failing field. |
| F02 Revoked session lacks sign-in recovery | 06 | High; must-have UX | Open populated form; recover password in another session; submit old form. Write is denied, but UI says workspace access denied instead of explaining session expiry. | Fresh authentication and record check recover safely. Offer sign-in recovery without falsely implying permission removal. |
| F03 CSRF expiry is silent | 12 | Medium; must-have UX | Intercept native company-form POST and forge CSRF. Real HTTP boundary returns 419; no visible reload/session guidance. | No mutation; reload and valid native form succeed. Explain expiry and safe retry. |
| F04 Network failures leave ambiguous save state | 25, 35, 46 | High; must-have UX | Drop GRN/DO response after a 200 commit: stale draft and old green success remain. Block a save before sending: no actionable feedback. | Reconcile/reload before retry; same operation key has one effect; changed dispatch content conflicts. Distinguish unknown outcome from unsent request; never auto-generate a fresh posting key. |
| F05 Invoice scope changes retain incompatible links | 39 | Medium; decision needed / must-have UX | Select PO then change direction to sales. Selected PO remains; save receives generic Not Found. Server prevents invalid relationship. | Remove incompatible link, restore scope and select valid link; save/reload succeed. Choose clear-with-notice versus retain-and-mark-invalid (decision below). |
| F06 Long print descriptions fragment numbers | 45 | High; must-have UX | Print PO-000036 with long description, quantity 99999.0000 and total 999990.00. Narrow numeric columns split digits vertically, including PDF. | Stored totals remain exact; current artifact is unsafe to read. Give numeric columns sufficient width and wrap descriptions, then inspect regenerated PDFs. No campaign repair. |
| F07 Removing focused order row loses focus | 48 | Medium; must-have UX | Keyboard-focus dynamic row remove button, activate it. Active element becomes BODY. | Remaining fields are reachable by Tab but place is lost. Focus adjacent row or Add row. |
| F08 Malformed filters return server error | 50 | Medium; must-have UX | `/invoice-references?page=-1`, `/purchasing?state=invalid`, catalogue query over 100 chars return sanitized 500/Page Error. No SQL/stack leak observed. | Valid URL/filter restores list without mutation. Validate GET input into usable field/page feedback. Technical runner labels this high; final triage is medium because normal valid workflow recovers. |

No automatic fixes are authorized by these findings. Similar symptoms are deduplicated above; raw per-case findings remain in the report.

## Decisions for the owner

1. **Invoice scope changes (F05):** clear incompatible selected links with a visible notice, or retain them marked invalid until explicitly removed. Recommended: retain-and-mark-invalid to avoid silent loss, disable save until resolved, offer explicit remove-all-incompatible action. Applies to branch/party/direction during creation; saved ownership remains fixed.
2. **Unknown save outcome (F04):** recommended default is “Outcome unknown — reload/check document before retrying,” preserving form input and operation identity. Any automatic retry must reuse the same key and reconcile, not create a fresh operation. Approve user-facing recovery behavior before implementation.
3. **Unsaved navigation:** define whether to warn before Back/leave when dirty. Current evidence does not establish a contractual unsaved-change policy; do not add a workflow silently. Recommended: warning only for dirty forms, no warning after successful save.

Optional polish, separate from must-haves: multi-page print middle pages lack repeated document identity/page count; historical print juxtaposes current DRAFT status with saved “Approved revision,” which could be clearer. Neither was classified as arithmetic/data corruption.

## Journey dispositions

Pass means the runner assertions and observed supplemental checks passed for this campaign. Controlled-request boundary probes are distinguished from native visible-error tests in `checks`; a safe fetch response alone is not proof of ideal UI feedback. Every case has setup/failure/reload-or-snapshot/recovery in the runner; screenshots, sanitized HTTP statuses, timestamps, checks, retained attempts and final reconciliation are in the machine report.

| ID | Journey | Disposition | Finding / qualification |
|---|---|---|---|
| 01 | Wrong password and throttling | **pass** | Assertions and recovery passed |
| 02 | Rejected password change | **fail** | F01 |
| 03 | Invalid recovery links | **pass** | Assertions and recovery passed |
| 04 | Wrong-account invitation | **pass** | Assertions and recovery passed |
| 05 | Obsolete invitation | **pass** | Assertions and recovery passed |
| 06 | Session invalidation during edit | **fail** | F02 |
| 07 | Cross-tab workspace switch | **pass** | Assertions and recovery passed |
| 08 | Foreign company and branch IDs | **pass** | Assertions and recovery passed |
| 09 | Permission revocation and replay | **pass** | Assertions and recovery passed |
| 10 | Delegated authority | **blocked** | Boundary assertions passed; native visible UX unproven |
| 11 | Last full administrator | **pass** | Assertions and recovery passed |
| 12 | Real CSRF rejection | **fail** | F03 |
| 13 | Malformed catalogue decimals | **pass** | Assertions and recovery passed |
| 14 | Blank versus zero and currency lock | **pass** | Assertions and recovery passed |
| 15 | Stale catalogue edit | **pass** | Assertions and recovery passed |
| 16 | Immutable UOM and conversion | **pass** | Assertions and recovery passed |
| 17 | Retired selected master data | **pass** | Assertions and recovery passed |
| 18 | Inactive duplicate supplier offer | **pass** | Assertions and recovery passed |
| 19 | Invalid dynamic PO rows | **pass** | Assertions and recovery passed |
| 20 | Supplier-offer mismatch | **blocked** | Behavior assertions passed; required journey screenshot missing |
| 21 | Price and tax snapshot drift | **pass** | Assertions and recovery passed |
| 22 | Stale approval revision | **pass** | Assertions and recovery passed |
| 23 | Partially received PO closure | **blocked** | Behavior assertions passed; required journey screenshot missing |
| 24 | Cumulative over-receipt | **pass** | Assertions and recovery passed |
| 25 | Lost receipt response and duplicate reference | **fail** | F04 |
| 26 | Invalid mixed receipt lines | **pass** | Assertions and recovery passed |
| 27 | Receipt correction versus reservations | **pass** | Assertions and recovery passed |
| 28 | Terminal and superseded receipt correction | **pass** | Assertions and recovery passed |
| 29 | Opening and adjustment mistakes | **pass** | Assertions and recovery passed |
| 30 | Atomic SO availability | **pass** | Assertions and recovery passed |
| 31 | Competing SO approvals | **pass** | Assertions and recovery passed |
| 32 | Exact dispatch reservation ownership | **pass** | Assertions and recovery passed |
| 33 | SO reopen and close release | **pass** | Assertions and recovery passed |
| 34 | Cumulative over-dispatch | **blocked** | Behavior assertions passed; required journey screenshot missing |
| 35 | Lost dispatch response and replay | **fail** | F04 |
| 36 | Delivery correction reservations | **pass** | Assertions and recovery passed |
| 37 | Invoice amount boundaries | **pass** | Assertions and recovery passed |
| 38 | Normalized duplicate invoice identity | **blocked** | Behavior assertions passed; required journey screenshot missing |
| 39 | Invalid invoice links | **fail** | F05 |
| 40 | Retained exact invoice links | **pass** | Assertions and recovery passed |
| 41 | Invoice ownership edit and audit | **pass** | Assertions and recovery passed |
| 42 | Void restore versus duplicate | **pass** | Assertions and recovery passed |
| 43 | Historical print snapshots | **pass** | Assertions and recovery passed |
| 44 | Protected print access | **pass** | Assertions and recovery passed |
| 45 | Long and corrected print layout | **fail** | F06 |
| 46 | Pre-send network failure | **fail** | F04 |
| 47 | Out-of-order lookup | **pass** | Assertions and recovery passed |
| 48 | Keyboard validation and controls | **fail** | F07 |
| 49 | Mobile and zoom resilience | **blocked** | Native 200% zoom blocked; viewport/reflow and keyboard table checks passed |
| 50 | Search pagination and Back | **fail** | F08 |

Journey 49: 320px, 390px, landscape and 720px desktop reflow were exercised, including long labels, invalid-row focus, retained values, keyboard-scrollable inventory table and successful recovery. Browser shortcut did not change measured width/DPR to 200%; **720px is not native zoom proof**. Human/native-browser 200% zoom remains the explicit blocked check.

## Reconciliation and artifacts

Fifteen early cases (03, 04, 09, 15, 18, 19, 20, 23, 24, 25, 27, 30, 34, 36, 37) predate per-attempt reconciliation metadata; their stock assurance is the final campaign-wide reconciliation, not an invented per-attempt receipt.

Final read-only snapshot: **21 stock balances, 50 movements, 28 reservations**, zero reconciliation errors. Every physical balance equals its movement sum; reserved equals sum of approved − dispatched − released by exact company/branch/location/product; on-hand ≥ reserved ≥ 0; no movement/reservation key lacks a balance. Also retained: 46 POs, 33 GRNs, 24 SOs, 19 DOs, 33 invoice references and 34 invoice links. Campaign operations: 286; company audit rows: 454. These counts include additive diagnostic/recovery attempts and guarded fixtures, not just successful principal journeys. Audit cardinality/atomic rollback is asserted per relevant mutation; these aggregate counts are not a global one-to-one audit assertion.

Final report and manifests are ignored local artifacts, retained for inspection:

- [Machine report](../../output/ego-lite/unhappy-paths/87dc451bdd/report.json): all 50 cases, attempts, sanitized outcomes, checks, fixture IDs, findings and final balance rows.
- [Artifact manifest](../../output/ego-lite/unhappy-paths/87dc451bdd/artifact-manifest.json): byte SHA-256 for retained screenshots/PDFs and runner/helper.
- [Inspection receipt](../../output/ego-lite/unhappy-paths/87dc451bdd/inspection/inspected.json): exact inspected bytes and method.
- [Defective print PDF](../../output/ego-lite/unhappy-paths/87dc451bdd/45-long-name-large-total-1792116914428.pdf): F06.

**138 retained artifacts: 129 PNGs and 9 PDFs (12 pages)**, visually inspected using contact sheets/direct views and all rendered PDF pages. Early repeated filenames overwrote 14 manifest entries; these are explicitly marked superseded/unavailable, not asserted as retained original evidence. Later captures use unique timestamps. Earlier source/artifact hash code hashed JSON Buffer encoding; independent review found it, the runner now hashes bytes, and retained artifact hashes were recomputed and verified against inspection receipts. Historical source-hash fields are labelled with their original encoding, not passed off as file SHA-256.

Expected fault responses (403/404/409/419/422, cancelled lookups, injected connection failure) are classified separately from unexpected malformed-query 500s. Native runtime exceptions were collected per case; no current passing case has an unhandled runtime exception assertion failure. Earlier harness errors remain in attempt history, including invalid interception IDs, wrong selectors and incorrect stale test assumptions; repaired probes were rerun rather than presenting those as product failures.

## Commands and reproducibility

Use only the owned runtime and private checkpoint. Do not recreate fixtures over an existing checkpoint. `--create` is additive, `--legacy` supplies explicit exceptions, `--snapshot` is read-only. The private file contains credentials and must never be printed or committed.

```sh
# Scoped syntax/style checks (passed)
node --check tests/e2e/unhappy-paths.mjs
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml exec -T php-tech php -l tests/e2e/seed-unhappy-paths.php
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml exec -T php-tech vendor/bin/phpcs --standard=phpcs.xml tests/e2e/seed-unhappy-paths.php
# Runner reads private options/checkpoint; does not assume shell environment propagation
ego-browser nodejs < tests/e2e/unhappy-paths.mjs
# Read-only reconciliation (redirect raw snapshot privately)
docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml exec -T php-tech php tests/e2e/seed-unhappy-paths.php --snapshot < /private/tmp/wesria-unhappy-acceptance.json > /private/tmp/wesria-unhappy-final-snapshot.json
```

Options `/private/tmp/wesria-unhappy-run.json`: `{"cases":"02,03","supplement":true}` selects cases; omit supplement for principal cases; `variant:"last"` runs only the final supplement when diagnosing. `setup:true` is for first principal UI setup only. Run access cases before stock cases and login-throttle case 01 after account-dependent principal cases. Supplements preserve prior findings; a final PASS cannot erase an earlier unresolved finding. Explicit replay can create additional synthetic records; resume only the intended cases. Source is campaign-specific, not a generic test framework.

`composer test` was not rerun: no product code changed, and this campaign's validation is the live frontend plus scoped harness checks. No fresh-install/SQLite/SQL lock-wait claims are made. Existing phase acceptance evidence remains separate. The byte-hash self-check against SHA-256(abc), all 138 retained artifact hashes, private secret-value scan, source whitespace and scoped Git diff checks passed.

## Independent review disposition

Read-only reviewer challenged visible-response/recovery coverage, exact dispatch fingerprints, independent sessions, stale lookup recovery, mobile keyboard behavior, final reconciliation handling and artifact hashes. Targeted supplemental cases or harness repairs addressed these; product findings were preserved without repair. Reviewer found no additional product finding after supplemental coverage; requested evidence normalization, artifact inspection and honest early-case reconciliation attribution. All three were addressed. Final review identified five additional evidence gaps, recorded below; these were downgraded instead of claimed as passes.

Final source byte hashes:

- `tests/e2e/unhappy-paths.mjs`: `bfd88a7fc2b4c89b79be5bef6c2e91c44c1795a98c76e5492863978d4abfc077`
- `tests/e2e/seed-unhappy-paths.php`: `cca72fda96b7800fd4e3295bfde65b384d04c886409bd7c45a8d4adb3e044b8b`

Browser closure: Fetch interception disabled, network/device emulation cleared, TaskSpace 58 finished with `keep:[]`. Campaign records remain additive and retained.

Final independent review: artifact/report/receipt sets and source hashes match, zero current runtime exceptions, zero final reconciliation errors. **J10** proves 403/no mutation using controlled frontend requests but does not prove native visible error/choice clarity. **J20, J23, J34, J38** passed behavior assertions but have no retained journey screenshot/PDF hash. These five are now **blocked for evidence**, not product failures. Together with J49 native zoom, final counts are **34 pass / 10 fail / 6 blocked**.

A same-space resume was attempted after this review and returned `task space not found: 58`. No second TaskSpace was created under the approved one-TaskSpace boundary. Remaining gate: authorize a follow-up browser campaign for native J10 denial/recovery, retained J20/J23/J34/J38 screenshots and actual 200% zoom. No additional product defect is inferred from these omissions. The audit report is delivered; full campaign evidence acceptance is explicitly incomplete.

## Authorized repair campaign — 2026-10-16 (in progress)

Scope: F01–F08 and evidence gaps J10/J20/J23/J34/J38/J49, with the approved adjacent regressions. Baseline remains `478f4a7575b8a85501761d6c01e7241a0b01d361`; working branch `codex/unhappy-repair`. No commit, push, deployment, dependency or schema change. Original campaign report and artifacts above remain historical evidence.

New egolite **TaskSpace 71** (`Wesria repair and evidence closure`), separate private checkpoint `/private/tmp/wesria-repair-acceptance.json`, separate output `output/ego-lite/unhappy-repair/`. Runner uses `repair:true` in its private options. Existing campaign-owned companies A19/B20/C21 are retained; additional records are additive. Unrelated plugin skeletons remain untouched.

Runtime reverified: compose project `foodywesria`, compose `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`, PHP/nginx bind mount `/Users/stev/htdoc/vmod_wesria` at `/var/www/html`. Development schema `mysql-dev/vmod_wesria_dev`; tests use only owned `mysql-test`. No schema recreation. Browser URL `https://wesria.local.test/`.

Native zoom fallback: automation initially changed the underlying user tab rather than the campaign preview; it was restored immediately to Actual Size. TaskSpace 71 was handed to the human, then resumed after “resized”. Measured campaign page: `innerWidth=850`, `outerWidth=1712`, `devicePixelRatio=4`, `visualViewport.scale=1`, CSS zoom `1`; original DPR was `2`. This proves native 200% zoom, not viewport substitution. Reflow assertions and inspected artifacts remain mandatory.

Focused tests were run red before fixes: signed-out context incorrectly accepted, malformed GET threw uncaught validation. After scoped fixes: **2 tests, 47 assertions**. Targeted workspace/catalogue/document-access/invoice filter run: **29 tests, 288 assertions**. Logs in repair output. Aggregate tests, browser dispositions, artifact inspection, reconciliation and independent review remain pending; this section does not assert completion.

Repair verification update: aggregate `composer test` passed on owned mysql-test: **70 application tests / 987 assertions**, **five synchronized Core MySQL scenarios**; framework **425 tests / 2,287 assertions / four pre-existing SQLite date-aggregation skips**. These skips are recorded separately and are not MySQL evidence. Initial PHPCS found only multiline-call formatting in the five GET handlers; fixed in scope and the rerun passed.

Early repair browser attempts are retained. Cached pre-repair CSS caused the first F01 probe to fail; versioned theme asset URLs deliver the new stylesheet. Actual 200% zoom exposed header overflow at 850 CSS pixels; native flex wrapping corrected it. Native 200% validation, retained input, successful correction and reload are captured; mobile emulation at the same native zoom compounds the size, so the TaskSpace was handed back to restore 100% before accurate mobile acceptance. Egolite's high-level screenshots cropped/blanked at native zoom; retained early captures are diagnostic attempts only. The runner now uses native CDP screenshots after paint, within the same TaskSpace. No intercepted failure is reclassified as a pass just because a later attempt starts.

A read-only source review confirmed October's actual event payload supplies `context.handler`/`context.options`, scoped 401 ordering, escaped 400 pages, invoice scope checks and print content preservation. Final independent evidence review remains required while TaskSpace 71 is open.

Pre-final review follow-up (2026-10-16): the runner now rejects malformed checkpoints instead of replacing them, persists with atomic rename, validates baseline/TaskSpace/runtime identity, and freezes application, runner and fixture-helper SHA-256 values per attempt. Accepted artifacts will carry attempt UUID and source hashes. Event-drain interception now retains runtime exceptions. J10's existing 403 guard now explains that delegates can only grant permissions they hold; permissions and policy are unchanged. The final runner checks restricted choices, exact denial, unchanged data and authorized recovery. J20 checks the intentional cross-party `Not Found` response, retained form and global-error focus. J46 requires the exact conservative unknown-outcome message and full form/key retention; the request-stage interception supplies the separate proof that this controlled request was not sent.

Post-review validation passed: **15 workspace tests / 82 assertions**, scoped PHPCS, JavaScript syntax and whitespace. Final aggregate `composer test` also passed: **70 application tests / 987 assertions**, **5 Core MySQL concurrency scenarios**, **425 framework tests / 2,287 assertions / 4 existing SQLite-only skips**. Logs: `tests-workspace-final.log`, `phpcs-frozen.log`, `composer-test-final.log`. An initial `exec php-test` invocation found no persistent test service; it performed no tests or schema changes. The corrected command used the runtime authority's one-shot `--profile test run --rm -T php-test`.

All 120 early repair PNGs have retained byte hashes and inspection attribution in `inspection/early-inspected.json`. Seven blank captures are unusable; early cropped or out-of-viewport error captures remain diagnostic/superseded, not final acceptance evidence. Current case results are still provisional (26 pass / 4 fail / 20 not run). A source-frozen rerun of 34 required journeys is prepared. J49 will retain separate mobile and native-200% receipts bound to the same application and runner; the final source-bound native segment remains required because UI assets changed after the earlier native capture. PDF generation/inspection, final reconciliation and final independent review remain open.

TaskSpace 71 remains handed to the human for the previously requested native **100% (Actual Size)** reset. Browser execution is paused at this required control handoff, while the code/test work above is complete. After the reset, run the prepared affected/adjacent campaign, inspect mobile artifacts, complete the final native-200% segment, then restore native 100%, interception and emulation before finishing the space. Do not close the TaskSpace or claim all eight repairs/six gaps accepted yet.

## Resume preflight — 18 October 2026

The user authorized completion verification followed by scoped commit, merge to main and push; deployment remains excluded. Attempting `takeOverTaskSpace(71)` returned **`task space not found: 71`**. Egolite's inventory contained spaces 0, 1, 2 and 9, with no repair TaskSpace. The existing user-owned `Wesria human testing handoff` is a separate space and was not claimed. No replacement has been created: approval is pending under the explicit one-TaskSpace recovery boundary.

The private original/repair checkpoints and runner options are also absent from `/private/tmp`. Existing credentials were not rotated. If a replacement is authorized, use fresh isolated synthetic fixtures with the guarded helper, retain the original companies/records, and rebuild required UI prerequisites before accepting rerun results. Do not silently attach fresh fixtures to the old report identity or overwrite previous attempts.

Read-only checks confirmed the served PHP/nginx mount remains this checkout, all 21 pre-browser manifest files are unchanged, and all 13 unrelated plugin skeletons are preserved. A minimal read-only reconciliation input reconstructed only the public company identifiers A19/B20/C21 and original nonce. The guarded snapshot passed with **25 balances / 62 movements / 30 reservations and zero reconciliation errors**; receipt: `output/ego-lite/unhappy-repair/original-reconciliation-2026-10-18.json`. These counts are retained campaign data, not a new browser acceptance run.

Browser reruns, repair PDF inspection, final native-zoom proof and final independent acceptance review remain blocked. No acceptance completion, commit, merge, push or deployment is claimed.

### Replacement acceptance resumed — 18 October 2026

The user approved one replacement for missing TaskSpace 71. TaskSpace **13**, “Wesria repair completion 2026-10-18”, now owns the continuation. The original campaign (`87dc451bdd`) and TaskSpace 71 evidence remain preserved. Fresh additive identities use nonce `39002ac92b`, companies A22/B23/C24; principal catalogue, PO125 and GRN64 were created through the native UI. Bootstrap identities are guarded fixtures. Private credentials/checkpoints stay in ignored `storage/app/acceptance-private/` with directory/file modes 0700/0600.

The served PHP and Nginx mounts still resolve to this checkout at `/var/www/html`; development remains `mysql-dev/vmod_wesria_dev`, PHP 8.2.34 and MySQL 8.0.46. The original campaign reconciled 25 balances, 62 movements and 30 reservations with zero discrepancies before continuation. The 13 unrelated plugin-skeleton hashes were retained. Remote `main` still resolved to `478f4a7575b8a85501761d6c01e7241a0b01d361` during pre-release inspection.

The replacement initially measured native 200% (DPR 4 against the display's native DPR 2, CSS zoom 1, visual viewport scale 1). Its first validation/recovery captures were inspected but became superseded when independent review found cancellation and stale-banner recovery defects. After the user's Actual Size adjustment, the replacement measured width 1700, outer width 1712, DPR 2, CSS zoom 1 and visual viewport scale 1. Final native 200% qualification is still required after the final source freeze.

Independent review found three scoped recovery gaps: sent-request cancellation used October's separate `ajax:request-cancel` event; successful invoice lookup left its transport error visible; restoring compatible invoice scope left the client rejection banner visible. Native RED runs reproduced the first two, and a visible scope-restoration probe reproduced the third. The repair uses October's before-send/cancel events and a local error-kind marker; confirmation dismissal and intentional lookup cancellation stay silent. No retry, context refresh, storage or submission-lock framework was added. This verifies explicit cancellation recovery, not prevention of a user's second submit.

Replacement output is `output/ego-lite/unhappy-repair/replacement-39002ac92b/`. Each attempt retains its source/application/helper hashes, runtime identity, artifacts, rejection/recovery checks and reconciliation. Earlier native zoom, RED and harness-diagnostic attempts remain available; none becomes accepted merely because a later attempt starts. A preliminary cancellation probe used a nonexistent handler and received 400; that diagnostic was corrected before the real RED test and is not product evidence.

Focused MySQL checks passed 29 tests / 288 assertions. The resumed aggregate passed 70 Vmod tests / 969 assertions, five synchronized Core MySQL concurrency scenarios, and 425 framework tests / 2,287 assertions with the four existing SQLite-only skips. The dedicated `mysql-test` service was started without resetting development or other schemas. Scoped PHP syntax (10 files), PHPCS, JavaScript syntax and whitespace checks passed. Logs: `tests-replacement-focused.log`, `composer-test-replacement-final.log`, and `scoped-checks-replacement-final.log` in the repair output root. Browser races remain application-behavior evidence, not MySQL lock-wait claims.

Acceptance remains open pending the final journey dispositions, actual confirmation-dialog coverage, final zoom receipts, PDF/screenshot inspection, reconciliation and independent release review. No commit, merge, push or deployment has occurred at this checkpoint.

### Final-source verification — 18 October 2026 (release gate open)

The frozen 34-journey invocation completed: **33 pass, J49 blocked only on final native-200% evidence**. Its mobile segment passed at 320×740, 390×844 and 844×390, including retained invalid input, focused quantity, successful save/reload, no page overflow and keyboard scrolling of the inventory table. Native 100% was independently remeasured after clearing device emulation: width 1700, outer width 1712, DPR 2, viewport scale 1, CSS zoom 1. TaskSpace 13 is handed to the human for the approved final native-200% fallback. Release remains blocked pending that segment, restoration and final review.

The final runner and helper are frozen. Application source hashes exclude tests/documentation; the release manifest below includes those separately. All required final-source attempts reported zero runtime exceptions, zero reconciliation errors and no new findings. Exact checks and HTTP outcomes remain in `report.json`; prior attempts and findings remain retained.

- Application manifest identity: `d0754d887c4e5137fb397a4d05f0e920803e7d307a6ab0e87042a4fadf802ea5`.
- Runner SHA-256: `387f06961cea2bfa2dce48450222fb6926fe329d03a35b05e7452559d2411ff4`.
- Guarded fixture/reconciliation helper SHA-256: `cca72fda96b7800fd4e3295bfde65b384d04c886409bd7c45a8d4adb3e044b8b`.

Independent review strengthened two assertions before the final run. J48 now opens and dismisses the actual invitation-revoke confirmation, observes October's cancellation event, proves no before-send event and no persisted change, then reloads the pending invitation. The earlier Escape-only probe is superseded. F06's first boundary check used a wide screen and missed touching numeric columns in the actual A4 PDF. Its retained RED PDF is `45-numeric-boundaries-a4-1792257756723.pdf` (SHA-256 `e4de1b8ee707ac57162a0ad7cea9c55e53d58a8a1c2a5dd58a49cec86ae349c8`). The scoped repair uses fixed commercial columns, 8pt numeric text and .25rem horizontal padding. The print asset version was corrected after an initial unmatched replacement left cached 8.5pt CSS; that diagnostic was not accepted.

Final A4 content width is 186mm / 703 CSS pixels with 12mm page margins. At quantity/unit-price maximum `999999999999999.9999` and net/gross maximum `99999999999999999.99`, all values remain on one line within their cells, zero page overflow and minimum adjacent numeric gap **8.125 CSS pixels**. Descriptions wrap separately; base quantity/UOM, totals and saved values remain present. No print library or font-fitting engine was introduced.

Artifact inspection: the root reviewer rendered **all 50 replacement PDFs / 80 pages** at 100 DPI and inspected all 21 distinct raster page hashes, explicitly mapping identical pages to an inspected representative. The historical RED boundary remains failed; final PDFs pass. Root inspected all **145 final-source PNGs** in 37 contact sheets. An independent reviewer directly inspected all **10 final-attempt PDFs / 16 pages** at 130 DPI and meaningful J10/J20/J23/J34/J38/J48 captures. Original/replacement GRN/DO quantities remain separate, status/reasons/references are visible, commercial totals are present only where intended, and script-like synthetic names render as text. The scanner's earlier automatic 40-PDF classification was provisional and is superseded by qualified root/independent inspection; its receipt is retained.

Qualified receipts under the replacement output:

- `inspection/pdf-inspection-root-qualified.json` — SHA-256 `fff6646cc2b446670890b441b87555047731476e2986b8cebffcdebfafe0ba30`.
- `inspection/screens-final-root-qualified.json` — SHA-256 `b5bfdcdf187cb57d7fb213f664ce5b43a96307993f002aee322be8ef80574ab0`.
- `inspection/independent-final-review.json` — SHA-256 `60b06e45ee6aac1c25ec39bb2c4048ed035ee163ff7a96fd4fd60924e978ab63`.
- `reconciliation-final.json` — SHA-256 `b1d0e9782411c223fb793ebe8154111ba8fb37a8b29325af795d6d484b436ca4`.
- `secret-scan.json` — SHA-256 `39936547f440eb9611f2251a9707e4dd530e096165bea23cfb9e867c5d07bf4d`.

Read-only final stock reconciliation: original A19/B20/C21 **25 balances / 62 movements / 30 reservations**; replacement A22/B23/C24 **16 balances / 46 movements / 12 reservations**. Both have zero physical-balance versus movement and reserved-balance versus outstanding-line discrepancies. No service shutdown, schema reset, historical-row removal or human-account credential change occurred. Throttling uses only the dedicated synthetic recovery account. Log-mail recovery proves local token behavior, not external email delivery.

Repair dispositions:

| Finding | Disposition and proof |
| --- | --- |
| F01 | Resolved: October `jax-visible` controls field feedback; J02 checks corrected current-password error hides while still-invalid password feedback remains. |
| F02 | Resolved: valid-CSRF signed-out workspace actions return401 before context comparison; signed-in permissions403 and stale context/version409 retained. J06/07/09/12 and focused MySQL tests pass. |
| F03/F04 | Resolved: persistent escaped419/401/transport/cancellation feedback, retained forms and loading release, fresh-tab sign-in/record checks. J12/25/35/46 prove rejected/unknown outcomes and one-effect replay where keys exist. No catalogue/admin idempotency claim. |
| F05 | Resolved: retained incompatible invoice links carry scope metadata and visible warnings, block save, recover on restored scope, and discard stale lookup results. J39/47 pass. |
| F06 | Resolved: actual A4 maximum-value and long-description PDF inspection passes after the scoped CSS repair. J43–45 preserve saved/history/correction content and access protections. |
| F07 | Resolved: J48 verifies new/next/previous/Add-row focus and reindexed names, plus real confirmation dismissal with no request or mutation. |
| F08 | Resolved: J50 verifies escaped400 correction/reset pages across all seven document/catalogue/inventory routes, safe scalar retention and no misleading results; auth/foreign-ID checks and MySQL regressions pass. |

Gap dispositions: **J10 closed** by exact native visible denial and authorized recovery; **J20 closed** by inspected stale-offer clearing/rejection and correct-supplier recovery; **J23 closed** by retained4-of10 receipt, denied reopen and closed remainder/draft invalidation; **J34 closed** by inspected over-dispatch denial and 2-unit remainder recovery; **J38 closed** by normalized cross-branch conflict, independent-session competition and distinct-number recovery; **J49 pending** final native-200% receipt. Existing unsaved-navigation prompts, optional print polish and FoodCourt policy remain deferred as approved.

Journey ledger (UTC timestamps retained; original passes keep their 16 October dates). The three supporting stock/correction journeys27/33/36 passed during this replacement campaign before the print-only final delta; their stock-flow source is unchanged. They are not relabeled as part of the final34.

| ID | Disposition | Evidence origin | Finished UTC | Attempt |
| --- | --- | --- | --- | --- |
| J01 | pass | final source | 2026-10-17T17:33:56.326Z | 730836fa-35c0-4b7d-a8a2-f93fdf3d985d |
| J02 | pass | final source | 2026-10-17T17:28:26.741Z | 38ff4001-1996-4632-af96-31a8e7885359 |
| J03 | pass | final source | 2026-10-17T17:33:52.197Z | 544fc466-9e85-41d6-9542-557f37725a93 |
| J04 | pass | original untouched pass | 2026-10-16T01:46:08.193Z | original report |
| J05 | pass | original untouched pass | 2026-10-16T02:16:41.995Z | original report |
| J06 | pass | final source | 2026-10-17T17:28:37.094Z | e5209dc2-bbe0-4650-afde-aca981b0f0d3 |
| J07 | pass | final source | 2026-10-17T17:27:42.449Z | 8d1677e1-829d-41fd-a284-5e8100d504ee |
| J08 | pass | final source | 2026-10-17T17:31:55.296Z | 65eb38e7-42f0-4d8b-abe7-8ccb4e201f1a |
| J09 | pass | final source | 2026-10-17T17:28:44.209Z | 6b765abc-677f-4a12-9182-7acd5f8138b6 |
| J10 | pass | final source | 2026-10-17T17:27:50.125Z | 465d7d9b-c788-4931-92cf-bda1654e9a2f |
| J11 | pass | final source | 2026-10-17T17:27:57.757Z | 7c15780c-69ec-4bf8-b69f-6c3509195ef4 |
| J12 | pass | final source | 2026-10-17T17:28:04.803Z | 95acd3e1-6ada-4efc-a7bd-2bd778504e9b |
| J13 | pass | final source | 2026-10-17T17:28:53.238Z | 6359c27f-25e7-428f-9d2b-f149e551401c |
| J14 | pass | original untouched pass | 2026-10-16T02:11:36.893Z | original report |
| J15 | pass | final source | 2026-10-17T17:28:58.480Z | f76bc880-363a-4db2-84eb-52047498e35e |
| J16 | pass | original untouched pass | 2026-10-16T02:21:25.595Z | original report |
| J17 | pass | original untouched pass | 2026-10-16T02:14:44.260Z | original report |
| J18 | pass | original untouched pass | 2026-10-16T01:47:14.380Z | original report |
| J19 | pass | final source | 2026-10-17T17:29:04.470Z | 52c367ec-320e-41ef-8250-45f91c110432 |
| J20 | pass | final source | 2026-10-17T17:29:08.130Z | e23d47bd-e126-44ff-b514-07dba30a7473 |
| J21 | pass | original untouched pass | 2026-10-16T02:14:53.983Z | original report |
| J22 | pass | final source | 2026-10-17T17:29:13.837Z | 1b8931f9-9bfd-4b0e-8924-4e93997589b3 |
| J23 | pass | final source | 2026-10-17T17:29:22.229Z | c2ae9e72-fb86-4668-8326-3921fd57c144 |
| J24 | pass | original untouched pass | 2026-10-16T01:48:42.156Z | original report |
| J25 | pass | final source | 2026-10-17T17:29:57.711Z | e4804be2-9b8b-48b9-891d-a1474e30a1e6 |
| J26 | pass | final source | 2026-10-17T17:29:37.723Z | 7bfe7f44-5df6-45ae-8897-8cb5dfeb02d8 |
| J27 | pass | replacement supporting pass | 2026-10-17T17:16:46.187Z | 81308941-4242-46b2-82c5-cceebe359776 |
| J28 | pass | original untouched pass | 2026-10-16T02:12:01.584Z | original report |
| J29 | pass | original untouched pass | 2026-10-16T02:15:02.704Z | original report |
| J30 | pass | original untouched pass | 2026-10-16T01:53:43.547Z | original report |
| J31 | pass | original untouched pass | 2026-10-16T02:22:04.807Z | original report |
| J32 | pass | original untouched pass | 2026-10-16T02:22:26.569Z | original report |
| J33 | pass | replacement supporting pass | 2026-10-17T17:17:11.282Z | 26176d33-d38a-4e22-bd85-69d25972bb52 |
| J34 | pass | final source | 2026-10-17T17:29:49.219Z | 7facb571-a870-49e9-84e3-576f24f052fe |
| J35 | pass | final source | 2026-10-17T17:30:15.368Z | 9405c5c4-a586-4a0f-adfe-663aad8d4354 |
| J36 | pass | replacement supporting pass | 2026-10-17T17:17:34.714Z | e6624a4c-1705-454a-9977-8292d1308553 |
| J37 | pass | final source | 2026-10-17T17:30:25.639Z | 7be8c19d-aaa1-49d9-ba4c-9895bd308868 |
| J38 | pass | final source | 2026-10-17T17:30:44.808Z | 702cf9d1-81ad-4434-8677-16a0665f2b89 |
| J39 | pass | final source | 2026-10-17T17:31:04.462Z | 530fa9b1-dd6a-44e2-8e92-d564c5701352 |
| J40 | pass | final source | 2026-10-17T17:31:39.474Z | a8b9b7ed-4e33-456f-8171-052b02882073 |
| J41 | pass | final source | 2026-10-17T17:32:09.041Z | 33f00021-5421-4496-b58d-1a54245e67d7 |
| J42 | pass | final source | 2026-10-17T17:32:20.069Z | ee0b8ddb-77b3-49e9-aabd-7d2bd1ad50dd |
| J43 | pass | final source | 2026-10-17T17:32:33.077Z | bd8c4a13-ba5f-40cb-9b75-c077293799ac |
| J44 | pass | final source | 2026-10-17T17:32:44.278Z | dc03f755-6a56-4e65-b2da-8d7fd6f97bb4 |
| J45 | pass | final source | 2026-10-17T17:32:51.881Z | aff8792e-4dcb-4bed-804f-4ab0badc751c |
| J46 | pass | final source | 2026-10-17T17:32:58.676Z | da38d147-aed2-43b6-98d6-54dc2cca5a42 |
| J47 | pass | final source | 2026-10-17T17:33:07.406Z | b6d58bfd-49b7-4290-8384-d176924b2490 |
| J48 | pass | final source | 2026-10-17T17:33:19.243Z | 18aab74c-289a-48f0-b673-3af34609b29b |
| J49 | blocked | final source | 2026-10-17T17:28:14.480Z | 0587d6c0-6824-4b66-ac36-61916bbec52f |
| J50 | pass | final source | 2026-10-17T17:33:45.088Z | e0d3c9f8-e2de-43ff-8ced-4b5509e9e477 |

Commands/results retained: `ego-browser nodejs < tests/e2e/unhappy-paths.mjs` with repair/replacement/rerun options for the approved34; guarded `seed-unhappy-paths.php --snapshot` on the owned php-tech runtime; focused `php vendor/bin/phpunit -c phpunit-vmod.xml --filter 'WorkspaceAccessTest|MasterDataTest|InvoiceReferenceTest|DocumentAccessTest' --do-not-cache-result`; `composer test` in one-shot owned php-test; scoped PHP syntax/PHPCS, `node --check` on app/runner and `git diff --check`. PHP source/tests were unchanged after the passing29/288 and aggregate70/969, five Core MySQL scenarios, framework425/2287 results; only CSS/runner checks were repeated after the final print repair. Four existing SQLite-only skips are not MySQL failures. Served app.js/app.css/print.css bytes match the checkout (`served-assets-replacement-final.json`).

No matching Wesria graph is indexed in codebase-memory, and GitNexus tools are unavailable; no unrelated reference graph was used. Scoped Git change detection and direct implementation/caller/test review provide the documented fallback. Independent source review found no remaining authorization, lifecycle or template defect. Final release approval still requires native zoom closure and restored TaskSpace state.

Exact scoped release paths (22). Credentials, ignored output/runtime artifacts and all13 unrelated plugin skeletons are excluded. Skeleton byte hashes still match the preflight. The evidence ledger's own final hash will be recorded in the ignored release-manifest receipt after closure to avoid a self-referential hash.

| Path | SHA-256 |
| --- | --- |
| `docs/create-document-standard.md` | `35cb7e20eb6d43f58c457ddcc53318b1e29f0d92484358fe820681f8f71df9a6` |
| `docs/evidence/unhappy-paths-2026-10-16.md` | `recorded in final release-manifest.json after ledger closure` |
| `plugins/vmod/core/classes/Workspace.php` | `efd915a5713595ab67a5c0bd6049200001b5ebd8c9bfb0d60a80f67d34c3df74` |
| `plugins/vmod/core/services/AdministrationService.php` | `53d0b4b3cf257135627d335e5aececb6bb151577ccdb7924cb5597bc46539ff5` |
| `plugins/vmod/core/tests/WorkspaceAccessTest.php` | `2f67ba52e6c43644928a4da348240d83e00c6749e4c73a73755a7cefb8954731` |
| `plugins/vmod/erp/components/Catalogue.php` | `2bb071a104703b28090cdbffe4b9f4825a1791b5f577fe242db8e4d4f0845404` |
| `plugins/vmod/erp/components/Inventory.php` | `153089e192a9117a899cf26aa34cf799a526b197d2255483d8c8ef9591353684` |
| `plugins/vmod/erp/components/InvoiceReferences.php` | `3ff3f854744385a6b0058725e6e80c8279bb6245c0882c83bc5f0078b4649331` |
| `plugins/vmod/erp/components/Purchasing.php` | `62dc3baec7e9f55c02e4aed140d2d9050fb494dd7430be64588d29b75deb84d0` |
| `plugins/vmod/erp/components/Sales.php` | `f00ea73673b52fe9aa1794353a2129736a18a170d483f50e1a3f74d629ef6b45` |
| `plugins/vmod/erp/tests/MasterDataTest.php` | `2fee0898f035512a51ac2865ee6243410bd789e7154c5b0800b96dc92282dbd4` |
| `tests/e2e/seed-unhappy-paths.php` | `cca72fda96b7800fd4e3295bfde65b384d04c886409bd7c45a8d4adb3e044b8b` |
| `tests/e2e/unhappy-paths.mjs` | `387f06961cea2bfa2dce48450222fb6926fe329d03a35b05e7452559d2411ff4` |
| `themes/vmod/assets/app.css` | `4d8b483a76bce4e79bbfb6d444c30f4c75d51620f36dc7bc9deed82cc7c6e1c8` |
| `themes/vmod/assets/app.js` | `a3903baf870db4d7d8251af840205fb9493a8de2a9fe86953217ed5c8507e5d8` |
| `themes/vmod/assets/print.css` | `1e0eca57c4df85d338b628bcc80c90eae4d7fcf9698442ca97f86ea892a67596` |
| `themes/vmod/layouts/default.htm` | `d0286044adae8dbd45491a9870c0586bed75097dd6f9e1e20617050ecbdd77f6` |
| `themes/vmod/layouts/print.htm` | `3c4e5365645412953274944d9d7c345ac65c548e06ba5ba584880019d1fe839f` |
| `themes/vmod/pages/document-print.htm` | `f158bd6d4d7f6ed918801993f8e16d952a5f5e6320dcb18494609aa4ab4dc937` |
| `themes/vmod/pages/invoice-references.htm` | `66a126bdd89a086cc240280f98aefce3d4243bb4e647ebb2c2ba63760b37c0cf` |
| `themes/vmod/partials/invoice-choices.htm` | `19b919feb011ae6e76615846a84fb4e26dad3df759b8ff46d14251810bdc015d` |
| `themes/vmod/partials/query-error.htm` | `5c7ac48a2886b0a456cde594d4639969bc603b81a3a3030911214cb13108930a` |

Independent ledger review confirmed the 50 dispositions, exact22 paths, all non-ledger hashes, qualified artifact counts and both reconciliation receipts. Conditional approval is retained in `inspection/independent-conditional-approval.json`; no additional product defect or ledger correction was requested. Remaining mandatory actions are final native200 measurement/run/inspection, J49 receipt verification, native100/interception/emulation restoration, final ledger/manifest closure and TaskSpace finish. No staged files, commit, merge, push or deployment at this handoff.

### Final native zoom closure — 18 October 2026

The human set TaskSpace13 to native200, independently measured as width850 / outer1712 / DPR4 / viewport scale1 / CSS zoom1 (display baseline DPR2). Final-source attempt `b6bbd0a5-e062-4511-a138-57a782f1ac60` passed invalid quantity, correct focus, retained notes, no page overflow, successful correction/save and reload. Root and independent review inspected both original3400×1948 PNGs; the duplicate repair-end image matches the recovery hash. The native and mobile receipts both passed and were marked inspected against application `d0754d887c4e5137fb397a4d05f0e920803e7d307a6ab0e87042a4fadf802ea5` and runner `387f06961cea2bfa2dce48450222fb6926fe329d03a35b05e7452559d2411ff4`.

The receipt-verifier attempt `03fe3c66-5f4a-4a3f-a58f-2cf841cc8d58` completed at `2026-10-17T17:50:44.901Z` and marked **J49 PASS**. This closes the sixth evidence gap. All **34 required final-source journeys pass**; the full50-ID ledger now combines34 final passes,3 unaffected supporting replacement passes and13 original untouched passes. All eight findings are resolved. Earlier blocked/failed attempts remain historical evidence, including the superseded A4 numeric-collision PDF.

- `inspection/native200-final-root.json` — SHA-256 `e77af589033536018c466ae03427766f2af9e58ccec265344e6a45b407e0cc84`.
- `inspection/native200-independent-final.json` — SHA-256 `cc3aaa930d95e8ba1881395040cba2034a556b122c85777334e51631617cb6e6`.
- `inspection/zoom-verifier-root.json` — SHA-256 `3edc7f9afed559dd051500c27213f7963e7d6d19a247a82d820dbade3f1acf0c`.

TaskSpace13 is handed back for the required native100 / Actual Size restoration. Final cleanup verification, TaskSpace finish, final ledger/manifest and commit/merge/push remain gated on that restoration. No release mutation has occurred.

### Final acceptance and browser restoration — 18 October 2026

The human restored TaskSpace13 to native100 / Actual Size. Measurement at `2026-10-17T17:55:24.035Z` confirmed width1700 / outer1712 / DPR2 / viewport scale1 / CSS zoom1. The sole page reloaded successfully and a same-origin GET returned200. Fetch interception was disabled, the network restored online with no latency/throughput restriction, media emulation cleared and device metrics cleared. Root and independent review inspected the restoration screenshot: complete navigation, readable actions/table and no overlap or stale fault feedback.

Independent acceptance was **approved while TaskSpace13 remained open**. It was then finished exactly once with `await taskSpace(13); await task.finish({keep:[]})`; the command acknowledged completion with exit0. No further browser operation was made on that TaskSpace. The earlier restoration receipt intentionally retains `finished:false` because it predates the separate finish acknowledgment.

All **34 required final-source journeys pass**, **F01–F08 are resolved**, and **J10/J20/J23/J34/J38/J49 are closed**. The final composite50-ID ledger retains3 supporting replacement passes and13 untouched original passes with their original dates; the replacement report intentionally labels those13 as not run in this campaign. Superseded failures and blocked attempts remain available. Qualified inspection covers149 final-source screenshots plus the final restoration screenshot; every replacement PDF page is mapped in the qualified receipt (50PDFs/80pages, including one preserved historical RED page). All10 final PDFs/16pages pass independent inspection.

The post-zoom read-only reconciliation at `2026-10-17T17:55:36.083415Z` confirmed16 balances,46 movements and12 reservations with **zero discrepancies**, snapshot SHA-256 `4203c029dee58b2415f5d685a8d9bd1482b7d85ab7d47620d5518710d75ce725`. Original-campaign reconciliation remains25/62/30 with zero discrepancies. Browser races are application evidence, not SQL lock-wait evidence. Existing passing tests remain valid because their PHP source/tests are unchanged; final scoped syntax/hash/whitespace and release-custody checks are repeated.

No mandatory acceptance blocker or unresolved business/user-flow decision remains. The approved22-path release can proceed; all13 unrelated plugin skeletons, additive records, credentials and historical evidence are preserved. No deployment, migration or dependency change is included. Unsaved-navigation prompts, optional print polish and FoodCourt work remain deferred.

Final closure receipts (under `output/ego-lite/unhappy-repair/replacement-39002ac92b/`):

- `browser-restoration-final.json` — SHA-256 `f202b99c41b6da43d0e6d5302f6091a719bd59a1f4516d711cc7395e96088e73`.
- `13-restored-100-final.png` — SHA-256 `b50ccedb0675bc068891110e7e86f00dd9be4949bcd35242553a2c269d38a22b`.
- `reconciliation-after-zoom.json` — SHA-256 `98961b4f83b2f3d73245614289f986147e68a4981ac24feefd892a0f7d690a2a`.
- `inspection/independent-acceptance-approved.json` — SHA-256 `145bb3fc7c66c1c2284aebce26ee7177b914292903ee0c4759ae31890f2fc206`.
- `taskspace-finish-final.json` — SHA-256 `81fa4d660c1ab184bfe3908fb160366ad81853c27583f25aaef0add4299908ce`.

The final `release-manifest.json` records all22 accepted file hashes, including this ledger. Staged, committed, merged and remote identities are recorded separately in the ignored release-custody receipt after each verified Git step; this avoids self-referential commit hashes.
