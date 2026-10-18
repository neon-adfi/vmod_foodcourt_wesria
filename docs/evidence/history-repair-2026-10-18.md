# H48/H49 repair and verified release

Baseline: `40a693aaac1e39b7170ae6827decd0a89dcfa9d2`, working branch `codex/hybrid-unhappy-audit`, served checkout `/Users/stev/htdoc/vmod_wesria`. User approved these two flow repairs and scoped commit/merge/push after acceptance. No deployment, dependency or migration change. Original [50-journey audit](hybrid-unhappy-paths-2026-10-18.md) retains its original H48/H49 failures and historical attempts.

## Implementation and boundary

- Identity's native read-only `identity::onCheckContext` handler calls `Workspace::assertContext()` and returns a validation marker. Native CSRF runs first; no membership/context refresh or business/operation/audit writes.
- Authenticated application pages carry their existing context. Persisted `pagehide` conceals and disables protected content. Restored pages reveal it only after native validation; same-context values/focus survive. Changed/expired contexts reload through normal authorization. Failed verification remains concealed with keyboard-accessible retry/sign-in recovery.
- October's supported **redirect and reload** response hooks validate the submitted context after a successful save. Workspace/identity actions are excluded. A changed workspace retains inputs and shows the approved completed-save message with new-tab workspace/record links. Failed verification says the save completed but the current context is unverified. No automatic replay, key replacement or workspace switching.
- Protected print history hides the body and replaces the document-bearing title with `Protected document` while cached, then reloads for authorization. Saved historical print content and existing private/no-store controls remain intact.

Four product files, one focused existing test file, the existing audit runner/helper and two evidence ledgers are the entire release scope: nine files. No new framework, dependency, route or schema. The graph inventory contained no matching Wesria index; direct implementation/caller/framework/test tracing was used. Existing authorization and transaction contracts are unchanged.

## Runtime and execution

New egolite **TaskSpace16**, separate private checkpoint `storage/app/acceptance-private/wesria-history-repair.json` (0600), separate output `output/ego-lite/history-repair/063331dc35/`. Existing campaign-owned A25/B26/C27 records are reused with additive UI-created repair documents; unrelated human records and 13 plugin-skeleton files are preserved. Company-switch variants use the existing cross-company B actor, not a new grant.

Dedicated Compose authority: `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`, project `foodywesria`; served mount reverified to this checkout. Development `mysql-dev/vmod_wesria_dev`, owned test `mysql-test/vmod_wesria_test`, PHP8.2.34/MySQL8.0.46. No schema reset or shared-service changes. Native200 remains explicitly waived.

Runner invocation: `ego-browser nodejs < tests/e2e/unhappy-paths.mjs`, private options `historyRepair:true, rerun:true` and selected IDs. Each attempt retains its own runner/application hash, screenshot/PDF hashes, assertions, created IDs and reconciliation. Original audit mappings and reports remain unchanged. H51–H56 are repair-specific subcases, not additions to the completed 50-journey audit.

For BFCache proof the runner installs a one-shot, source-page wrapper that holds only the context-check fetch, requires real `pageshow.persisted`, inspects concealment, then releases the native request or rejects it as a controlled transport fault. CDP interception activated after caching invalidated the cache, so that earlier harness attempt is retained but not accepted as proof. Print tests record the actual cache lifecycle separately from fresh authorized navigation; final observations are listed in the closure receipt.

## Test and review evidence

Handler TDD: explicit missing-handler assertion failed, then passed (1 test/7 assertions). Existing application test asserts success, unchanged token/membership/audit count, stale409 and unauthenticated401. Real HTTP covers native419 precedence and successful response metadata.

Commands in the owned `php-test` service:

- `composer test` with Git safe.directory scoped to `/var/www/html`: **71 application tests/976 assertions**, five synchronized Core independent-process concurrency scenarios; **425 framework tests/2287 assertions**, four existing SQLite date-aggregation skips. These skips are framework limitations, not claimed MySQL failures. No new SQL lock-wait claim is made for browser races.
- `php -l` and `vendor/bin/phpcs --standard=phpcs.xml` for Identity, WorkspaceAccessTest and the guarded helper: pass.
- `node --check tests/e2e/unhappy-paths.mjs`, module syntax check of app.js, `git diff --check`: pass.

Exact logs and source hashes are retained under `verification/` and `verified-source.json` in the repair output. Full suite was repeated after the reviewed reload/print repairs. Earlier attempts remain available.

Independent review caught a missing native **reload** response hook (edits reproduced the original404) and a cached print title containing the document number. Both were repaired in the existing files. Harness-only issues were stale overview context, native `__ajax` response metadata, navigation timing, and attempting a company switch with an account lacking that membership. These are distinguished from product failures.

Final acceptance is recorded below; original failed attempts remain retained.

## Final browser acceptance

All **15 selected cases pass** against the same frozen application, helper and runner hashes. The other 41 repair-report slots are intentionally not rerun; the original 50-case audit remains the authoritative record for untouched journeys.

| Cases | Verified outcome |
|---|---|
| H48 | Real cached application restoration remains concealed before validation; A→B history reload and fresh request deny A document content; B recovery works. |
| H49 | Delayed create/edit across both branch and company changes retain inputs, focus recovery feedback, release controls, preserve the active workspace and one operation effect; stale follow-up409 changes nothing. |
| H51–H52 | Same-context unsaved values/focus survive actual BFCache; failed verification hides content, shows retry/sign-in and recovers without writes. |
| H53, H56 | Workspace change, logout/session expiry and invalid CSRF recover through fresh authorization; real native handler returns200/409/419/401 as appropriate and no business effects. |
| H54 | Committed save with failed context verification is explicitly completed/unverified, not unknown; original saved record is found once. |
| H55 | Historical values/title remain stable; Back under B discloses neither the document nor its title. This browser reported **persisted=false**, so the print case proves fresh reauthorization. The persisted=true print conceal/reload branch is source-reviewed, not browser-exercised. Protected print returns200 with `max-age=0, no-store, private`. |
| H02, H03, H05 | Session recovery, stale forms and native CSRF rejection still work; entered values and rejected-state integrity are checked. |
| H06, H07, H10 | Lost GRN/DO responses retain one-effect replay; known-unsent versus unknown transport recovery remains distinct. |
| H50 | Native completed response reaches the saved catalogue record after history validation; visible search finds exactly one row. No automatic mutation retry. Earlier inspections raced this navigation and remain recorded as harness failures. |

Final read-only reconciliation: **23 balances,67 movements,21 reservation records; zero discrepancies**. Snapshot hash, exact counts and runtime identity: `final-reconciliation.json`. Browser restoration receipt confirms width1712/outer1712/DPR2/visual scale1/CSS zoom1 (native100%), online/unthrottled, Fetch disabled, media/device emulation cleared, and zero temporary print-probe keys. Native200 waived. Independent final review approved closure; the completion receipt is recorded below.

## Exact release manifest

The following seven source files plus this ledger and `docs/evidence/hybrid-unhappy-paths-2026-10-18.md` are the **nine-file** release manifest. Private checkpoints, cookies, credentials, ignored runtime artifacts and all13 unrelated `docs/plugin-skeletons/` files are excluded. Skeleton byte hashes match original preflight. Known synthetic secret values are absent from all nine files; checkpoint remains0600.

| Source path | SHA256 |
|---|---|
| `plugins/vmod/core/components/Identity.php` | `a8bfa22db652d9053ad7854620a54f6d41a7becebb36793462a6ea83edd4251b` |
| `plugins/vmod/core/tests/WorkspaceAccessTest.php` | `01a1dff5f9a4917035d2836df04326758ea9936f43e302c932e524ca2de746b4` |
| `themes/vmod/layouts/default.htm` | `f4933dfac46a6dde458846f94bcbd581367f1f2d32c49f4050e9d34f5c41f89d` |
| `themes/vmod/layouts/print.htm` | `a2139c9a2c94aa4a8671e985d2468d3c0a95615849f7e1f95a13add7ba9bcfd2` |
| `themes/vmod/assets/app.js` | `06b788dad5c3348bff49b70ef7fcbb7b62fbbfe89d55ae422cc5dd3d46fbb632` |
| `tests/e2e/unhappy-paths.mjs` | `4644064600cfc4d95fa08c62b3f4f52dced00bd11b6d3d348729b74f61d991f6` |
| `tests/e2e/seed-unhappy-paths.php` | `039901a210790a4fa699095404d97708d0a9b984b12f948d94d04f2d289aaedb` |

## Independent acceptance review

Source reviewer accepted the exact seven source hashes after repairs; receipt `inspection/source-review.json`, SHA256 `f09c712be8dd9c7fb8ef976c821188b98dee45746051bef737053a72d61cc22d`. No open confirmed product defect remains in this scope. The persisted=true print limitation is explicit above.

Independent visual review inspected **187 artifact references /81 unique hashes**, including **all5 PDFs /5 pages**, with zero uncovered references. Receipt `inspection/visual-review-final.json`, SHA256 `fa5cc6c1dee40bf9ff1b98ab47d6ef7e5744af984ce485629225dd0668c8032c`. This hash-bound receipt qualifies the runner's initially uninspected artifact entries, including each final rerun; historical failure captures remain classified separately. Minor transient toast overlap and empty native error strips remain optional prior-audit polish, outside these two repairs.

Final report SHA256: `d12ce85ad54e7b22e53ea9166cc04247980cb4d4441e5a5d4a0b2a723058b240`. Original audit H48/H49 findings remain visible; this separate repair ledger records their resolution.

## Closure and release gate

Independent final review confirmed no remaining mandatory evidence gap or open confirmed product defect. TaskSpace16 was restored and finished **exactly once** at `2026-10-18T07:47:04.776Z`; receipt `taskspace-finished.json`. H48/H49 are resolved within the approved scope. All additive campaign records and original evidence remain retained.

Remote `main`, local `main` and working-branch base were rechecked at `40a693aaac1e39b7170ae6827decd0a89dcfa9d2` before release. The exact nine-file hashes, staged/committed tree checks and remote commit identity are recorded in ignored local `release-manifest.json` / `release-receipt.json`. Commit delivery does not deploy the application. No deployment is included.
