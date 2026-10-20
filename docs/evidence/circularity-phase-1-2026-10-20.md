# Circularity Phase 1 — local acceptance

**Result:** Phase 1 complete locally on 2026-10-20. Phase 2 is not started. No measurement tables, capture screens, summaries or circularity score were introduced.

Implementation started from `6666118` in `/Users/stev/.codex/worktrees/circularity-phase1/vmod_wesria`; the verified 19-file implementation was transferred by SHA-256 manifest to `/Users/stev/htdoc/vmod_wesria`, branch `codex/circularity-phase1`. Documentation was then updated in the served checkout. Git commit, merge to `main` and push were separately authorized on 2026-10-20 after local acceptance. No remote deployment is included.

## Delivered boundary

- `Vmod.Circularity` has no plugin dependencies. Its sole product endpoint is protected `GET /api/circularity/v1/health`.
- `Vmod.CircularityUi` depends only on Core. Its concrete HTTP client derives company, allowed/selected branches, frontend actor and permission context immediately before dispatch. Browser context and cookies are not forwarded.
- Provider authentication uses a SHA-256 credential hash and binds requests to the configured source namespace. Scoped middleware validates authority and hides unauthorized branch lookups with 404.
- The client uses the installed Laravel HTTP client, two-second connect/ten-second total limits, no redirects or automatic retries, safe status handling and native October field errors. HTTPS is required except explicitly enabled local/testing HTTP to `nginx-tech`.
- Core's append-only `vmod.core.extendPermissions` event adds `circularity.read` and `circularity.write`. Malformed event contributions fail explicitly; duplicates are removed.
- The connector's migration freezes the preceding 25 permission keys, locks each company and records each changed role in the same transaction. It does not change membership or branch grants.

No new package, interface layer, persistent service or database was added. Authorization revocation applies to subsequent dispatches; there is no distributed transaction or cancellation guarantee for requests already in flight.

## Owned runtime

Runtime authority remains `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`, project `foodywesria`. PHP is 8.2.34; development uses `mysql-dev/vmod_wesria_dev`, tests use the existing guarded `mysql-test/vmod_wesria_test` service.

Three external configuration files changed:

| File under the runtime directory | Change |
| --- | --- |
| `docker-compose.yml` | Add the private circularity env file, FPM pool mount and internal listener 9001 to `php-tech` |
| `php/fpm.d/zz-circularity.conf` | Add the `circularity` pool, two ondemand workers on 9001 |
| `nginx/default.conf` | Route `/api/circularity/v1/` to 9001, executing the native `index.php` |

The existing UI pool remains on 9000 with its five-worker configuration. Both pools deliberately use `clear_env = no` in the same application trust boundary; the separate pool prevents synchronous worker starvation, not secret access between pools. No host port was added.

New private `wesria-circularity.env` is mode 0600 and contains the client token, provider token hash, stable namespace `wesria`, internal base URL and explicit local HTTP allowance. Existing private environment files were preserved. Do not print or commit this file.

Activation used native `artisan down`, then FPM's verified `SIGQUIT` stop signal with no forced-kill timeout. After old requests drained, a private read-only snapshot captured roles, full administrators and membership/branch hashes. Only the derived `storage/cms/manifest.php` was refreshed; native `october:migrate` installed both plugins at 1.0.1. The app reopened after migration invariants and live configuration checks passed.

| Local migration assertion | Result |
| --- | --- |
| Existing full-administrator memberships | All 33 preserved |
| Roles checked | 67 |
| Eligible roles granted both new keys | 30 |
| Matching system audit records | 30 |
| Other permissions, memberships and branch grants | Preserved |

The browser fixtures were created through the existing guarded identity seed before activation, so browser acceptance exercised an administrator upgraded by this migration. Synthetic fixture records remain in the owned local database.

## Verification

| Check | Result |
| --- | --- |
| Guarded aggregate Vmod suite | 82 tests, 1,071 assertions passed |
| Core MySQL concurrency | Five synchronized independent-process scenarios passed |
| Database safety guard | Eleven unsafe targets rejected before application boot |
| Separate framework suite | 425 tests, 2,287 assertions; four existing SQLite-only skips |
| Scoped PHP coding standard | Passed for both new plugins and Core permission registry |
| Shell/Node syntax and `git diff --check` | Passed |
| Disposable actual HTTP | One UI FPM worker → Nginx → one API FPM worker passed |
| Provider-only boot | Only Circularity discovered; Core, ERP and UI connector classes unavailable |
| HTTP authority checks | Missing/invalid bearer 401; missing context 422; trusted namespace preserved; foreign branch 404; no health session cookie |
| Live native HTTP | Client health succeeded through configured Nginx/API pool; missing/invalid bearer rejected |
| Egolite | Six scenarios passed; five screenshots inspected; zero JavaScript runtime errors |
| Independent review | No unresolved correctness findings |

The provider-only harness isolates plugin/cache/storage paths **and** replaces October's default `plugins` autoload fallback. Changing `PLUGINS_PATH` alone isolates discovery but leaves classes from the original plugin directory loadable. October creates the API worker's complete cache/storage tree inside a writable temporary parent.

Checks caught and resolved a client URL validation case accepting username-only URL credentials, the isolated harness's missing native cache tree/autoload fallback, and alignment of remote field errors with October's validation exception. Temporary debug output was removed. The harness has test-only scope/isolation routes; these are absent from the product routes.

Browser TaskSpace **14** was finished after inspection. Evidence is in `output/ego-lite/circularity-phase-1/report.json` and five PNGs at 1440×900 / 390×844. Checks cover native sign-in/workspace selection, upgraded administrator role save/reload, unchanged delegated reader, ERP purchasing/inventory navigation, browser-session API denial and mobile workspace/catalogue navigation. This is a regression smoke check, not Phase 2 measurement acceptance.

## Repeat commands

From the served checkout:

```sh
wesria_compose=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
"${wesria_compose[@]}" --profile test up -d --wait mysql-test
"${wesria_compose[@]}" --profile test run --rm -T \
  -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/var/www/html \
  php-test composer test
"${wesria_compose[@]}" --profile test run --rm -T php-test \
  php vendor/bin/phpcs --standard=phpcs.xml plugins/vmod/circularity plugins/vmod/circularityui \
  plugins/vmod/core/classes/Permissions.php --extensions=php
bash tests/vmod/check-circularity-http.sh
```

Run the HTTP harness serially with database tests. It creates temporary PHP/Nginx proof containers on the existing private test network and removes its own containers on exit. On failure it retains private diagnostic files under `/private/tmp/wesria-circularity-http.*`; do not publish credential files. It does not alter development services or data. An isolated checkout can set `VMOD_DEPENDENCY_ROOT` to the served checkout to reuse installed dependencies.

For a new independent browser run, create fresh synthetic fixtures using `tests/e2e/seed-phase2.php`, write output privately to `/private/tmp/wesria-circularity-phase1-acceptance.json`, then run `ego-browser nodejs < tests/e2e/circularity-phase1.mjs`. Resume the same TaskSpace on failure; finish it once after screenshot inspection. The original pre-activation administrator proof is the recorded run above.

## Rollback and next boundary

Rollback requires the same maintenance/drain window before reverting the scoped application/runtime files. Preserve permission grants, audit records and plugin migration history; the migration's `down()` intentionally removes nothing. With the connector absent, its permission keys are unregistered and grant no access.

Before reactivation, check administrator eligibility under the expanded registry. A completed migration is not rerun automatically; never regrant permissions that an administrator deliberately removed while the plugin was inactive. Resolve such access decisions before reopening.

The isolated plugin boot and HTTP checks support the future API extraction boundary. Independent-host operation, remote TLS/credential provisioning and production qualification remain later work. Phase 2 requires separate authorization.
