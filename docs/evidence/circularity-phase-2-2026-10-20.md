# Circularity Phase 2 — local capture acceptance

**Result:** Complete locally on 2026-10-20. Management can manage areas and capture, edit and remove measurements through the existing frontend and the real HTTP API. Phase 3 remains unstarted. No remote deployment was performed. Git commit, merge and push were separately authorized after acceptance.

Baseline: clean `main` at `a42add5033dd55c0860d32caa09c8e181e5537bb`. Implementation used `/Users/stev/.codex/worktrees/circularity-phase2/vmod_wesria`; the scoped changes were copied to `/Users/stev/htdoc/vmod_wesria` for local acceptance. Both checkouts retained uncommitted Phase 2 changes at the acceptance gate; Git release was subsequently authorized.

## Delivered

- Two provider-owned tables, native models, one explicit entry mutation service and versioned `/api/circularity/v1` options/areas/entries endpoints. The provider imports no Core/ERP models and has no cross-plugin foreign keys.
- Existing HTTP connector extended with response validation, escaped native field errors and allowlisted conflict information. Links are constructed locally. Existing authentication, workspace checks, timeouts and no-cookie/no-redirect/no-retry behavior remain.
- Native October/Tabler `/circularity/entries` and `/circularity/areas`, permission-controlled navigation, pagination and filters. Read-only users can browse but cannot mutate.
- Daily totals by default, optional individual entries, one active mode per group, one daily total per destination, separate mixed waste and whole reusable counts. Explicit zero is preserved.
- Same-court corrections, immutable entry UUID/court, versions, retained removed identities, area reactivation and historical corrections within inactive areas.
- UUID recovery preserves entered values and displayed version. Checking an unknown outcome never silently loads another record or advances the edit version; a failed lookup remains uncertain.

Dates use the configured reporting timezone, initially `Asia/Kuala_Lumpur`. Quantities cross the API as strings and persist as `DECIMAL(15,3)`. Parent-area locks serialize manual capture; moves lock areas in ascending ID order. No group table, replay ledger, new dependency or persistent service was introduced.

## Runtime and data preservation

The runtime remains the owned `foodywesria` Compose project at `/Users/stev/htdoc/docker_foody_wesria`, with PHP 8.2.34 and MySQL 8.0.46. Disposable verification preceded development activation.

Local activation entered maintenance, gracefully stopped `php-tech`, copied an exact file manifest and applied provider migration **1.0.2** to `mysql-dev/vmod_wesria_dev`. All **32 pre-existing Core/ERP table row counts** were unchanged by the migration. The connector remains at 1.0.1 because it has no new migration. Existing schemas, runtime configuration, credentials and grants were preserved. Synthetic acceptance companies and measurement records remain locally.

The migration rollback deliberately retains the new tables. After capture begins, rollback must disable the new UI/routes and preserve all areas, entries, UUIDs, versions and removed rows; do not drop captured history.

## Verification

| Check | Observed result |
| --- | --- |
| Final aggregate Vmod suite | **93 tests / 1,435 assertions passed** |
| Database safety guard | **11 unsafe targets rejected** before application boot |
| Adjacent Core MySQL concurrency | **5 synchronized scenarios passed** |
| Separate framework suite | **425 tests / 2,287 assertions; 4 existing SQLite skips** |
| Focused provider suite after review fixes | **10 tests / 356 assertions passed** |
| Circularity MySQL concurrency | **7 exact lock waits observed**, outcomes and persisted data reconciled |
| Actual HTTP | Nested UI → API with one worker each; options, area CRUD, entry CRUD, validation, replay, stale writes and removed-entry isolation passed |
| Provider-only boot | Core, ERP and UI connector classes unavailable; provider still serves its API |
| Egolite | **13 scenario groups, 10 inspected screenshots, zero JavaScript runtime errors** |
| Scoped PHPCS, Node syntax, whitespace | Passed |
| Fresh independent review | Two Important findings reproduced and fixed; no unresolved findings or deferred minors |

The seven circularity races cover daily duplicates, competing modes, different-area UUID collisions, matching UUID replays, opposing area moves, deactivation before creation and creation before deactivation. The check observes the actual waiter/blocker connections through `performance_schema.data_lock_waits`, then reconciles identities, modes, moves and retained inactive-area history. These are disposable MySQL checks, not SQLite or browser locking claims.

Browser TaskSpace **15** was reused throughout acceptance and finished after screenshot inspection. Checks cover save → reload → edit → remove, both modes/remove-before-switch, mixed waste, clean daily auto-open versus dirty-form preservation, fixed edit UUID, read-only controls/server denial, stale workspace, invalid fields/filters, reusable counts, packaging, uncertain completed saves, failed lookups and HTTP 500/502/504 recovery. Desktop is 1440×900; mobile is 390×844, with no horizontal overflow.

Local browser evidence is `/Users/stev/htdoc/vmod_wesria/output/ego-lite/circularity-phase-2/report.json` and its ten PNGs. The initial conflict/uncertain screenshots predate a cosmetic empty-validation-banner fix; the refreshed desktop/mobile forms and final gateway screenshot show the corrected behavior. Private test logs are under `/private/tmp/wesria-circularity-phase2/` (`aggregate-final.log`, `concurrency-final.log`, `http-reviewed.log`, `lint-reviewed.log`, `scope-red.log`, `scope-green.log`, `browser-gateway-red.log`, `browser-gateway-green.log`). Credentials are only in the separate mode-0600 fixture/environment files.

## Review fixes and technical decisions

1. **Exact scope identity:** MySQL `utf8mb4_bin` is case-sensitive but ignores trailing spaces. A disposable regression first reproduced an unauthorized alias returning 200. The boundary now rejects namespace/company/branch references with leading/trailing whitespace; it never trims authority. The regression now passes for reads, writes and removed identities. Existing canonical workspace references are unchanged, and the already-applied schema needed no alteration. A future adapter with whitespace-bearing identifiers must provide canonical identifiers or explicitly revise this contract.
2. **Unknown gateway outcomes:** The browser regression first showed that HTTP 504 left mutation controls available. All HTTP 5xx mutation failures now require **Check saved entry**, retaining UUID, values and displayed version. HTTP 500/502/504 and failed-check cases pass.
3. **Native validation:** Live acceptance exposed October's normal CMS renderer converting provider validation into HTML 500. A native render callback scoped to the circularity API now returns JSON 422; a regression exercises the normal renderer. Remote validation text is escaped before native October inserts it into the page.
4. **Default mode:** Changing from an individual-entry group to a different empty group resets to Daily total; an explicit choice within the same empty group remains intact. The browser regression failed before the narrow fix and passed afterward.
5. **Phase boundaries:** Summary/reporting/score, treatment verification, issued-versus-returned stock matching, independent-host qualification and production deployment remain outside Phase 2. Per-area serialization is intentional for manual capture; higher-volume ingestion would need a separately justified locking change.

No matching current target graph was available, so impact analysis used source, callers, interfaces and tests directly. The execution ledger/review packet stayed private to avoid adding process scaffolding. The user's no-commit instruction overrides the execution skill's usual commit/cleanup steps; uncommitted work and evidence were retained.

## Repeat checks

Run these serially from the served checkout against the owned disposable service:

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" --profile test up -d --wait mysql-test
"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test
"${wesria_compose[@]}" exec -T mysql-test sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"' |
  "${wesria_compose[@]}" --profile test run --rm -T php-test php tests/vmod/check-circularity-concurrency.php
bash tests/vmod/check-circularity-http.sh
"${wesria_compose[@]}" --profile test run --rm -T php-test php vendor/bin/phpcs \
  --standard=phpcs.xml plugins/vmod/circularity plugins/vmod/circularityui \
  tests/vmod/check-circularity-concurrency.php tests/vmod/check-circularity-http.php \
  tests/e2e/seed-circularity-phase2.php --extensions=php
node --check themes/vmod/assets/app.js
node --check tests/e2e/circularity-phase2.mjs
git diff --check
```

The observer credential is piped directly to the guarded harness, never printed. This execution used a private Compose override mounting the implementation worktree and existing dependencies; the HTTP harness used `VMOD_DEPENDENCY_ROOT=/Users/stev/htdoc/vmod_wesria`. No database guard was broadened.

For a fresh independent browser acceptance, use `tests/e2e/seed-circularity-phase2.php` once through the owned local `php-tech` service. It reuses the guarded additive identity seed and changes only its new synthetic reader role. Save its output privately at `/private/tmp/wesria-circularity-phase2-acceptance.json` with mode 0600, then run `ego-browser nodejs < tests/e2e/circularity-phase2.mjs`. Archive the preceding private fixture/report first; never overwrite a running acceptance or reclaim finished TaskSpace 15. During an interrupted run, resume the fixture's existing space and scenario ledger. Inspect the screenshots and finish that same space once.

Local automated acceptance does not establish human operational acceptance or independent-host readiness. The four framework skips are unchanged SQLite cases; all circularity persistence/concurrency checks above used MySQL. No Phase 3 endpoint or calculations were added.
