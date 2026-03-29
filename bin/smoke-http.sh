#!/usr/bin/env bash
# Layer 3 smoke: public HTTP probes against a temporary php -S + router.php.
# Usage: from repo root, ./bin/smoke-http.sh
# Override port: SMOKE_PHP_PORT=18991 ./bin/smoke-http.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${SMOKE_PHP_PORT:-18990}"
BASE="http://127.0.0.1:${PORT}"

php -S "127.0.0.1:${PORT}" -t "${ROOT}/public" "${ROOT}/public/router.php" >/tmp/claudriel-smoke-php.log 2>&1 &
PHP_PID=$!
cleanup() { kill "${PHP_PID}" 2>/dev/null || true; }
trap cleanup EXIT
sleep 1

fail() { echo "FAIL: $*" >&2; exit 1; }

code_html=$(curl -sS -o /tmp/smoke-brief.html -w "%{http_code}" "${BASE}/brief" || true)
[[ "${code_html}" == "200" ]] || fail "GET /brief HTML expected 200 got ${code_html}"

code_json=$(curl -sS -o /tmp/smoke-brief.json -w "%{http_code}" \
  -H "Accept: application/json" "${BASE}/brief" || true)
[[ "${code_json}" == "200" ]] || fail "GET /brief JSON expected 200 got ${code_json}"
python3 -c 'import json,sys; json.load(open("/tmp/smoke-brief.json"))' || fail "/brief JSON not valid JSON"

code_login=$(curl -sS -o /tmp/smoke-login.html -w "%{http_code}" "${BASE}/login" || true)
[[ "${code_login}" == "200" ]] || fail "GET /login expected 200 got ${code_login}"
[[ -s /tmp/smoke-login.html ]] || fail "GET /login empty body"

code_gql=$(curl -sS -o /tmp/smoke-gql.txt -w "%{http_code}" -X POST "${BASE}/graphql" \
  -H "Content-Type: application/json" \
  -d '{"query":"query { __typename }"}' || true)
[[ "${code_gql}" == "200" ]] || fail "POST /graphql introspection expected 200 got ${code_gql}"

code_404=$(curl -sS -o /dev/null -w "%{http_code}" "${BASE}/no-such-claudriel-route-xyz" || true)
[[ "${code_404}" == "404" ]] || fail "unknown path expected 404 got ${code_404}"

echo "OK smoke-http: /brief (HTML+JSON), /login, POST /graphql, 404 routing"
