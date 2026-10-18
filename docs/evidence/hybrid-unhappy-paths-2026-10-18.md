# Second egolite audit — hybrid campaign

Status: **audit complete; TaskSpace15 finished once after independent approval**. Audit result: **48 pass /2 fail /0 blocked /0 not run**. Failed product journeys do not prevent audit completion. Native200 is user-waived, not passed.

Baseline `40a693aaac1e39b7170ae6827decd0a89dcfa9d2`; branch `codex/hybrid-unhappy-audit`; target https://wesria.local.test/. One TaskSpace: **15**. Campaign nonce `063331dc35`, synthetic companies A25/B26/C27. Scoped changes: existing runner/helper and this ledger only. No application changes, migrations, dependencies, commit, push or deployment.

## Authority and runtime

User approved 15 fresh regression journeys H01–H15 and 35 additional journeys H16–H50. Each requires failure trigger, visible response, persisted/reload verification and recovery. Existing human data, prior evidence and all13 unrelated plugin skeleton files are preserved.

Verified runtime: dedicated Compose `foodywesria` in `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`; php-tech mounts this checkout at `/var/www/html`; local PHP8.2.34, MySQL8.0.46, mysql-dev/vmod_wesria_dev, themevmod, mail log. No service/schema reset. 135 tracked application/configuration files and13 skeleton files are hashed in `output/ego-lite/hybrid-unhappy/preflight/`. Private checkpoint `storage/app/acceptance-private/wesria-hybrid.json` is mode0600 inside0700 directory; it is excluded from evidence.

## Rulings and user steering

- User waived native zoom testing: “skip testing zoom, its working well.” Native200 is **waived, not passed**. H15 retains320px/390px/landscape checks. Initial native100 measurement: width1712/outer1712/DPR2/scale1/CSSzoom1. Restore the initial native setting at closure.
- Use this served checkout on a dedicated audit branch, preserving mount identity. Only runner/helper/evidence may change. The approved audit-only/no-commit boundary overrides generic execution-skill commit/cleanup steps.
- Matching Wesria graph is unavailable; direct native implementation, handlers/templates and existing tests were inspected. No unrelated repository graph is used.
- Independent technical preflight found same-alias HTTP cookie jars must be unique per session; repeated administration arrays must use indexed keys; race checks must isolate rejected effects from legitimate competitor writes.
- Operation replay identity is company/action/key, not actor. Authorized same-input cross-actor replay is valid. Extra spoofed actor fields may be ignored safely rather than rejected. Unsupported native handler status is observed, not guessed; no mutation or internal disclosure is required.
- H18 concurrent invitation account creation may require the losing request to sign in as the newly created intended account. H20 protects at least one full administrator; an all-permissions role with restricted branches is not a full administrator.

## Progress

- Preflight passed; new TaskSpace and fresh guarded synthetic identities created once. Principal catalogue created through UI; stock-bearing setup follows access checks.
- Harness updates in progress. Historical acceptance receipts and output directories are unchanged.

### Intermediate review (rounds 01–03)

Seventeen journeys have passing assertions after round03; H15 landscape supplement has an unresolved viewport assertion and is not accepted. H03 was supplemented with native Save rejection/recovery after initial visual review found the 409 capture missing. Prior attempts remain retained.

Independent visual review inspected 26 artifacts/21 distinct PNGs in round01 and 25 artifacts/17 distinct PNGs in round02. Exact hash-bound selections are under `inspection/`. H16 redirects to the remaining workspace without an explanatory disabled-membership banner (flow observation); H18 competitor denial and H23 unsupported-handler rejection currently rely on sanitized HTTP evidence. Full branch grants and spoofed actor checks rely on database assertions, not screenshots. These qualifications remain open for final review.

### Stock batch and stronger persisted checks

Round04 executed H04/H06/H07/H13/H29–H41 with passing assertions. Independent review prompted supplementary read-only checks, retained as `inspection/round04-persisted.json`: all H29–H41 passed exact document identity, movement, winner-specific correction quantity, terminal/recovery state and operation/audit linkage assertions. Stock balance reconciliation reports zero discrepancies. These checks do not reconstruct unavailable pre-race snapshots or establish database lock waits. Round04 image inspection identified missing retained captures of the exact lost-response original GRN/DO after reload; those visual supplements remain pending.

### Navigation findings under verification

- **H48 — decision needed:** after logout and B sign-in, native session-history traversal displayed A's cached PO page, including the old account label and commercial detail. This is a cached presentation observation; fresh-request authorization is checked separately. Options: retain current browser-history behavior with a documented shared-device boundary, or add explicit history restoration revalidation/concealment. Recommended default: require revalidation before redisplaying protected content after an identity change. No change authorized by this audit.
- **H49 — must-have UX / flow decision:** a PO saved successfully in A1 while its response was held; another tab switched to A2. Releasing the response navigated to a bare “PAGE NOT FOUND” because the saved document belongs to A1. Recommend explicit workspace-changed feedback and a deliberate route to check the saved record in A1, without silently replacing the active workspace. Recovery and no-duplicate assertions are being repeated after correcting the harness's incorrect assumption that the workspace chooser preselects the current branch.

Harness failures are retained separately: H08 missing legacy prerequisite IDs; H15 immediate focus geometry before scrolling settled; H45 comparing an uppercase normalized key to lowercase generated text; H48 a boolean navigation marker restored by BFCache; H49 chooser default mistaken for session context. None of these assertion failures alone establishes a product defect.

### Print review

Independent reviewer inspected all **14 generated PDFs /20 pages**, plus15 unique browser screenshots. Numeric boundaries fit intact at A4; long descriptions wrap; historical labels, fallback disclosures and escaped text are readable. Corrected GRN10→6 and DO4→2 retain separate original/replacement quantities and reasons. No clipping, blank pages, overlap or unreadable totals. Exact per-PDF/page/render hashes: `inspection/pdf-pages/visual-review.json` (SHA256 `a3e188e047f7a18417cafbc4b80ddd8a552fe1ae88cfd95626805244d01b3028`). **Optional polish:** document identifier/page numbering on continuation pages; outside automatic repair scope.

## Final dispositions and evidence

Every H01–H50 ID was executed freshly. H48/H49 retain failed dispositions for confirmed flow findings; no critical integrity or fresh-request access failure was observed. Original attempts and harmless harness failures remain in the report. Authoritative detailed evidence: `output/ego-lite/hybrid-unhappy/063331dc35/report.json`; exact302 artifact hashes in `artifact-manifest.json`. All302 references are visually qualified by independent hash-bound receipts (byte-identical captures share review), including14 PDFs/20 pages.

Actor/workspace default is A full administrator in A1; independent competitors use separate native HTTP cookies. H01/H02 use dedicated recovery/operator accounts; H04 revokes operator grants; H16 uses the A/B cross-member; H17/H18 use invited recipients; H19/H20 use A's two full administrators; H21 uses operator branch grants; H22/H23 probe authenticated native boundaries; H27 uses C; H42 uses A/operator/B; H48 switches A→B; H49 switches A1→A2. Exact fixture IDs are retained per journey under `createdRows`; bootstrap-only identities, legacy print/currency fixtures and bulk data are labeled by setup/checks. Principal catalogue and document records were created via visible UI.

| Journey | Scenario | Final result |
|---|---|---|
| H01 | Rejected password change | pass |
| H02 | Session revoked while editing | pass |
| H03 | Stale workspace tab | pass |
| H04 | Revoked permission and replay | pass |
| H05 | Real CSRF failure | pass |
| H06 | Lost receipt response | pass |
| H07 | Lost dispatch response | pass |
| H08 | Incompatible invoice links | pass |
| H09 | Print boundaries and history | pass |
| H10 | Unsent versus unknown save | pass |
| H11 | Keyboard recovery | pass |
| H12 | Malformed filters and navigation | pass |
| H13 | Competing sales approvals | pass |
| H14 | Invoice restore versus reuse | pass |
| H15 | Mobile and native zoom | pass; native200 waived |
| H16 | Multi-company membership isolation | pass |
| H17 | Invitation live-role changes | pass |
| H18 | Cross-company invitation acceptance race | pass |
| H19 | Mutual administrator demotion | pass |
| H20 | Branch creation versus grant edit | pass |
| H21 | Missing or revoked branch | pass |
| H22 | Actor and ownership spoofing | pass |
| H23 | Unexposed native account handlers | pass |
| H24 | Unrepresentable base quantity | pass |
| H25 | Calculated amount overflow | pass |
| H26 | Concurrent catalogue identity | pass |
| H27 | Currency versus first price | pass |
| H28 | Reprice after price removal | pass |
| H29 | Concurrent first stock receipts | pass |
| H30 | Receipt versus reopen | pass |
| H31 | Receipt versus close-short | pass |
| H32 | Competing receipt corrections | pass |
| H33 | Obsolete receipt revision | pass |
| H34 | Concurrent document numbering | pass |
| H35 | Receipt reversal after dispatch | pass |
| H36 | Dispatch retired approved stock | pass |
| H37 | Competing delivery corrections | pass |
| H38 | Dispatch versus close-short | pass |
| H39 | Obsolete delivery reservation | pass |
| H40 | Adjustment versus sales approval | pass |
| H41 | Full reversal retains dispatch history | pass |
| H42 | Operation-key namespaces | pass |
| H43 | Invoice chooser pagination | pass |
| H44 | Invoice edit versus void | pass |
| H45 | Renumber versus duplicate creation | pass |
| H46 | Link versus source transition | pass |
| H47 | Lost invoice-edit response | pass |
| H48 | History across identity switch | fail — HF01 |
| H49 | Late save response after workspace switch | fail — HF02 |
| H50 | Navigate during non-idempotent create | pass |

### Deduplicated findings

- **HF01 /H48 — decision needed:** cached prior-account PO content/totals appears through browser Back after sign-in as B. Fresh request to the same A document is denied without document content. Recommended default: revalidate/conceal protected history after identity changes. Alternative: explicitly document current shared-device browser-history behavior. No policy change implemented.
- **HF02 /H49 — must-have UX, medium:** successfully saved PO remains in A1, active context remains A2 and stale follow-up is rejected409, but delayed response lands on a bare404 with no recovery control. Manual return to verified A1 finds exactly one saved record. Recommended: explicit workspace-change explanation and deliberate original-workspace record check. No silent workspace replacement.
- **HP01–03 — optional polish:** explain disabled-membership redirects; suppress empty pink summary bars beside precise feedback; add document identity/page numbers to print continuation pages. These are separate from must-haves.

### Final technical gates

- Guarded MySQL reconciliation: **0 discrepancies;19 balances,55 movements,17 reservations**. All13 strengthened H29–H41 persisted assertions pass, explicitly checked as a completion gate. Receipt: `final-reconciliation.json`.
- Runner syntax, helper PHP syntax, scoped helper PHPCS and `git diff --check` pass. Commands: `node --check tests/e2e/unhappy-paths.mjs`; dedicated Compose php-tech `php -l tests/e2e/seed-unhappy-paths.php`; `vendor/bin/phpcs --standard=phpcs.xml tests/e2e/seed-unhappy-paths.php`. Application-suite receipts remain historical because application source is unchanged; no fresh `composer test` claim.
-135 tracked application/configuration files and13 unrelated skeleton files match preflight byte hashes. No dependency/migration/product change. Checkpoint remains0600; scoped source/ledger/report contain none of its password/token/cookie values.
- Only three scoped files: `tests/e2e/unhappy-paths.mjs`, `tests/e2e/seed-unhappy-paths.php`, this ledger. No commit, push or deployment.
- Exact source hashes: runner `7924547c46d6744ad6651233d853a438b53628821b7e9894eedccbb379b4d718`; helper `039901a210790a4fa699095404d97708d0a9b984b12f948d94d04f2d289aaedb`. Per-attempt runner snapshots/hashes remain retained; later harness repairs do not rewrite prior evidence.
- Independent case/scope review accepted the three-file audit boundary and preserved historical mappings. Its assertion gaps were addressed by strengthened case checks and the read-only persisted receipt. Independent visual review accepted all artifacts while retaining HF01/HF02. Competitor-only denials are explicitly HTTP/persistence evidence; browser races do not establish SQL lock waits.
- Restored online/unthrottled network, disabled Fetch interception, default media and cleared device emulation. Native measurement width1712/outer1712/DPR2/scale1/CSSzoom1 matches preflight100%. Receipt: `browser-restoration.json`. TaskSpace15 remains open until final evidence review signs off, then is finished once.

### Closure acknowledgement

Independent reviewer approved audit closure with no blocking evidence gap; product acceptance remains failed for HF01/HF02. TaskSpace15 was restored and finished exactly once at `2026-10-18T03:17:21.778Z`; receipt `taskspace-finished.json`. All original attempts and additive synthetic records remain retained. Final scoped file hashes are in `final-manifest.json`. No commit, push or deployment.
