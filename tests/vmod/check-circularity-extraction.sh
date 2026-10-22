#!/usr/bin/env bash
# Fixed Phase 5 rehearsal, invoked by check-circularity-http.sh --extracted. No development services change.
set -euo pipefail
source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
dependencies="${VMOD_DEPENDENCY_ROOT:-$source_root}"
proof="$(mktemp -d /private/tmp/wesria-circularity-target.XXXXXX)"
network= mysql= provider= ui_pid= source_php=
cleanup() {
    result=$?
    if [ -z "$source_php" ] && [ -f "$proof/handoff/source-php" ]; then source_php="$(cat "$proof/handoff/source-php")"; fi
    if [ -n "$ui_pid" ] && kill -0 "$ui_pid" 2>/dev/null; then kill "$ui_pid" 2>/dev/null || true; fi
    for id in "$source_php" "$provider" "$mysql"; do
        if [ -n "$id" ] && docker inspect "$id" >/dev/null 2>&1; then
            docker logs "$id" > "$proof/$id.log" 2>&1 || true
            docker rm -f "$id" >/dev/null
        fi
    done
    if [ -n "$ui_pid" ]; then wait "$ui_pid" 2>/dev/null || true; fi
    if [ -n "$network" ]; then docker network rm "$network" >/dev/null; fi
    if [ "$result" -eq 0 ]; then rm -rf "$proof"; else echo "Private extraction artifacts: $proof" >&2; fi
}
trap cleanup EXIT
chmod 755 "$proof"
python3 - "$source_root" "$proof" <<'PY'
from pathlib import Path
import secrets, shutil, sys
source, proof = map(Path, sys.argv[1:])
app = proof / 'app'
for folder in ['bootstrap', 'plugins/vmod/circularity', 'storage/framework', 'cache/framework', 'vendor']:
    (app / folder).mkdir(parents=True, exist_ok=True)
# Native RegisterOctober skips creating children when a mounted root already exists.
for folder in ['storage/app/media', 'storage/app/uploads', 'storage/framework/sessions', 'storage/logs',
               'cache/cms/cache', 'cache/cms/combiner', 'cache/cms/twig', 'cache/framework/cache',
               'cache/framework/views', 'cache/temp/public']:
    (app / folder).mkdir(parents=True, exist_ok=True)
for name in ['index.php', 'artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/autoload.php', 'bootstrap/providers.php']:
    shutil.copyfile(source / name, app / name)
for name in ['config', 'modules']:
    shutil.copytree(source / name, app / name)
for name in ['Plugin.php', 'routes.php', 'classes', 'models', 'config', 'updates']:
    src, dst = source / 'plugins/vmod/circularity' / name, app / 'plugins/vmod/circularity' / name
    shutil.copytree(src, dst) if src.is_dir() else shutil.copyfile(src, dst)
for folder in ['qualification', 'transfer', 'evidence', 'handoff']:
    (proof / folder).mkdir()
for name in ['DatabaseGuard.php', 'check-circularity-extraction.php']:
    shutil.copyfile(source / 'tests/vmod' / name, proof / 'qualification' / name)
for name in ['storage', 'cache']:
    (app / name).chmod(0o777)
(proof / 'mysql.env').write_text('MYSQL_DATABASE=vmod_wesria_test\nMYSQL_USER=vmod_wesria_test\nMYSQL_PASSWORD=' + secrets.token_hex(32) + '\nMYSQL_ROOT_PASSWORD=' + secrets.token_hex(32) + '\n')
(proof / 'mysql.env').chmod(0o600)
PY
cat > "$proof/provider-nginx.conf" <<'NGINX'
server {
    listen 8081;
    resolver 127.0.0.11 valid=1s;
    set $provider circularity-target;
    location / { return 404; }
    location ^~ /api/circularity/v1/ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_X_CIRCULARITY_CONTEXT $http_x_circularity_context;
        fastcgi_pass $provider:9000;
    }
}
NGINX
# October intentionally skips plugin boot under APP_ENV=testing. Use its native provider lifecycle.
cat > "$proof/qualification/RegisterProvider.php" <<'PHP'
<?php
class CircularityRehearsalProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        if (!$this->app->runningUnitTests() || getenv('VMOD_EXTRACTION_TARGET') !== 'true') {
            throw new \RuntimeException('Rehearsal bootstrap requires the guarded target.');
        }
        \System\Classes\PluginManager::instance()->registerAll(true);
    }
    public function boot()
    {
        \System\Classes\PluginManager::instance()->bootAll(true);
    }
}
PHP
cat > "$proof/app/bootstrap/providers.php" <<'PHP'
<?php
require_once '/qualification/RegisterProvider.php';
return [System\ServiceProvider::class, CircularityRehearsalProvider::class];
PHP
cat > "$proof/qualification/fpm.conf" <<'FPM'
[global]
daemonize = no
error_log = /proc/self/fd/2
[provider]
user = www-data
group = www-data
listen = 9000
pm = static
pm.max_children = 1
clear_env = no
catch_workers_output = yes
chdir = /var/www/html
php_admin_value[auto_prepend_file] = /qualification/check-circularity-extraction.php
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /proc/self/fd/2
FPM
# Docker's default pools can be exhausted by unrelated projects; choose an unused private /24 explicitly.
subnet="$(docker network ls -q | xargs docker network inspect | python3 -c '
import ipaddress, json, sys
used = [ipaddress.ip_network(c["Subnet"]) for n in json.load(sys.stdin) for c in (n["IPAM"]["Config"] or []) if c.get("Subnet")]
print(next(str(n) for i in range(50, 250) for n in [ipaddress.ip_network("10.253.%d.0/24" % i)] if not any(n.overlaps(u) for u in used)))
')"
network="$(docker network create --subnet "$subnet" --internal --label vmod.circularity.extracted=true "circularity-extracted-$(basename "$proof")")"
mysql_image="$(docker inspect --format '{{.Image}}' foodywesria-mysql-test-1)"
mysql="$(docker run -d --network "$network" --network-alias mysql-test --label vmod.circularity.extracted=true \
    --tmpfs /var/lib/mysql --env-file "$proof/mysql.env" "$mysql_image")"
server_uuid=
for attempt in {1..90}; do
    server_uuid="$(docker exec "$mysql" sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" "$MYSQL_DATABASE" -Nse "SELECT @@server_uuid"' 2>/dev/null || true)"
    if [[ "$server_uuid" =~ ^[a-f0-9-]{36}$ ]]; then break; fi
    sleep 1
done
[[ "$server_uuid" =~ ^[a-f0-9-]{36}$ ]] || { echo 'Target MySQL unavailable.' >&2; exit 1; }
docker inspect "$mysql" | python3 -c 'import json,sys; c=json.load(sys.stdin)[0]; assert c["HostConfig"]["Tmpfs"] == {"/var/lib/mysql": ""}; assert len(c["NetworkSettings"]["Networks"]) == 1; assert not c["HostConfig"]["PortBindings"]'
VMOD_EXTRACTED_PROOF=true VMOD_EXTRACTED_NETWORK="$network" VMOD_EXTRACTED_CONFIG="$proof/provider-nginx.conf" \
    VMOD_EXTRACTED_HANDOFF="$proof/handoff" bash "$source_root/tests/vmod/check-circularity-http.sh" --browser > "$proof/ui.log" 2>&1 &
ui_pid=$!
for attempt in {1..60}; do
    [ ! -f "$proof/handoff/source-dir" ] || break
    kill -0 "$ui_pid" || { cat "$proof/ui.log"; exit 1; }
    sleep 1
done
source_proof="$(cat "$proof/handoff/source-dir")"
python3 - "$proof" "$server_uuid" <<'PY'
from pathlib import Path
import base64, secrets, sys
p = Path(sys.argv[1])
shared = dict(line.split('=', 1) for line in (p / 'handoff/ui.env').read_text().splitlines())
mysql = dict(line.split('=', 1) for line in (p / 'mysql.env').read_text().splitlines())
env = {'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    'APP_CONFIG_CACHE': '/tmp/wesria-test-config.php', 'VMOD_TEST_DATABASE_OWNER': 'vmod_wesria_phase1',
    'DB_CONNECTION': 'mysql', 'DB_HOST': 'mysql-test', 'DB_PORT': '3306', 'DB_DATABASE': 'vmod_wesria_test',
    'DB_USERNAME': 'vmod_wesria_test', 'DB_PASSWORD': mysql['MYSQL_PASSWORD'], 'DB_URL': '', 'DB_SOCKET': '',
    'CIRCULARITY_API_TOKEN': shared['CIRCULARITY_API_TOKEN'], 'CIRCULARITY_TOKEN_HASH': shared['CIRCULARITY_TOKEN_HASH'],
    'CIRCULARITY_SOURCE_NAMESPACE': 'wesria-proof', 'CIRCULARITY_REPORTING_TIMEZONE': 'Asia/Kuala_Lumpur',
    'SESSION_DRIVER': 'array', 'CACHE_STORE': 'array', 'CACHE_DRIVER': 'array', 'ACTIVE_THEME': 'vmod',
    'STORAGE_PATH': '/var/www/html/storage', 'CACHE_PATH': '/var/www/html/cache', 'VMOD_EXTRACTION_TARGET': 'true',
    'VMOD_TARGET_UUID': sys.argv[2]}
(p / 'private.env').write_text(''.join(k + '=' + v + '\n' for k, v in env.items()))
(p / 'private.env').chmod(0o600)
PY
target_args=(--network "$network" --network-alias circularity-target --label vmod.circularity.extracted=true
    --env-file "$proof/private.env" -v "$proof/app:/var/www/html:ro" -v "$dependencies/vendor:/var/www/html/vendor:ro"
    -v "$proof/app/storage:/var/www/html/storage" -v "$proof/app/cache:/var/www/html/cache"
    -v "$proof/qualification:/qualification:ro" -v "$proof/transfer:/transfer:ro" -v "$proof/evidence:/evidence" -w /var/www/html)
for override in DB_DATABASE=vmod_wesria_dev VMOD_TARGET_UUID=wrong-server; do
    if docker run --rm "${target_args[@]}" -e "$override" foodywesria-php-tech:phase1 php /qualification/check-circularity-extraction.php init > "$proof/unsafe-target.log" 2>&1; then
        echo 'Unsafe target was accepted.' >&2; exit 1
    fi
    if ! rg -q 'explicitly owned disposable MySQL configuration|Target server UUID differs' "$proof/unsafe-target.log"; then cat "$proof/unsafe-target.log"; exit 1; fi
done
echo 'PASS: wrong schema and wrong physical server rejected before migrations.'
docker run --rm "${target_args[@]}" foodywesria-php-tech:phase1 php /qualification/check-circularity-extraction.php init
# FPM runs as www-data; the native CLI boot has initialized fresh storage as root.
docker run --rm "${target_args[@]}" foodywesria-php-tech:phase1 chown -R www-data:www-data storage cache
provider="$(docker run -d "${target_args[@]}" foodywesria-php-tech:phase1 php-fpm -F -y /qualification/fpm.conf)"
docker exec "$provider" php /qualification/check-circularity-extraction.php health
touch "$proof/handoff/target-ready"
for attempt in {1..180}; do
    if [ -f "$proof/handoff/source-php" ]; then source_php="$(cat "$proof/handoff/source-php")"; fi
    [ ! -f "$source_proof/source-ready" ] || break
    kill -0 "$ui_pid" || { cat "$proof/ui.log"; exit 1; }
    sleep 1
done
[ -f "$source_proof/source-ready" ] || { cat "$proof/ui.log"; exit 1; }
cp "$source_proof/snapshot.json" "$proof/transfer/snapshot.json"
docker exec "$provider" php /qualification/check-circularity-extraction.php import
docker exec "$provider" php /qualification/check-circularity-extraction.php contract
mkdir -p "$source_proof/target-controls"
cp "$proof/evidence/transfer.json" "$source_proof/transfer-evidence.json"
docker inspect "$provider" "$mysql" | python3 -c 'import json,sys; rows=json.load(sys.stdin); print(json.dumps([{k:r[k] for k in ["Id","Image","Mounts"]} | {"networks": list(r["NetworkSettings"]["Networks"])} for r in rows], indent=2))' > "$source_proof/target-runtime.json"
touch "$source_proof/extracted-ready"
echo 'READY: extracted browser runtime; private pointer /private/tmp/wesria-circularity-phase5-runtime.json'
while kill -0 "$ui_pid" 2>/dev/null; do
    for request in "$source_proof"/target-controls/*.request; do
        [ -f "$request" ] || continue
        action="$(cat "$request")"
        case "$action" in
            snapshot) docker exec "$provider" php /qualification/check-circularity-extraction.php snapshot > "$request.tmp" ;;
            stop) docker stop -t 15 "$provider" >/dev/null; printf '{"stopped":true}' > "$request.tmp" ;;
            start) docker start "$provider" >/dev/null; printf '{"started":true}' > "$request.tmp" ;;
            *) echo 'Unknown target control.' >&2; exit 1 ;;
        esac
        mv "$request.tmp" "$request.response"
        rm "$request"
    done
    sleep 0.2
done
wait "$ui_pid"
ui_pid=
source_php=
cat "$proof/ui.log"
