#!/usr/bin/env bash
# Run serially after starting the owned mysql-test service. No development service is modified.
set -euo pipefail
circularity_browser=false
if [ "${1:-}" = --browser ] && [ "$#" -eq 1 ]; then
    circularity_browser=true
elif [ "$#" -ne 0 ]; then
    echo 'Usage: check-circularity-http.sh [--browser]' >&2
    exit 2
fi
circularity_source="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
circularity_dependencies="${VMOD_DEPENDENCY_ROOT:-$circularity_source}"
circularity_runtime=/Users/stev/htdoc/docker_foody_wesria
circularity_tmp="$(mktemp -d /private/tmp/wesria-circularity-http.XXXXXX)"
circularity_nginx_id=
cleanup() {
    circularity_status=$?
    if [ -n "$circularity_nginx_id" ]; then
        if [ "$circularity_status" -ne 0 ]; then docker logs "$circularity_nginx_id" > "$circularity_tmp/nginx.log" 2>&1; fi
        docker rm -f "$circularity_nginx_id" >/dev/null
    fi
    if [ "$circularity_status" -eq 0 ]; then
        rm -rf "$circularity_tmp"
    else
        echo "Private failure artifacts: $circularity_tmp" >&2
    fi
}
trap cleanup EXIT
chmod 755 "$circularity_tmp"
python3 - "$circularity_tmp" "$circularity_browser" <<'PY'
from pathlib import Path
import base64, hashlib, secrets, sys
p = Path(sys.argv[1])
token = secrets.token_hex(32)
browser = sys.argv[2] == 'true'
p.joinpath('private.env').write_text('\n'.join([
    'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    'APP_DEBUG=false', 'CIRCULARITY_API_TOKEN=' + token,
    'CIRCULARITY_TOKEN_HASH=' + hashlib.sha256(token.encode()).hexdigest(),
    'CIRCULARITY_SOURCE_NAMESPACE=wesria-proof',
    'CIRCULARITY_API_URL=http://nginx-tech/api/circularity/v1',
    'CIRCULARITY_ALLOW_INTERNAL_HTTP=true',
    'CACHE_STORE=' + ('file' if browser else 'array'),
    'CACHE_DRIVER=' + ('file' if browser else 'array'),
    'SESSION_DRIVER=' + ('file' if browser else 'array'),
    'SESSION_COOKIE=circularity_proof_' + secrets.token_hex(8),
    'SESSION_DOMAIN=null', 'SESSION_SECURE_COOKIE=false',
    'ACTIVE_THEME=vmod', 'LINK_POLICY=detect',
    'VMOD_BROWSER_PROOF=' + sys.argv[2], 'VMOD_PROOF_HOST_DIR=' + str(p),
    'STORAGE_PATH=/proof/ui-storage', 'CACHE_PATH=/proof/ui-cache',
]) + '\n')
p.joinpath('private.env').chmod(0o600)
p.joinpath('nginx.conf').write_text('''server {
    listen 80;
    root /var/www/html;
    resolver 127.0.0.11 valid=1s;
    set $proof_php circularity-php;
    location / {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/tests/vmod/check-circularity-http.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param HTTP_HOST $http_host;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_X_CIRCULARITY_CONTEXT $http_x_circularity_context;
        fastcgi_pass $proof_php:9000;
        fastcgi_read_timeout 45s;
    }
    location ^~ /api/circularity/v1/ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/tests/vmod/check-circularity-http.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_X_CIRCULARITY_CONTEXT $http_x_circularity_context;
        fastcgi_pass $proof_php:9001;
        fastcgi_read_timeout 45s;
    }
    location ~ ^/(themes|modules|plugins)/.*\\.(css|js|map|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf)$ {
        try_files $uri =404;
    }
}
''')
PY
docker network inspect foodywesria_test >/dev/null
circularity_nginx_image="$(docker inspect --format '{{.Image}}' foodywesria-nginx-tech-1)"
# Resolve PHP upstream lazily: the PHP runner below is not started yet.
circularity_nginx_args=(--network foodywesria_test --network-alias nginx-tech)
if [ "$circularity_browser" = true ]; then circularity_nginx_args=(--network bridge -p 127.0.0.1::80); fi
circularity_nginx_id="$(docker run -d --label vmod.circularity.proof=true \
    "${circularity_nginx_args[@]}" \
    -v "$circularity_source:/var/www/html:ro" \
    -v "$circularity_tmp/nginx.conf:/etc/nginx/conf.d/default.conf:ro" "$circularity_nginx_image")"
if [ "$circularity_browser" = true ]; then
    # Docker 29 does not publish ports on an internal-only network; only Nginx joins the host-facing bridge.
    docker network connect --alias nginx-tech foodywesria_test "$circularity_nginx_id"
    circularity_port="$(docker port "$circularity_nginx_id" 80/tcp)"
    case "$circularity_port" in 127.0.0.1:*) ;; *) echo 'Proof origin must be loopback only.' >&2; exit 1 ;; esac
    printf 'APP_URL=http://%s\n' "$circularity_port" >> "$circularity_tmp/private.env"
    python3 - "$circularity_tmp" <<'PY'
from pathlib import Path
import json, sys
p = Path('/private/tmp/wesria-circularity-phase4-runtime.json')
p.write_text(json.dumps({'proof_dir': sys.argv[1]}))
p.chmod(0o600)
PY
    echo "Browser proof directory: $circularity_tmp"
fi
docker run --rm --network foodywesria_test --network-alias circularity-php \
    --label vmod.circularity.proof=true \
    --env-file "$circularity_runtime/wesria-test.env" --env-file "$circularity_tmp/private.env" \
    -v "$circularity_source:/var/www/html:ro" \
    -v "$circularity_dependencies/vendor:/var/www/html/vendor:ro" \
    -v "$circularity_dependencies/plugins/rainlab/user:/var/www/html/plugins/rainlab/user:ro" \
    -v "$circularity_tmp:/proof" -w /var/www/html foodywesria-php-tech:phase1 \
    php tests/vmod/check-circularity-http.php
