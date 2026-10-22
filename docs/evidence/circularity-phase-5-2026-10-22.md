# Circularity Phase 5 — independent provider extraction

**Accepted locally on 2026-10-22.** Baseline `7760e5afab1cdbbe6e3363f80e7f7817d26df619`; isolated implementation at `/Users/stev/.codex/worktrees/circularity-phase5/vmod_wesria`. The seven scoped files are delivered to `/Users/stev/htdoc/vmod_wesria` with matching SHA-256 hashes; the tested worktree is preserved. No commit, push, cutover or deployment was performed.

## Packaging and runtime contract

The rehearsal is `VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria bash tests/vmod/check-circularity-http.sh --extracted`. Run serially with other Wesria test processes stopped and the owned `mysql-test` running. Ordinary standalone and `--browser` modes remain available. The Phase 5 runner is `ego-browser nodejs < tests/e2e/circularity-phase5.mjs`; it reads the private runtime pointer at `/private/tmp/wesria-circularity-phase5-runtime.json`. Never publish the fixture or environment files.

The target package explicitly copies native `index.php`, `artisan`, Composer manifests, bootstrap, config, framework modules, and only Circularity's production files. It mounts the existing locked `vendor` read-only. Core, ERP, CircularityUi, RainLab.User's plugin tree, the theme, `.env`, `.git`, and source storage/caches are absent. This is an artifact rehearsal with installed dependencies, not a clean Composer installation from a provider-specific distribution manifest. No dependency is added or upgraded.

PHP uses the cached owned PHP 8.2.34 image and MySQL uses the cached owned 8.0.46 image. A fresh tmpfs MySQL container and unique internal network use the network-local `mysql-test` alias, database/user `vmod_wesria_test`, and owner `vmod_wesria_phase1`. The existing `DatabaseGuard.php` is copied unchanged. CLI checks validate resolved configuration and physical server identity before migration/import; FPM checks the environment before native boot. Source and target server UUIDs must differ. Target PHP is connected only to its target network; Nginx alone bridges the source and target networks. Only the browser listener is published, on an ephemeral `127.0.0.1` port. The target API listener on port 8081 is unpublished.

October deliberately suppresses automatic plugin registration under `APP_ENV=testing`. The generated, target-only `CircularityRehearsalProvider` invokes the native plugin register/boot lifecycle through `bootstrap/providers.php`. It requires the testing environment and extraction marker. Production `index.php`, plugin files, and the database guard are unchanged. Without this test bootstrap, native HTTP falls through to CMS and fails on the intentionally absent theme; this was observed before correction. Mounted cache/storage roots require October's native subdirectory tree to be initialized explicitly. FPM owns those fresh writable directories; source and target storage remain separate.

The UI retains its private file sessions/cache, unique cookie, native MySQL session locking and Core authorization. Its unchanged connector uses `http://nginx-tech:8081/api/circularity/v1`, permitted by the existing local/testing exception. The target uses its own application key and database password, a SHA-256 service-token hash, preserved source namespace, reporting timezone, and array sessions/cache. Source/UI credentials and storage are not mounted into the target. TLS is required outside this local exception; this rehearsal does not qualify public HTTPS, DNS, hosting, backups, operational monitoring or license/security readiness.

## Transfer and failure behavior

The guarded CLI helper is deliberately fixed to `vmod_circularity_areas`, `vmod_circularity_entries`, and only `Vmod.Circularity` rows in `system_plugin_versions` / `system_plugin_history`. It is a disposable qualification tool, not a general or production importer.

Fresh synthetic users/courts use the existing bootstrap service. API-created fixtures cover all five types, separate daily and individual groups, edited versions, soft removal, inactive areas, nullable fields and Unicode text. A private current-run manifest pins the scopes, area IDs and UUIDs. A source write barrier and subsequent read drain its single provider worker before one read-only repeatable-read transaction exports raw rows. No namespace-wide selector infers fixture ownership.

After native target migrations, import requires empty circularity tables and no other plugin migration metadata. It validates the fixed table/column/identity/scope manifest and schema definitions, then inserts areas before entries and replaces only the fresh target's Circularity migration metadata in one transaction. Foreign keys stay enabled. IDs, UUIDs, decimal strings, opaque references, actor references, versions, timestamps, nulls, Unicode and deleted rows compare exactly. Schema/index definitions compare excluding auto-increment counters; subsequent target inserts must receive IDs above imported maxima. A second native migration must leave provider data/history unchanged.

Wrong schema/server targets, foreign snapshot tables/scopes, an interrupted transaction, and a nonempty target are rejected. Any failed or uncertain import requires recreating only this owned disposable target. Source data is retained. Source circularity rows and provider metadata are compared in full before and after target/browser activity. Target assertions execute inside target CLI; source browser controls are limited to synthetic workspace authorization.

Both standalone PHP harnesses require a completion sentinel to exit zero: October's console exception renderer otherwise returned zero for an observed failure. Failure artifacts stay private; successful runtime cleanup removes only the run's own containers/network/files. Synthetic source fixtures remain in disposable MySQL.

## Acceptance and evidence

All database checks ran serially. The source and UI used the existing owned disposable `mysql-test`; the extracted provider used a fresh physical MySQL server. Both reported schema `vmod_wesria_test`, account `vmod_wesria_test@%` and MySQL **8.0.46**:

| Identity | Value |
| --- | --- |
| Source server UUID | `87dd47ec-cd73-11f1-bc6f-820c49d083d8` |
| Target server UUID | `8cb727e7-cdba-11f1-ac13-92c51da422ef` |
| Target PHP image | `sha256:5ade0667a0ae5d7d555f5b0ea237094381bf6fc55d8ef31430f4848d37fb9866` |
| Target MySQL image | `sha256:b7e118f56c5963e079252e0d6e2978c9c010eb7fc7aaaec100e367796b5dd08f` |
| Browser origin | `http://127.0.0.1:58974` |
| Unchanged database guard SHA-256 | `c1849278c4206f54aa646ae9dca2d18e8f8484da7f2e094a211c7ccf6fde1b99` |

[Runtime mounts/networks](../../output/ego-lite/circularity-phase-5/target-runtime.json), [runtime/guard checks](../../output/ego-lite/circularity-phase-5/runtime-checks.json), [source manifest](../../output/ego-lite/circularity-phase-5/source-manifest.json) and [success logs](../../output/ego-lite/circularity-phase-5/logs) are local ignored artifacts. Credentials and raw snapshots remain private. No matching Wesria code graph was available; implementation, callers and tests were traced directly. After target packaging, one PHPCS-only line wrap changed the helper; final PHP syntax/PHPCS passed. The manifest identifies the final delivered files; production source remained unchanged throughout.

| Check | Observed result |
| --- | --- |
| Provider / connector PHPUnit | **14 / 641** and **15 / 148** tests/assertions; **29 / 789** total, pass |
| Ordinary standalone HTTP | Exit **0** after final harness edits; native provider boot/auth, statelessness, scope, CRUD, summaries, replay, stale writes and removal pass |
| Independent target safety | **2 unsafe targets** rejected before migrations: wrong schema and wrong physical server |
| Import | **3 areas, 34 entries, 1 version row, 3 history rows** preserved exactly; **33 active** imported entries |
| Transfer negative cases | **4** pass: foreign table, foreign scope, interrupted transaction rollback, nonempty target |
| Package/migrations | Only **Vmod.Circularity** loaded; **43 native/provider tables**; schema/index parity and second-migration no-op pass |
| Target native HTTP | Inactive-area history paginates **25 + 1** active rows; exact-date total **53.125**; new IDs exceed imported maxima; CRUD, replay, stale versions, removed UUID, read-only grants and foreign scope checks pass |
| Egolite TaskSpace **22** | **4 groups**, **5 actual BFCache returns**, **16 visually inspected screenshots**, **0 JavaScript exceptions** |
| Source preservation / closure | Full source provider rows and migration metadata unchanged; extracted harness exit **0**; owned temporary containers/network removed |
| Scoped checks | PHP syntax/PHPCS, generated native bootstrap syntax, Bash syntax, Node syntax and whitespace pass |
| Aggregate regression | Exit **0**: **11 unsafe targets** rejected; Vmod **100 tests / 1,767 assertions**, Core **5 concurrency scenarios**, framework **425 tests / 2,287 assertions**, **4 existing SQLite skips** |
| Independent whole-change review | No actionable findings |

[Transfer receipt](../../output/ego-lite/circularity-phase-5/transfer-evidence.json) records raw-row SHA-256 `a18ef1949f9ddc93998b83a4d68e2e89b8f01b24ab0bf0915cfe0527af04c8d8`. Import comparisons include raw deleted rows and all fields, not only API representations. [Browser report](../../output/ego-lite/circularity-phase-5/report.json) records native capture → reload → edit → remove, daily conflict with retained inputs, independent individual readings totaling **3.125**, and exact overview/list reconciliation: **12.125 food**, **0.000 packaging**, **3.250 mixed**, **8 issued**, **12 returned**. Composting applies uniformly; reusable counts become missing. Unknown destinations, empty dates and unauthorized scopes are explicit.

Actual Back/Forward navigation reauthorized unchanged access, revoked write/read grants, reduced court grants and workspace changes. Each restored document stayed concealed and was replaced; unsaved edits were discarded. Stopping the actual target container produced the unavailable state; restarting restored reads. Keyboard submission/focus and mobile table scrolling pass. All screenshots were inspected at **1440×900** or **390×844** with no page overflow: [desktop overview](../../output/ego-lite/circularity-phase-5/desktop-overview.png), [desktop list](../../output/ego-lite/circularity-phase-5/desktop-list.png), [mobile totals](../../output/ego-lite/circularity-phase-5/mobile-totals.png), [mobile scrolling](../../output/ego-lite/circularity-phase-5/mobile-table-scrolled.png). Mobile all-court food **112.125** correctly includes the second court's **100.000**.

Final aggregate setup required a private synthetic `APP_KEY` and a writable private copy of native CMS theme fixtures. The initial isolated invocation lacked the key (two Core recovery errors and one failure); supplying it passed the **3 affected tests / 12 assertions**. Read-only theme fixtures then caused seven framework errors and one failure; the private copy passed the **33 affected tests / 228 assertions**. No application/test assertion was weakened. Both failed-run logs remain private. The final four skips are the existing SQLite weekly/monthly/quarterly/yearly dashboard aggregation checks; no Circularity gate was skipped.

Seven scoped files:

| File | Responsibility |
| --- | --- |
| `tests/vmod/check-circularity-http.sh` | Adds explicit `--extracted` dispatch, private handoff/network and source storage; preserves ordinary/browser modes and live output |
| `tests/vmod/check-circularity-http.php` | Extraction-only source freeze/export/preservation, authorization-only controls and reliable nonzero failure exit |
| `tests/vmod/check-circularity-extraction.sh` | Fixed allowlisted package, independent MySQL/PHP, native bootstrap, target controls and owned cleanup |
| `tests/vmod/check-circularity-extraction.php` | Guarded fixed transfer, rejection/rollback, raw reconciliation, migration and native HTTP checks |
| `tests/e2e/circularity-phase5.mjs` | Focused resumable browser acceptance against the extracted provider |
| `docs/vmod-wesria-circularity-mvp-plan-2026-10-20.md` | Phase 5 tracker and acceptance |
| `docs/evidence/circularity-phase-5-2026-10-22.md` | Packaging, configuration, commands, results and cutover limits |

## Commands and repeatability

The focused containers ran `php vendor/bin/phpunit -c plugins/vmod/circularity/phpunit.xml --do-not-cache-result` and the equivalent `plugins/vmod/circularityui/phpunit.xml` command using the existing private test environment and read-only source/dependency mounts. No dependency installation was performed.

From the tested worktree, run each runtime serially:

```sh
VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria bash tests/vmod/check-circularity-http.sh
VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria bash tests/vmod/check-circularity-http.sh --extracted
# While extracted READY is held, in another terminal:
ego-browser skill
ego-browser nodejs < tests/e2e/circularity-phase5.mjs
```

Resume the same TaskSpace after a failed check. Inspect every screenshot, then write `pass` to the private proof directory's `complete` file and finish that TaskSpace once with `keep: []`. Wait for source preservation and harness exit zero before another database suite. The accepted TaskSpace was finished once; [closure receipt](../../output/ego-lite/circularity-phase-5/closure.json) records successful cleanup. Failed diagnostics stay private and source synthetic records stay in disposable MySQL.

Scoped commands: `bash -n tests/vmod/check-circularity-http.sh tests/vmod/check-circularity-extraction.sh`, `node --check tests/e2e/circularity-phase5.mjs`, `git diff --check`; PHP 8.2 ran `php -l` on both PHP harnesses and `php vendor/bin/phpcs --standard=phpcs.xml tests/vmod/check-circularity-http.php tests/vmod/check-circularity-extraction.php`.

For the accepted aggregate invocation, `/private/tmp/wesria-circularity-phase5/aggregate.env` held only a fresh synthetic `APP_KEY` (mode 0600); `framework-themes-final` was a fresh copy of `modules/cms/tests/fixtures/themes`. Keep both outside Git:

```sh
docker run --rm --network foodywesria_test \
  --env-file /Users/stev/htdoc/docker_foody_wesria/wesria-test.env \
  --env-file /private/tmp/wesria-circularity-phase5/aggregate.env \
  --tmpfs /var/www/html/storage \
  -v /Users/stev/.codex/worktrees/circularity-phase5/vmod_wesria:/var/www/html:ro \
  -v /Users/stev/htdoc/vmod_wesria/vendor:/var/www/html/vendor:ro \
  -v /Users/stev/htdoc/vmod_wesria/plugins/rainlab/user:/var/www/html/plugins/rainlab/user:ro \
  -v /private/tmp/wesria-circularity-phase5/framework-themes-final:/var/www/html/modules/cms/tests/fixtures/themes \
  -w /var/www/html -e GIT_CONFIG_COUNT=1 \
  -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  foodywesria-php-tech:phase1 sh -c 'mkdir -p storage/framework && composer test'
```

This phase reuses Phase 4's timeout and observed MySQL race qualification; those scenarios were not repeated because no production writer, timeout or history behavior changed. Phase 5's distinct required target-transfer, native HTTP and frontend gates all ran. This does not qualify a clean Composer distribution build, public hosting/TLS, production cutover, physical devices or deployment. No business/user-flow change was needed.

## Future cutover and rollback notes

A real cutover needs separate authorization and an operational destination, credentials, TLS, backup and restore proof. Pause source writes, drain in-flight mutations, back up source data plus provider migration state, transfer the complete intended scope into the empty target, reconcile raw identities/counts/current values/deleted records/summaries, verify target reads and permissions, change the connector URL and server-held credential, then reopen writes. Preserve the configured source namespace and opaque tenant/court/user references.

Before target writes are accepted, a failed rehearsal/cutover can retain the source URL and leave source data intact. After target writes begin, switching back to a stale source loses those changes: pause/drain again, reconcile all target changes including edits and removals into the rollback dataset, verify it, then switch. Never treat an HTTP timeout as rollback, automatically retry a mutation, or restore only active rows.
