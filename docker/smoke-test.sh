#!/usr/bin/env bash
# Boot the production image the way it would run for real and poke it.
# Usage: docker/smoke-test.sh <image>
set -euo pipefail

IMAGE="${1:?usage: $0 <image>}"
NAME="tally-smoke-$$"
KEY="base64:$(head -c 32 /dev/urandom | base64)"

cleanup() {
    docker logs "$NAME" >/tmp/smoke.log 2>&1 || true
    docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
    echo "FAIL: $*" >&2
    cat /tmp/smoke.log >&2 2>/dev/null || docker logs "$NAME" >&2 || true
    exit 1
}

echo "==> Runs as a non-root user"
user="$(docker run --rm --entrypoint id "$IMAGE" -u)"
[ "$user" != "0" ] || fail "container runs as root"

echo "==> Refuses to start without APP_KEY"
if docker run --rm "$IMAGE" true >/dev/null 2>&1; then
    fail "container started without APP_KEY"
fi

echo "==> Migrations run inside the image"
docker run --rm -e APP_KEY="$KEY" -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/smoke.sqlite \
    "$IMAGE" sh -c 'touch /tmp/smoke.sqlite && php artisan migrate --force' \
    || fail "migrations failed"

echo "==> Server starts and becomes healthy"
docker run -d --name "$NAME" -p 8080:8080 \
    -e APP_KEY="$KEY" -e CACHE_STORE=array -e SESSION_DRIVER=array \
    "$IMAGE" >/dev/null

for _ in $(seq 1 30); do
    status="$(docker inspect --format '{{.State.Health.Status}}' "$NAME")"
    [ "$status" = "healthy" ] && break
    [ "$(docker inspect --format '{{.State.Running}}' "$NAME")" = "true" ] || fail "container exited"
    sleep 1
done
[ "$status" = "healthy" ] || fail "container never became healthy (last status: $status)"

echo "==> /up returns 200"
curl -fsS -o /dev/null http://127.0.0.1:8080/up || fail "/up did not return 200"

echo "==> Protected API returns a JSON 401, not a 500 or a redirect"
code="$(curl -s -o /tmp/body.json -w '%{http_code}' http://127.0.0.1:8080/api/invoices)"
[ "$code" = "401" ] || fail "expected 401 from /api/invoices, got $code"
grep -q '"message":"Unauthenticated."' /tmp/body.json || fail "401 body was not JSON: $(cat /tmp/body.json)"

echo "==> Debug mode is off"
code="$(curl -s -o /tmp/body.json -w '%{http_code}' http://127.0.0.1:8080/api/invoices/not-a-number -H 'Accept: application/json')"
if grep -q '"trace"' /tmp/body.json; then
    fail "stack trace leaked in a $code response"
fi

echo "==> X-Powered-By is not exposed"
if curl -sI http://127.0.0.1:8080/up | grep -qi '^x-powered-by'; then
    fail "X-Powered-By header present"
fi

echo "All smoke tests passed."
