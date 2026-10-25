#!/usr/bin/env bash
# GreenPoints (System 1) <-> School Registry (System 2): the scripted integration demo.
# Prints every request and response so it can be screenshotted.
#
#   scripts/demo-integration.sh
#
# Settings (environment variables; defaults in brackets):
#   GP_URL          GreenPoints backend                 [http://localhost:8000]
#   REGISTRY_URL    School Registry                     [SCHOOL_REGISTRY_URL from .env]
#   REGISTRY_KEY    registry read key, never printed    [SCHOOL_REGISTRY_KEY from .env]
#   GP_ADMIN_TOKEN  optional admin bearer token, to show GET /api/v1/admin/registry/health
#   ACTIVE_ID [2024-00309]  INACTIVE_ID [2026-00109]  UNKNOWN_ID [2026-99999]  OUTAGE_ID [2026-00101]
#
# Needs curl and python3. Each registration creates a real account, so ACTIVE_ID can only be
# registered once; see docs/integration.md for how to reset or pick another seeded id.
set -euo pipefail
cd "$(dirname "$0")/.."

from_env() { [ -f .env ] && grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }

GP_URL=${GP_URL:-http://localhost:8000}
REGISTRY_URL=${REGISTRY_URL:-$(from_env SCHOOL_REGISTRY_URL)}
REGISTRY_URL=${REGISTRY_URL%/}
REGISTRY_KEY=${REGISTRY_KEY:-$(from_env SCHOOL_REGISTRY_KEY)}
ACTIVE_ID=${ACTIVE_ID:-2024-00309}
INACTIVE_ID=${INACTIVE_ID:-2026-00109}
UNKNOWN_ID=${UNKNOWN_ID:-2026-99999}
OUTAGE_ID=${OUTAGE_ID:-2026-00101}

if [ -z "$REGISTRY_URL" ] || [ -z "$REGISTRY_KEY" ]; then
  echo "Set REGISTRY_URL and REGISTRY_KEY (or SCHOOL_REGISTRY_URL / SCHOOL_REGISTRY_KEY in .env)." >&2
  exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

step() { printf '\n\033[1;32m=== %s\033[0m\n' "$*"; }
note() { printf '\033[2m%s\033[0m\n' "$*"; }

# Pretty-print JSON (hiding any "token" value); print anything else (XML) as it is.
pretty() {
  python3 -c 'import json, sys
text = sys.stdin.read()
try:
    data = json.loads(text)
except ValueError:
    print(text.rstrip())
    sys.exit()
if isinstance(data, dict) and "token" in data:
    data["token"] = "•••••• (API token, hidden)"
print(json.dumps(data, indent=2, ensure_ascii=False))'
}

# Secrets are shown as placeholders.
mask() { sed -E 's/^(> X-API-Key: ).*/\1•••••• (registry read key, hidden)/; s/^(> Authorization: Bearer ).*/\1•••••• (hidden)/'; }

# request METHOD URL BODY [HEADER...]: send it, print the request (">") and the response ("<").
request() {
  local method=$1 url=$2 body=$3
  shift 3
  local headers=("Accept: application/json" "X-Request-Id: demo-$(date +%H%M%S)-$RANDOM" "$@")
  local args=(-sS -X "$method" -D "$tmp/headers" -o "$tmp/body")

  printf '> %s %s\n' "$method" "$url"
  for h in "${headers[@]}"; do
    args+=(-H "$h")
    printf '> %s\n' "$h" | mask
  done
  if [ -n "$body" ]; then
    args+=(-H "Content-Type: application/json" --data "$body")
    printf '> Content-Type: application/json\n>\n'
    pretty <<<"$body" | sed 's/^/> /'
  fi
  echo

  : >"$tmp/headers"; : >"$tmp/body"
  if ! curl "${args[@]}" "$url" 2>"$tmp/err"; then
    printf '< (no response: %s)\n' "$(cat "$tmp/err")"
    return 0
  fi
  tr -d '\r' <"$tmp/headers" | grep -iE '^(HTTP/|content-type:|x-request-id:|location:|retry-after:)' | sed 's/^/< /'
  echo '<'
  pretty <"$tmp/body"
}

register_body() { # student_id
  printf '{"student_id":"%s","name":"Typed In The Form","email":"demo-%s@student.test","password":"demo-pass-%s","password_confirmation":"demo-pass-%s"}' \
    "$1" "$1" "$1" "$1"
}

pause() { printf '\n\033[1;33m>>> %s\033[0m ' "$*"; read -r _; [ -t 0 ] || echo; }

echo "GreenPoints (System 1): $GP_URL"
echo "School Registry (System 2): $REGISTRY_URL"

step "1. System 2 health (no API key needed)"
request GET "$REGISTRY_URL/health" ""

if [ -n "${GP_ADMIN_TOKEN:-}" ]; then
  step "2. System 1's view of the integration (admin only)"
  request GET "$GP_URL/api/v1/admin/registry/health" "" "Authorization: Bearer $GP_ADMIN_TOKEN"
else
  step "2. System 1's view of the integration"
  note "(skipped: set GP_ADMIN_TOKEN to call GET /api/v1/admin/registry/health)"
fi

step "3. System 2 directly: look up a student (REST/JSON, API key)"
request GET "$REGISTRY_URL/api/v1/students/$ACTIVE_ID" "" "X-API-Key: $REGISTRY_KEY"

step "4. System 2 directly: section roster as XML"
request GET "$REGISTRY_URL/api/v1/sections/IT1A/students?status=Inactive&format=xml" "" "X-API-Key: $REGISTRY_KEY"

step "5. TC-01 Register an Active student on System 1 (it calls System 2 first)"
note "The name typed in the form is ignored: System 2 is the source of truth."
request POST "$GP_URL/api/v1/auth/register" "$(register_body "$ACTIVE_ID")"

step "6. TC-02 Register with a student id System 2 does not know"
request POST "$GP_URL/api/v1/auth/register" "$(register_body "$UNKNOWN_ID")"

step "7. Register an Inactive student"
request POST "$GP_URL/api/v1/auth/register" "$(register_body "$INACTIVE_ID")"

step "8. System 2 is down"
pause "Stop the School Registry now, then press Enter."
request POST "$GP_URL/api/v1/auth/register" "$(register_body "$OUTAGE_ID")"
note "No account was created; registration works again once System 2 is back."
pause "Start the School Registry again, then press Enter."
request GET "$REGISTRY_URL/health" ""

echo
echo "Done."
