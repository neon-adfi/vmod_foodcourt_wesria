#!/usr/bin/env bash
# Run serially after starting the owned mysql-test service. No development service is modified.
set -euo pipefail
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
python3 - "$circularity_tmp" <<'PY'
from pathlib import Path
import base64, hashlib, secrets, sys
p = Path(sys.argv[1])
token = secrets.token_hex(32)
p.joinpath('private.env').write_text('\n'.join([
    'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    'APP_DEBUG=false', 'CIRCULARITY_API_TOKEN=' + token,
    'CIRCULARITY_TOKEN_HASH=' + hashlib.sha256(token.encode()).hexdigest(),
    'CIRCULARITY_SOURCE_NAMESPACE=wesria-proof',
    'CIRCULARITY_API_URL=http://nginx-tech/api/circularity/v1',
    'CIRCULARITY_ALLOW_INTERNAL_HTTP=true',
    'CACHE_STORE=array', 'CACHE_DRIVER=array', 'SESSION_DRIVER=array',
    'STORAGE_PATH=/proof/ui-storage', 'CACHE_PATH=/proof/ui-cache',
]) + '\n')
p.joinpath('private.env').chmod(0o600)
p.joinpath('nginx.conf').write_text('''server {
    listen 80;
    resolver 127.0.0.11 valid=1s;
    set $proof_php circularity-php;
    location / {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/tests/vmod/check-circularity-http.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_X_CIRCULARITY_CONTEXT $http_x_circularity_context;
        fastcgi_pass $proof_php:9000;
        fastcgi_read_timeout 20s;
    }
    location ^~ /api/circularity/v1/ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/tests/vmod/check-circularity-http.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_X_CIRCULARITY_CONTEXT $http_x_circularity_context;
        fastcgi_pass $proof_php:9001;
        fastcgi_read_timeout 20s;
    }
}
''')
PY
docker network inspect foodywesria_test >/dev/null
circularity_nginx_image="$(docker inspect --format '{{.Image}}' foodywesria-nginx-tech-1)"
# Resolve PHP upstream lazily: the PHP runner below is not started yet.
circularity_nginx_id="$(docker run -d --network foodywesria_test --network-alias nginx-tech \
    --label vmod.circularity.proof=true \
    -v "$circularity_tmp/nginx.conf:/etc/nginx/conf.d/default.conf:ro" "$circularity_nginx_image")"
docker run --rm --network foodywesria_test --network-alias circularity-php \
    --label vmod.circularity.proof=true \
    --env-file "$circularity_runtime/wesria-test.env" --env-file "$circularity_tmp/private.env" \
    -v "$circularity_source:/var/www/html:ro" \
    -v "$circularity_dependencies/vendor:/var/www/html/vendor:ro" \
    -v "$circularity_dependencies/plugins/rainlab/user:/var/www/html/plugins/rainlab/user:ro" \
    -v "$circularity_tmp:/proof" -w /var/www/html foodywesria-php-tech:phase1 \
    php tests/vmod/check-circularity-http.php
