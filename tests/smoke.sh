#!/usr/bin/env bash
# HTTP smoke test for the admin panel and public endpoints.
#
#   BASE=http://127.0.0.1:8080 ADMIN_USER=admin ADMIN_PASS='...' ./tests/smoke.sh
#
# Requires the app to be installed already (storage/installed.lock).
set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-NewStrongPass987}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
JAR="$(mktemp)"
PASS=0
FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31m✗\033[0m %s %s\n' "$1" "${2:-}"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
goto() { curl -s -o /dev/null -w '%{redirect_url}' "$@"; }

expect_code() { # label url expected
  local got
  got=$(code -b "$JAR" -c "$JAR" "$2")
  if [ "$got" = "$3" ]; then ok "$1 ($got)"; else bad "$1" "expected $3 got $got"; fi
}

echo "== guards =="
expect_code "unauthenticated dashboard redirects" "$BASE/admin/?r=dashboard" "302"
expect_code "login page renders" "$BASE/admin/?r=login" "200"
expect_code "unknown route is 404" "$BASE/admin/?r=doesnotexist" "404"
expect_code "webhook rejects GET" "$BASE/bot.php" "405"
BAD_SECRET=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/bot.php?secret=wrong" -H 'Content-Type: application/json' -d '{}')
[ "$BAD_SECRET" = "401" ] && ok "webhook rejects wrong secret (401)" || bad "webhook rejects wrong secret" "$BAD_SECRET"
expect_code "public landing redirects" "$BASE/" "302"

if [ ! -f "$ROOT/storage/installed.lock" ]; then
  echo "app is not installed — run the installer first"; exit 1
fi

echo "== login =="
curl -s -c "$JAR" -b "$JAR" "$BASE/admin/?r=login" -o /tmp/login.html
TOKEN=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/login.html | head -1 | sed 's/.*value="//;s/"//')
[ -n "$TOKEN" ] && ok "login form has CSRF token" || bad "login form has CSRF token"

# POST without a token must not authenticate
NO_CSRF=$(code -b "$JAR" -c "$JAR" -X POST "$BASE/admin/?r=login" --data "username=$ADMIN_USER&password=$ADMIN_PASS")
[ "$NO_CSRF" = "200" ] || [ "$NO_CSRF" = "419" ] && ok "login without CSRF is refused ($NO_CSRF)" || bad "login without CSRF" "$NO_CSRF"

STEP=$(goto -b "$JAR" -c "$JAR" -X POST "$BASE/admin/?r=login" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "username=$ADMIN_USER" --data-urlencode "password=$ADMIN_PASS")
case "$STEP" in
  *r=otp*) ok "password accepted, 2FA requested" ;;
  *r=first-login*) ok "first login flow requested" ;;
  *) bad "password step" "$STEP" ;;
esac

if [[ "$STEP" == *r=otp* ]]; then
  curl -s -c "$JAR" -b "$JAR" "$BASE/admin/?r=otp" -o /tmp/otp.html
  CODE=$(grep -o 'code=[0-9]*' "$ROOT/storage/otp/otp.log" 2>/dev/null | tail -1 | cut -d= -f2)
  if [ -z "$CODE" ]; then
    bad "OTP available (panel is in Bale delivery mode; switch to file mode to smoke-test)"
  else
    OTOKEN=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/otp.html | head -1 | sed 's/.*value="//;s/"//')
    DEST=$(goto -b "$JAR" -c "$JAR" -X POST "$BASE/admin/?r=otp" \
      --data-urlencode "_csrf=$OTOKEN" --data-urlencode "action=verify" --data-urlencode "code=$CODE")
    [[ "$DEST" == *"/admin/"* ]] && ok "OTP accepted" || bad "OTP accepted" "$DEST"
  fi
fi

echo "== panel pages =="
for route in dashboard users downloads broadcast channels settings security admins tools audit; do
  BODY=$(curl -s -b "$JAR" -c "$JAR" "$BASE/admin/?r=$route")
  STATUS=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$BASE/admin/?r=$route")
  if [ "$STATUS" = "200" ] && ! grep -qiE 'Fatal error|Warning:|Notice:|Uncaught' <<<"$BODY"; then
    ok "GET ?r=$route"
  else
    bad "GET ?r=$route" "status=$STATUS $(grep -oiE 'Fatal error[^<]*|Warning:[^<]*' <<<"$BODY" | head -1)"
  fi
done
for tab in bot instagram behavior panel texts; do
  STATUS=$(code -b "$JAR" -c "$JAR" "$BASE/admin/?r=settings&tab=$tab")
  [ "$STATUS" = "200" ] && ok "settings tab: $tab" || bad "settings tab: $tab" "$STATUS"
done

echo "== CSRF on state-changing POST =="
NO_TOKEN=$(code -b "$JAR" -c "$JAR" -X POST "$BASE/admin/?r=channels" --data "action=toggle&id=1")
[ "$NO_TOKEN" = "419" ] && ok "POST without CSRF token is 419" || bad "POST without CSRF token" "$NO_TOKEN"

echo "== logout =="
DASH=$(curl -s -b "$JAR" -c "$JAR" "$BASE/admin/")
LTOKEN=$(grep -o 'name="_csrf" value="[^"]*"' <<<"$DASH" | head -1 | sed 's/.*value="//;s/"//')
OUT=$(goto -b "$JAR" -c "$JAR" -X POST "$BASE/admin/?r=logout" --data-urlencode "_csrf=$LTOKEN")
[[ "$OUT" == *"r=login"* ]] && ok "logout redirects to login" || bad "logout" "$OUT"
AFTER=$(code -b "$JAR" -c "$JAR" "$BASE/admin/")
[ "$AFTER" = "302" ] && ok "session destroyed after logout" || bad "session destroyed after logout" "$AFTER"
# log back in for the remaining checks
curl -s -c "$JAR" -b "$JAR" "$BASE/admin/?r=login" -o /tmp/login2.html
T2=$(grep -o 'name="_csrf" value="[^"]*"' /tmp/login2.html | head -1 | sed 's/.*value="//;s/"//')
curl -s -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/admin/?r=login" \
  --data-urlencode "_csrf=$T2" --data-urlencode "username=$ADMIN_USER" --data-urlencode "password=$ADMIN_PASS"
OTP2=$(grep -o 'name="_csrf" value="[^"]*"' <<<"$(curl -s -b "$JAR" -c "$JAR" "$BASE/admin/?r=otp")" | head -1 | sed 's/.*value="//;s/"//')
CODE2=$(grep -o 'code=[0-9]*' "$ROOT/storage/otp/otp.log" 2>/dev/null | tail -1 | cut -d= -f2)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$BASE/admin/?r=otp" \
  --data-urlencode "_csrf=$OTP2" --data-urlencode "action=verify" --data-urlencode "code=$CODE2"

echo "== authenticated webhook call =="
SECRET=$(grep '^BALE_WEBHOOK_SECRET=' "$ROOT/.env" | cut -d= -f2- | tr -d '"')
RES=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/bot.php?secret=$SECRET" \
  -H 'Content-Type: application/json' \
  -d '{"update_id":999999999,"message":{"message_id":1,"from":{"id":1},"chat":{"id":1},"text":"/help"}}')
[ "$RES" = "200" ] && ok "valid secret accepted" || bad "valid secret accepted" "$RES"

echo "----------------------------------------"
if [ "$FAIL" -eq 0 ]; then
  printf 'ALL %d SMOKE CHECKS PASSED ✅\n' "$PASS"
else
  printf '%d of %d SMOKE CHECKS FAILED ❌\n' "$FAIL" "$((PASS+FAIL))"
fi
rm -f "$JAR"
[ "$FAIL" -eq 0 ]
