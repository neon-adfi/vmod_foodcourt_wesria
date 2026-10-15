# Phase 1 implementation evidence — 2026-10-15

**Outcome: complete for local Phase 1.** The authorized minimal repairs and mandatory acceptance now pass. No Phase 2 implementation, commit, push or deployment was performed. Next: **Phase 2 — Core identity and workspaces using Tabler**.

## Fresh repair acceptance — 2026-10-15

The results in this section were executed after the repairs; the earlier blocked run is retained below as historical setup evidence, not substituted for acceptance. Raw logs for this repair run are in `/tmp/wesria-phase1-repair/`. Sanitized package versions, browser results, screenshots and hashes are in [machine-readable results](phase-1-results-2026-10-15.json).

### Provenance and preservation

- Checkout `/Users/stev/htdoc/vmod_wesria`, `main`, HEAD `ef09ef701840882e7ccb2019dd1b7a03d56c43ea`; read-only reference remains `1d5f54ff55f8c1de225da53b00052f7e38f21752`. No matching target graph exists; implementation/callers/tests were traced directly.
- Reinspected dedicated containers, mounts and networks: PHP and Nginx serve this checkout at `/var/www/html`; PHP **8.2.34**, Composer **2.10.3**, MySQL **8.0.46**. Existing pinned image digests/IDs below are unchanged; no image rebuild or external runtime file edit was needed in this repair run. Neither MySQL service publishes a host port; development and test retain separate storage/networks.
- Development remains `mysql-dev/vmod_wesria_dev`, SQL identity `vmod_wesria_dev@%`: **65 tables, zero frontend/backend users**. Both native migration commands report `Nothing to migrate`. The development volume was not reset or seeded. The original `foodywesria` database was not touched.
- Only the explicitly owned `mysql-test` tmpfs service was recreated, after the ownership guard and SQL identity check. Fresh core/plugin/Tailor migrations on `vmod_wesria_test` proved **0 → 65 tables, zero frontend/backend users**. Final test SQL identity is `vmod_wesria_test@%`; `SHOW GRANTS` for each application user still permits only its own escaped schema. Native auth tests roll back their synthetic user.
- `.env` SHA-256 remains `8088beacb6df442af5fef12d2d29093a50d9f69c2fe721ad835c1021f6950177`. Lock SHA-256 remains `e5017adecd449ead9d00de31b2561756f0c804adf8f685c91354e860719420d6`. Only `composer.lock` was staged to satisfy the tracked-lockfile gate; no commit was created and no unrelated file was staged.

### Repairs and changed files

- `themes/demo/assets/vendor/codeblocks/codeblocks.js` uses the already-loaded `window.jQuery`; `codeblocks.min.js` was regenerated alone using **esbuild 0.24.2 and CodeMirror 5.65.5**, matching the existing CodeMirror version and declared dependency ranges. Build dependencies stayed under `/tmp/wesria-codeblocks-build`; no application dependency, CSS or other vendor bundle changed. `themes/demo/assets/vendor/codeblocks/LICENSE.txt` retains the exact upstream CodeMirror MIT notice beside the regenerated asset. The source resource directory and Tabler assets remain untouched. No global require shim or duplicate Bootstrap/jQuery was added.
- `modules/dashboard/tests/classes/ReportQueryBuilderTest.php`: the fixture declares the supported explicit `test_sales_products_data.name`/`.sku` mappings, and SKU selection/assertions use the registered alias consistently. Row counts, values, filtering and ordering assertions remain. No production query-builder change was necessary.
- `modules/tailor/tests/classes/BlueprintTest.php` and `BlueprintIndexerTest.php`: four previously skipped cases now run against only their existing fixture datasource, restoring shared datasource/cache state afterward. Fixed expected lists describe that fixture inventory (10 entry sections, 13 mixins, one global). UUID save/reload occurs in a unique temporary directory; source fixture files are never overwritten.
- `tests/e2e/phase1.mjs`: actual desktop/mobile menu navigation, specific About-page content, CodeMirror content/read-only/idempotent-render/expansion checks, and PNG signature/dimension assertions. Existing native asset, runtime-error, layout, focus and checkout-provenance assertions remain. It adds no browser framework.
- `docs/vmod-wesria-erp-mvp-plan-2026-10-15.md`, `docs/runtime-phase-1.md`, this file and the JSON evidence: approved repair scope, completed progress row, current commands/results and remaining limitations. All earlier foundation files are listed in the historical setup section below.

### Results

| Check | Fresh result |
| --- | --- |
| PHP/extensions, Composer validation/platform/lock reinstall, CLI boot | Exit 0; PHP 8.2.34, Laravel 12.70.0; nothing to install/update/remove; exact RainLab pin warning retained |
| HTTP boot | Frontend 200; `/admin` redirects to `/admin/backend/auth/signin`, final 200 |
| Fresh disposable migrations | Exit 0, empty schema → 65 tables; native October/RainLab plus six Tailor blueprints; zero accounts |
| Persistent development migration status | Exit 0; both commands report nothing to migrate, 65 tables/zero accounts preserved |
| Pre-boot unsafe target rejection | **11/11**, executed by aggregate runner before the application suite |
| Application MySQL | **19 tests / 31 assertions / zero skips, errors or failures** |
| Restored Tailor focused suite | **4 tests / 20 assertions / zero skips, errors or failures** |
| Aggregate framework SQLite | Exit 0, **425 tests / 2,287 assertions / four SQLite-only skips**, zero errors/failures |
| Native report MySQL | **23 tests / 178 assertions / zero skips, errors or failures** |
| Egolite | **10/10 scenarios**, zero runtime errors; ten valid screenshots visually inspected |
| Scoped PHPCS, PHP syntax and diff whitespace | Exit 0 |
| Composer audit | Exit 1; same three SVG-sanitizer advisories, explicitly retained below |

The four SQLite skips are `testWeeklyGrouping`, `testMonthlyGrouping`, `testQuarterlyGrouping`, and `testYearlyGrouping` in `ReportQueryBuilderTest`. All four **execute and pass on isolated MySQL** in the 23-test run. The framework baseline therefore has execution evidence for every case across these two engines; the SQLite skips themselves are not counted as passes. No locking/concurrency claim is made in Phase 1.

Fresh reproduction before repairs yielded the browser jQuery exception and MySQL **23 tests / 172 assertions / two errors**. Enabling Tailor tests first reproduced two fixture-list/count failures (3 tests / 7 assertions); isolated fixture correction then passed all four. A diagnostic UUID assertion incorrectly assumed `save()` returned a boolean; the native method returns void, so the final check asserts persisted UUID equality instead. The first standalone fresh-install probe omitted Composer autoload and failed before DB access; the corrected command below started from an independently verified empty schema. Failed diagnostic attempts are not acceptance passes.

### Browser evidence

Run timestamp: **2026-10-15T00:47:34.643Z** (Asia/Kuala_Lumpur: 08:47:34). URL: `https://wesria.local.test/`, TaskSpace **13**, egolite **0.5.1.13**. The random served-file probe matched the checkout and was removed in `finally`. Desktop: **1440 × 900**; mobile: **390 × 844**; device scale factor 1. Each viewport passed frontend, actual menu navigation, reload, native codeblocks and backend-login/focus checks. All referenced CSS/JS fetched HTTP 200 with valid MIME types; no browser exception, console error or horizontal document overflow was observed.

Screenshot capture initially failed even on a fresh blank page without emulation. Graceful quit/relaunch of the **same installation** restored both blank-page and application capture. No upgrade, replacement browser, profile clearing or cookie clearing occurred. The task space was finalized only after all ten scenarios passed.

Screenshots are in `output/ego-lite/phase-1/`: `desktop-{frontend,navigation,reload,codeblocks,backend-login}.png` and `mobile-{frontend,navigation,reload,codeblocks,backend-login}.png`. All ten PNG signatures and exact viewport dimensions were asserted; all were visually inspected. The JSON evidence records individual absolute paths and SHA-256 hashes. These are retained local, ignored browser artifacts, not committed image assets.

### Exact repair and acceptance commands

From `/Users/stev/htdoc/vmod_wesria`, using the same Compose authority throughout:

```sh
C=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
git status --short
git rev-parse HEAD
git -C /Users/stev/.codex/worktrees/foodcourt-fulfilment-fixes/netsemailparsing rev-parse HEAD
shasum -a 256 .env composer.lock
"${C[@]}" ps --format json
docker inspect foodywesria-php-tech-1 foodywesria-nginx-tech-1 foodywesria-mysql-dev-1 foodywesria-mysql-test-1 --format '{{json .Name}} {{json .Image}} {{json .Mounts}} {{json .NetworkSettings.Networks}} {{json .HostConfig.PortBindings}}'

npm install --prefix /tmp/wesria-codeblocks-build --no-audit --no-fund --ignore-scripts --save-exact esbuild@0.24.2 codemirror@5.65.21
# Match the existing bundle's exact CodeMirror version before the retained build.
npm install --prefix /tmp/wesria-codeblocks-build --no-audit --no-fund --ignore-scripts --save-exact codemirror@5.65.5
node --input-type=module <<'JS'
import { build } from '/tmp/wesria-codeblocks-build/node_modules/esbuild/lib/main.js';
await build({entryPoints:['assets/vendor/codeblocks/codeblocks.js'],absWorkingDir:'/Users/stev/htdoc/vmod_wesria/themes/demo',nodePaths:['/tmp/wesria-codeblocks-build/node_modules'],bundle:true,format:'iife',outfile:'assets/vendor/codeblocks/codeblocks.min.js',minify:true,sourcemap:false,target:['es2020'],external:['jquery']});
JS
cp /tmp/wesria-codeblocks-build/node_modules/codemirror/LICENSE themes/demo/assets/vendor/codeblocks/LICENSE.txt

osascript -e 'tell application id "com.citrolabs.ego.lite" to quit'
open -a 'ego lite'
ego-browser nodejs <<'JS'
const task = await taskSpace(13);
const p = task.page('p2');
await p.goto('about:blank');
await p.screenshot({path:'/tmp/wesria-phase1-control.png',raw:true,fullPage:false});
console.log('SCREENSHOT_OK');
JS
ego-browser nodejs <<'JS'
globalThis.vmodE2eSpaceId = 13;
await import('file:///Users/stev/htdoc/vmod_wesria/tests/e2e/phase1.mjs');
JS

"${C[@]}" exec -T -w /var/www/html php-tech sh -eu -c 'php -v; php -m; composer --version; composer validate; composer install --no-scripts --no-interaction; composer check-platform-reqs; php artisan --version'
"${C[@]}" --profile test run --rm -T php-test composer test
"${C[@]}" --profile test run --rm -T php-test vendor/bin/phpunit --do-not-cache-result --filter 'Blueprint(Test|IndexerTest)' modules/tailor/tests/classes
"${C[@]}" exec -T -w /var/www/html php-tech sh -eu -c 'vendor/bin/phpcs --standard=phpcs.xml tests/vmod tests/foundation tests/framework-bootstrap.php modules/tailor/tests/classes/BlueprintTest.php modules/tailor/tests/classes/BlueprintIndexerTest.php; php -l modules/dashboard/tests/classes/ReportQueryBuilderTest.php; php -l modules/tailor/tests/classes/BlueprintTest.php; php -l modules/tailor/tests/classes/BlueprintIndexerTest.php'
"${C[@]}" exec -T -w /var/www/html php-tech composer audit --format=json
curl -k -sS -o /dev/null -w 'frontend %{http_code}\n' https://wesria.local.test/
curl -k -sS -L -o /dev/null -w 'backend %{http_code} %{url_effective}\n' https://wesria.local.test/admin
git diff --check
git add -- composer.lock
git diff --cached --stat
git ls-files --error-unmatch composer.lock
```

Before recreating only disposable MySQL, this guard/identity probe ran successfully:

```sh
"${C[@]}" --profile test run --rm -T php-test php <<'PHP'
<?php
require 'tests/vmod/DatabaseGuard.php';
Vmod\Tests\DatabaseGuard::assertEnvironment();
$db = new PDO('mysql:host=mysql-test;port=3306;dbname=vmod_wesria_test', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$row = $db->query('select database() db, current_user() identity, version() version')->fetch(PDO::FETCH_ASSOC);
if ($row['db'] !== 'vmod_wesria_test' || $row['identity'] !== 'vmod_wesria_test@%') { throw new RuntimeException('Unsafe test service'); }
echo json_encode($row), PHP_EOL;
PHP
"${C[@]}" stop mysql-test
"${C[@]}" rm -f mysql-test
"${C[@]}" --profile test up -d --wait mysql-test
"${C[@]}" --profile test run --rm -T php-test php <<'PHP'
<?php
require 'tests/vmod/DatabaseGuard.php';
Vmod\Tests\DatabaseGuard::assertEnvironment();
require 'vendor/autoload.php';
foreach (['CACHE_PATH'=>'/tmp/wesria-vmod-cache','STORAGE_PATH'=>'/tmp/wesria-vmod-storage','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array'] as $key=>$value) { putenv("$key=$value"); $_SERVER[$key] = $_ENV[$key] = $value; }
require 'tests/vmod/bootstrap.php';
$case = new class extends Vmod\Tests\ApplicationTestCase {};
$app = $case->createApplication();
$db = $app['db']->connection();
$identity = $db->selectOne('select database() db, current_user() identity, version() version');
if ($identity->db !== 'vmod_wesria_test' || $identity->identity !== 'vmod_wesria_test@%') { throw new RuntimeException('Unsafe resolved SQL identity'); }
echo json_encode($identity), PHP_EOL;
$tables = count($db->select('show tables'));
echo "Tables before fresh install: $tables\n";
if ($tables !== 0) { throw new RuntimeException('Expected empty disposable schema'); }
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
foreach (['october:migrate', 'tailor:migrate'] as $command) {
 $status = $kernel->call($command, ['--no-interaction'=>true]);
 echo $kernel->output();
 if ($status) { exit($status); }
}
echo json_encode(['tables_after'=>count($db->select('show tables')), 'users'=>$db->table('users')->count(), 'backend_users'=>$db->table('backend_users')->count()]), PHP_EOL;
PHP
```

The exact guarded report command is retained in the historical setup section below; it was rerun unchanged after the final fixture correction and produced **23/178, zero skips/errors**. Fresh logs: `report-red.log`, `report-green.log`, `tailor-red.log`, `tailor-green.log`, `aggregate.log`, `dependencies.log`, `fresh-test-migrations.log`, `dev-state.log`, `dev-grants.log`, `test-final-state.log`, `lint.log`, `audit.json`, `egolite.log`, `runtime-provenance.log` under `/tmp/wesria-phase1-repair/`.

Fresh development status and final grant/account checks used these commands (credentials are read only from the service environment):

```sh
"${C[@]}" exec -T -w /var/www/html php-tech php <<'PHP'
<?php
if (getenv('APP_ENV') !== 'local' || getenv('DB_HOST') !== 'mysql-dev' || getenv('DB_DATABASE') !== 'vmod_wesria_dev' || getenv('DB_USERNAME') !== 'vmod_wesria_dev' || getenv('DB_URL') !== '' || getenv('DB_SOCKET') !== '') { throw new RuntimeException('Unexpected development environment'); }
require 'bootstrap/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$db = $app['db']->connection();
if ($db->getConfig('host') !== 'mysql-dev' || $db->getDatabaseName() !== 'vmod_wesria_dev' || $db->getConfig('url') || $db->getConfig('unix_socket')) { throw new RuntimeException('Unexpected resolved development connection'); }
$row = $db->selectOne('select database() db, current_user() identity, version() version');
echo json_encode($row), PHP_EOL;
echo json_encode(['tables'=>count($db->select('show tables')), 'users'=>$db->table('users')->count(), 'backend_users'=>$db->table('backend_users')->count()]), PHP_EOL;
foreach (['october:migrate', 'tailor:migrate'] as $command) {
 $status = $kernel->call($command, ['--no-interaction'=>true]);
 echo $kernel->output();
 if ($status) { exit($status); }
}
PHP
"${C[@]}" exec -T php-tech php <<'PHP'
<?php
if (getenv('DB_HOST') !== 'mysql-dev' || getenv('DB_DATABASE') !== 'vmod_wesria_dev' || getenv('DB_USERNAME') !== 'vmod_wesria_dev') { throw new RuntimeException('Unexpected development target'); }
$db = new PDO('mysql:host=mysql-dev;port=3306;dbname=vmod_wesria_dev',getenv('DB_USERNAME'),getenv('DB_PASSWORD'));
echo json_encode($db->query('show grants')->fetchAll(PDO::FETCH_COLUMN)), PHP_EOL;
PHP
"${C[@]}" --profile test run --rm -T php-test php <<'PHP'
<?php
require 'tests/vmod/DatabaseGuard.php';
Vmod\Tests\DatabaseGuard::assertEnvironment();
$db = new PDO('mysql:host=mysql-test;port=3306;dbname=vmod_wesria_test', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
echo json_encode($db->query('select database() db, current_user() identity, version() version')->fetch(PDO::FETCH_ASSOC)), PHP_EOL;
echo json_encode($db->query('show grants')->fetchAll(PDO::FETCH_COLUMN)), PHP_EOL;
echo json_encode(['users'=>$db->query('select count(*) from users')->fetchColumn(), 'backend_users'=>$db->query('select count(*) from backend_users')->fetchColumn()]), PHP_EOL;
PHP
```

### Final review

A fresh read-only reviewer found no material findings in dependency locking, database isolation, native authentication/suite separation, the Codeblocks repair, Report/Tailor test integrity, or the refreshed Phase 1 evidence. No additional DB/browser runs or code changes were required after review. Final whitespace checks pass; the reference revision, `.env` and lockfile checksums remain unchanged.

### Remaining limitations

- Composer audit still reports `PKSA-8j6w-3kr7-s9xk`, `PKSA-6j95-1jtb-x6wc`, and `PKSA-cbx2-m9db-bmzd` in `enshrined/svg-sanitize` 0.22.0 under the required October 4.3 series. No dependency-policy weakening, series upgrade, security-clean claim or production approval was made.
- Composer emits a non-fatal Git ownership-discovery warning inside the bind-mounted container; validation/install/platform checks and aggregate tests return zero. No global `safe.directory` exception was added.
- Local RainLab access/registration/native auth boot is proven; production license entitlement remains a release prerequisite. Native framework demo/login screens are the Phase 1 surface; Vmod authentication/workspaces and Tabler screens remain Phase 2.

## Historical initial foundation setup and blocked run

**The remainder is the earlier snapshot, retained for setup provenance and failure history. Its blocked outcome, untracked-lockfile state and failed browser/MySQL counts were superseded by the fresh acceptance above.**

### Checkout and runtime provenance

- Target: `/Users/stev/htdoc/vmod_wesria`, branch `main`, HEAD `ef09ef701840882e7ccb2019dd1b7a03d56c43ea`. Initially only the existing `docs/` directory was untracked. Existing documents and unrelated work were preserved. `AGENTS.md` is ignored by workstation rules; it was reconciled locally, not staged.
- Exact reference: `/Users/stev/.codex/worktrees/foodcourt-fulfilment-fixes/netsemailparsing` at `1d5f54ff55f8c1de225da53b00052f7e38f21752`, reverified after implementation. Reference files were read only. No legacy plugin was copied or renamed. The only installed plugins are the pre-existing `October.Demo` and Composer-installed `RainLab.User`.
- No current matching target graph was available. Implementations, configuration and callers were traced directly; the stale source-project graph was not used as target evidence.
- Runtime authority: `/Users/stev/htdoc/docker_foody_wesria/docker-compose.yml`, Compose project `foodywesria`. Both PHP and Nginx bind this exact target to `/var/www/html`. Nginx serves that directory and forwards PHP to `php-tech:9000`; its existing Traefik router serves `https://wesria.local.test/`.
- Actual PHP container image: `foodywesria-php-tech:phase1`, image ID `sha256:5ade0667a0ae5d7d555f5b0ea237094381bf6fc55d8ef31430f4848d37fb9866`. Base: `php:8.2.34-fpm-bookworm@sha256:76ba16ca0fa4b202b57299d3bd96672c0ddc98fa81dc95fd495696723b52584a`. Actual PHP is **8.2.34**, Composer CLI **2.10.3**. `pdo_mysql`, `mysqli`, `pdo_sqlite`, XML, GD, Imagick, curl and mbstring support were inspected; Composer's actual platform check passes.
- Both owned MySQL services use `mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`; running image ID `sha256:b7e118f56c5963e079252e0d6e2978c9c010eb7fc7aaaec100e367796b5dd08f`. SQL `version()` returns **8.0.46** in both services.
- Browser provenance includes a random file written in this checkout, fetched by the browser through the application URL, compared exactly, then removed in `finally`. The recorded `uniqueServedFileMatched: true` establishes the served checkout independently of generic October page text. Container/mount inspection was captured in the same acceptance session.

### Database and filesystem preservation

| Service | Database / SQL identity | Storage / network |
| --- | --- | --- |
| `mysql-dev` | `vmod_wesria_dev` / `vmod_wesria_dev@%` | Owned persistent volume `foodywesria_wesria-dev-data`; existing dedicated internal network |
| `mysql-test` | `vmod_wesria_test` / `vmod_wesria_test@%` | Disposable tmpfs; private `foodywesria_test`, `10.253.49.0/24` |

`SHOW GRANTS` proves each application user has `USAGE` globally and privileges only on its own escaped schema name. Neither database publishes a host port. PHP development no longer joins the shared `db` network; PHP tests join only the private test network. Existing shared services/networks and the original `foodywesria` database were not migrated, reset, seeded or queried. No old database contents were copied.

The four new external `wesria-*.env` credential files have mode **0600**, distinct non-root application credentials and separate root credentials retained only by the MySQL services. No credentials are stored in this evidence. Test ownership is `VMOD_TEST_DATABASE_OWNER=vmod_wesria_phase1`. The private subnet was selected after Docker reported exhausted automatic address pools; existing networks were inspected and left intact.

The original application `.env` remains byte-identical: SHA-256 `8088beacb6df442af5fef12d2d29093a50d9f69c2fe721ad835c1021f6950177`. Its backend URI is `/admin`; `/backend` is a stale historical URL. Fresh development migrations produced 65 tables, including native demo Tailor content tables, with **zero frontend users and zero backend users**. No sample content/accounts were seeded. Native frontend-auth creation was exercised only inside a rolled-back test transaction; the test service has zero users afterward.

### Changed files

- `.gitignore`, `composer.json`, `composer.lock`: PHP platform 8.2.34; October 4.3 constraint; exact RainLab.User 3.7.2; minimal compatible lock resolution; separate/aggregate test scripts; Composer-managed RainLab plugin exclusion and browser-output exclusion. The lockfile is present and no longer ignored, but remains uncommitted/untracked because no Git staging/commit was requested.
- `phpunit.xml`, `tests/framework-bootstrap.php`: retain the native framework suite, force SQLite memory and fixture paths across PHP environment sources, isolate cache/storage, reject cached application configuration.
- `phpunit-vmod.xml`, `tests/vmod/{bootstrap.php,DatabaseGuard.php,ApplicationTestCase.php,check-guard.php}`, `tests/foundation/{DatabaseGuardTest.php,FoundationBootstrapTest.php}`: pre-boot guard, independent negative processes, resolved-connection recheck before migrations, real plugins and native frontend authentication, single plugin registration and MySQL rollback verification. Missing URL/socket keys are rejected too, preventing a later `.env` fallback. This regression was observed red, then passed after the guard correction. No new test framework or fixtures.
- `tests/e2e/phase1.mjs`: egolite/Node assertions, exact-checkout probe, cold asset loading, viewport/overflow assertions, navigation/reload, backend focus order and sanitized runtime-error capture. Planned screenshots and report are written under ignored `output/ego-lite/phase-1`. It exits nonzero on failure and keeps the failed TaskSpace for diagnosis as required by the ego-browser skill.
- `AGENTS.md`, `tests/README.md`, `docs/runtime-phase-1.md`, `docs/october-plugin-standards-design-2026-03-30.md`, `docs/october-frontend-form-handling-standards-2026-04-30.md`, `docs/notification-implementation-standards-2026-07-01.md`: reconcile runtime, frontend identity, native validation/testing and MVP boundaries; retain historical examples as references.
- `docs/vmod-wesria-erp-mvp-plan-2026-10-15.md` and this evidence: Tabler/egolite contracts, phase progress and actual results. Evaluation and plugin-skeleton files remain reference material.
- External, approved filesystem changes: `docker-compose.yml`, `Dockerfile_php8` and the four private credential files in `/Users/stev/htdoc/docker_foody_wesria`. Review the credential-free [runtime diff](phase-1-runtime.patch). Only dedicated Wesria services were built/started. No automatic Composer invocation remains in the PHP service startup command.

The native demo bundle was briefly diagnosed with a minimal local correction, then restored byte-for-byte because theme edits are outside the Phase 1 allowlist. There is **no retained theme change**. Tabler source files were not modified or copied. The Tabler 1.5.1 / Bootstrap 5.3.8 ES-module contract, selective assets, license/checksum recording, one Bootstrap implementation and excluded demo controls are documented for Phase 2 onward.

### Dependencies and access

The final lock retains October/Rain **4.3.4**, Laravel **12.70.0**, PHPUnit **9.6.36**, RainLab.User **3.7.2** (source `374007a8ae01377897c53375cb020595c7ba9a64`) and PHP platform **8.2.34**. Symfony PHP-8.4-only packages moved to compatible 7.4 releases; Doctrine Instantiator moved to 2.0.0. RainLab requires Composer Installers 1.x, so it resolved to 1.12.0. Composer/CommonMark/Flysystem received compatible patches. Installation performed seven additions and twelve updates; exact package versions are in the lock and [machine-readable results](phase-1-results-2026-10-15.json).

RainLab package resolution, download, registration, migration and native user-provider hydration all succeeded. No source vendor directory, project license or account credentials were copied. No project license was configured. [October's installation guide](https://docs.octobercms.com/user-guide/quickstart/introduction/install-october-cms.html) permits local exploration without a license; [license requirements](https://octobercms.com/help/license/faqs) still apply before a real product release. This evidence establishes local access/boot, not production entitlement.

The existing Composer advisory-reporting policy was preserved, not weakened. Composer 2.9.5 on the host did not understand that newer policy; acceptance uses container Composer 2.10.3. Audit still returns **three advisories in `enshrined/svg-sanitize` 0.22.0**, constrained by October/Rain 4.3: `PKSA-8j6w-3kr7-s9xk` (stored XSS), `PKSA-6j95-1jtb-x6wc` (nesting denial of service), `PKSA-cbx2-m9db-bmzd` (DTD declaration denial of service). No security-clean or production-ready claim is made; a framework-series upgrade was not authorized.

### Exact execution commands and results

Commands ran from `/Users/stev/htdoc/vmod_wesria`. This Bash/Zsh array abbreviates the exact Compose prefix below:

```sh
C=(docker compose -p foodywesria -f /Users/stev/htdoc/docker_foody_wesria/docker-compose.yml)
git status --short
git rev-parse HEAD
git -C /Users/stev/.codex/worktrees/foodcourt-fulfilment-fixes/netsemailparsing rev-parse HEAD
shasum -a 256 .env composer.lock
"${C[@]}" build php-tech
"${C[@]}" --profile test up -d --wait mysql-dev mysql-test
"${C[@]}" run --rm --no-deps -w /var/www/html php-tech composer update rainlab/user-plugin symfony/clock symfony/css-selector symfony/event-dispatcher symfony/filesystem symfony/string symfony/translation doctrine/instantiator composer/composer laravel/framework league/commonmark league/flysystem --with-all-dependencies --minimal-changes --no-scripts --no-interaction
"${C[@]}" up -d --no-deps php-tech nginx-tech
"${C[@]}" exec -T -w /var/www/html php-tech sh -c 'composer validate && composer install --no-scripts --no-interaction && composer check-platform-reqs'
"${C[@]}" exec -T -w /var/www/html php-tech sh -eu -c 'test "$APP_ENV" = local; test "$DB_HOST" = mysql-dev; test "$DB_DATABASE" = vmod_wesria_dev; test "$DB_USERNAME" = vmod_wesria_dev; test -z "$DB_URL"; test -z "$DB_SOCKET"; composer run-script post-autoload-dump; php artisan october:migrate --no-interaction; php artisan tailor:migrate --no-interaction'
"${C[@]}" --profile test run --rm php-test composer test
"${C[@]}" --profile test run --rm php-test sh -c 'composer test:vmod && vendor/bin/phpcs --standard=phpcs.xml tests/vmod tests/foundation tests/framework-bootstrap.php'
"${C[@]}" --profile test run --rm -e PLUGINS_PATH=modules/system/tests/fixtures/plugins -e CACHE_PATH=/tmp/untrusted-cache -e ENABLE_CSRF=false php-test composer test:vmod
"${C[@]}" --profile test run --rm php-test sh -c 'vendor/bin/phpcs --standard=phpcs.xml tests/vmod tests/foundation tests/framework-bootstrap.php && find tests/vmod tests/foundation -name "*.php" -exec php -l {} \; && php -l tests/framework-bootstrap.php'
"${C[@]}" exec -T -w /var/www/html php-tech composer audit --format=json
git diff --check
```

| Check | Fresh result |
| --- | --- |
| Runtime build / PHP / extensions | Pass, PHP 8.2.34; pinned digests and actual image IDs above |
| Composer validation | Valid; expected warning about the user-required exact RainLab version. `validate --strict` returned 1 for that warning; ordinary `validate` returned 0 |
| Repeat lock installation | Exit 0; nothing to install, update or remove; no platform bypass |
| Platform requirements | Exit 0; all reported requirements satisfied |
| Application scripts / fresh development migration | Pass after isolation; initial log says “Migration table created”; core/RainLab and six native Tailor blueprints migrated; repeat migration is idempotent |
| Guard processes | **11/11** unsafe configurations rejected before application autoload, including existing/dev schemas, missing ownership, URL/socket overrides or missing keys, cached app/framework config and conflicting server/environment values |
| Application MySQL suite | **19 tests / 31 assertions**, no skips or failures; real native auth provider and rolled-back insert |
| Hostile inherited settings | **19 tests / 31 assertions**; real application plugin path and CSRF retained |
| Aggregate framework suite | Exit 0; **425 tests / 2,267 assertions / 8 upstream skips**, no failures/errors. This is not an all-tests-passed claim |
| Focused native framework on MySQL | Exit 2; **23 tests / 172 assertions / 2 errors**, no skips; see below |
| PHP syntax / scoped PHPCS / diff whitespace | Pass |
| Dependency audit | Nonzero; three advisories listed above |
| Egolite | Exit 1; **one attempted desktop scenario, zero completed scenarios**; browser gate failed |

The eight native framework skips are four date-grouping cases unsupported by SQLite and four unconditional Tailor blueprint/indexer tests marked “Needs refactor to isolate blueprint paths”. To check actual MySQL behavior without replacing application evidence, this exact guarded command ran the native report class:

```sh
"${C[@]}" --profile test run --rm -T php-test php <<'PHP'
<?php
require 'tests/vmod/DatabaseGuard.php';
Vmod\Tests\DatabaseGuard::assertEnvironment();
$xml = file_get_contents('phpunit.xml');
$xml = str_replace('bootstrap="tests/framework-bootstrap.php"', 'bootstrap="/var/www/html/tests/framework-bootstrap.php"', $xml);
$xml = str_replace('name="DB_CONNECTION" value="sqlite"', 'name="DB_CONNECTION" value="mysql"', $xml);
$xml = str_replace('name="DB_DATABASE" value=":memory:"', 'name="DB_DATABASE" value="vmod_wesria_test"', $xml);
file_put_contents('/tmp/wesria-framework-mysql.xml', $xml);
passthru('php vendor/bin/phpunit -c /tmp/wesria-framework-mysql.xml --do-not-cache-result modules/dashboard/tests/classes/ReportQueryBuilderTest.php', $status);
exit($status);
PHP
```

The date grouping cases execute on MySQL, but `testDimensionFieldFilterStringStartsWith` and `testDimensionFieldFilterStringIncludes` fail with MySQL 1054, unknown `oc_field_product_name` in the WHERE clause. No framework business implementation or native tests were rewritten to hide this. No locking/concurrency correctness is claimed in Phase 1; later stock gates still require independent MySQL workers.

### Browser acceptance and remaining blockers

```sh
ego-browser nodejs <<'JS'
globalThis.vmodE2eSpaceId = 13;
await import('file:///Users/stev/htdoc/vmod_wesria/tests/e2e/phase1.mjs');
JS
```

The latest final run records `2026-10-14T18:27:16.962Z` (2026-10-15 Asia/Kuala_Lumpur), TaskSpace **13**, URL `https://wesria.local.test/`, actual desktop viewport **1440 × 900**, content width 1425, **four loaded stylesheets and eleven script URLs returning HTTP 200**. The exact-checkout probe passes. The runtime assertion fails on `Dynamic require of "jquery" is not supported`, originating from `themes/demo/assets/vendor/codeblocks/codeblocks.min.js`. Both the source entry's external jQuery import and the compiled bundle were inspected; no theme edits remain.

The script stops at that failed assertion. Desktop navigation/reload, mobile **390 × 844**, backend keyboard/focus and full scenario completion remain **pending**, not passed. A separate browser inspection rendered `/admin/backend/auth/signin` successfully, but is not a substitute for the scripted gate.

Screenshot probes using the documented `page.screenshot()` API (normal, viewport-only and raw), then the CDP escape hatch with `Page.bringToFront` and `Page.captureScreenshot`, all timed out at `Page.captureScreenshot`. A raw probe confirmed 1440 × 900 / devicePixelRatio 1 before timing out. **No screenshot was produced.** No alternate browser was substituted, no profile/cookies were cleared, and the failed space was not finalized. Browser unavailability is not a pass.

Next actions require staying in Phase 1: resolve the native demo packaging issue within an explicitly agreed scope, restore working egolite screenshot capture, rerun the mandatory browser scenarios, and disposition the native MySQL report failures/upstream skips and dependency advisories. Do not start Phase 2 merely because the runtime and application tests pass.

Machine-readable sanitized results are retained beside this document. Full local command logs remain under `/tmp/wesria-phase1-evidence/` (`aggregate-tests.log`, `vmod-tests.log`, `vmod-final.log`, `framework-mysql.log`, `repeat-install.log`, `dev-migrate.log`, `dev-tailor-migrate.log`, `dev-repeat-migrate.log`, `lint-final.log`, `audit-final.json`, `egolite.log`, runtime/container provenance). These are implementation-session logs, not historical substitute evidence. Compiler/install attempts that failed during diagnosis were not counted as acceptance passes.
