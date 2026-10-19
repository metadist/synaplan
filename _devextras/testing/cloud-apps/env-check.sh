#!/usr/bin/env bash
# Is the local stack ready for the cloud file sharing plan? One line per check
# (PASS, WARN, FAIL or SKIP), then a summary. Exit 1 when a check fails.
# Read-only: no check changes state.
#
#   ./env-check.sh [--cloud nextcloud|opencloud|owncloud|all]... [--oidc] [--compute]
#
# What each group checks: _devextras/planning/20261013-cloud-file-sharing/10_local_environments.md §5
set -uo pipefail

usage_error() { echo "$1 (see --help)" >&2; exit 2; }

CLOUDS=() OIDC=0 COMPUTE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --cloud)
      [[ -n ${2:-} ]] || usage_error "--cloud needs nextcloud, opencloud, owncloud or all"
      IFS=, read -ra more <<<"$2"
      for cloud in "${more[@]}"; do
        case "$cloud" in
          nextcloud | opencloud | owncloud | all) CLOUDS+=("$cloud") ;;
          *) usage_error "unknown cloud: '$cloud'" ;;
        esac
      done
      shift 2
      ;;
    --oidc) OIDC=1; shift ;;
    --compute) COMPUTE=1; shift ;;
    -h | --help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) usage_error "unknown option: $1" ;;
  esac
done
[[ " ${CLOUDS[*]} " == *" all "* ]] && CLOUDS=(nextcloud opencloud owncloud)
wants() { [[ " ${CLOUDS[*]} " == *" $1 "* ]]; }

ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
REPOS=${CLOUD_APPS_REPOS:-$(dirname "$ROOT")}
BASE=${SYNAPLAN_BASE_URL:-http://localhost:8000}
EMAIL=${SYNAPLAN_EMAIL:-demo@synaplan.com}
PASSWORD=${SYNAPLAN_PASSWORD:-demo123}
KC=${KEYCLOAK_URL:-http://localhost:8080}
NC=${NEXTCLOUD_URL:-http://localhost:8081}
NC_ADA=${NEXTCLOUD_ADA:-ada:ada-dev-pass}
OC=${OPENCLOUD_URL:-https://host.docker.internal:9200}
OC_API=${OPENCLOUD_API_URL:-http://localhost:9106}
OCO=${OWNCLOUD_URL:-http://localhost:8083}

passed=0 warned=0 failed=0 skipped=0
report() {
  printf '%-4s  %s\n' "$1" "$2"
  case "$1" in
    PASS) passed=$((passed + 1)) ;;
    WARN) warned=$((warned + 1)) ;;
    FAIL) failed=$((failed + 1)) ;;
    SKIP) skipped=$((skipped + 1)) ;;
  esac
}
check() { # message, command...
  local message=$1
  shift
  if "$@" >/dev/null 2>&1; then report PASS "$message"; else report FAIL "$message"; fi
}
running() { [[ $(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null) == true ]]; }
healthy() { [[ $(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$1" 2>/dev/null) == healthy ]]; }
http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@"; }
http_is() { local want=$1; shift; [[ $(http_code "$@") == "$want" ]]; }
json() { python3 -c 'import sys, json; print(json.load(sys.stdin).get(sys.argv[1], ""))' "$1" 2>/dev/null; }
contains() { [[ $1 == *"$2"* ]]; }
db() { docker exec synaplan-db mariadb -usynaplan_user -psynaplan_password synaplan -N -e "$1" 2>/dev/null; }
nc_occ() { docker exec -u www-data synaplan-nextcloud-dev php occ "$@" 2>/dev/null; }

echo "== core"
for name in backend worker scheduler db redis centrifugo qdrant tika mailhog compute; do
  check "container synaplan-$name running" running "synaplan-$name"
done
check "Synaplan API answers ($BASE)" http_is 200 "$BASE/api/v1/config/runtime"
check "PLATFORM_LINKS.ENABLED = 1" test "$(db "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'PLATFORM_LINKS' AND BSETTING = 'ENABLED'")" = 1
check "MailHog API answers (http://localhost:8025)" http_is 200 "http://localhost:8025/api/v2/messages?limit=1"
login_body=$(python3 -c 'import json, sys; print(json.dumps({"email": sys.argv[1], "password": sys.argv[2]}))' "$EMAIL" "$PASSWORD")
check "login as $EMAIL" http_is 200 -X POST "$BASE/api/v1/auth/login" -H 'content-type: application/json' -d "$login_body"

if ((COMPUTE)); then
  compute_url=$(docker exec synaplan-backend printenv COMPUTE_URL 2>/dev/null)
  check "COMPUTE_URL set on the backend" test -n "$compute_url" -a "$compute_url" != disabled
  check "compute sidecar healthy ($compute_url/v1/health)" test \
    "$(docker exec synaplan-backend curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$compute_url/v1/health" 2>/dev/null)" = 200
fi

if ((OIDC)); then
  echo "== oidc"
  check "container synaplan-keycloak healthy" healthy synaplan-keycloak
  check "realm discovery ($KC/realms/synaplan)" http_is 200 "$KC/realms/synaplan/.well-known/openid-configuration"
  admin_token=$(curl -s --max-time 10 -X POST "$KC/realms/master/protocol/openid-connect/token" \
    -d grant_type=password -d client_id=admin-cli -d username=admin -d password=admin | json access_token)
  kc_has() { [[ $(curl -s --max-time 10 -H "Authorization: Bearer $admin_token" "$KC/admin/realms/synaplan/$1") == '[{'* ]]; }
  for client in synaplan-app opencloud synaplan-opencloud nextcloud; do
    check "Keycloak client $client" kc_has "clients?clientId=$client"
  done
  for user in testuser ada bob; do
    check "Keycloak user $user" kc_has "users?username=$user&exact=true"
  done
  check "host.docker.internal resolves on this machine" getent hosts host.docker.internal
  discovery=$(docker exec synaplan-backend printenv OIDC_DISCOVERY_URL 2>/dev/null)
  check "Synaplan has OIDC configured (OIDC_DISCOVERY_URL)" test -n "$discovery"
  check "Synaplan reaches the realm" docker exec synaplan-backend curl -fs --max-time 5 "$discovery/.well-known/openid-configuration"
fi

nc_version=""
if wants nextcloud; then
  echo "== nextcloud"
  check "container synaplan-nextcloud-dev healthy" healthy synaplan-nextcloud-dev
  status=$(curl -s --max-time 10 "$NC/status.php")
  check "Nextcloud installed ($NC)" test "$(json installed <<<"$status")" = True
  nc_version=$(json versionstring <<<"$status")
  info=$(docker exec synaplan-nextcloud-dev cat /mnt/synaplan_integration/appinfo/info.xml 2>/dev/null)
  min=$(grep -oE 'nextcloud min-version="[0-9]+"' <<<"$info" | grep -oE '[0-9]+')
  max=$(grep -oE 'max-version="[0-9]+"' <<<"$info" | grep -oE '[0-9]+')
  major=${nc_version%%.*}
  if [[ -z $major || -z $min || -z $max ]]; then
    report FAIL "Nextcloud version or the app's range unknown"
  elif ((major < min)); then
    report FAIL "Nextcloud $nc_version is below the app's min-version $min"
  elif ((major > max)); then
    report WARN "Nextcloud $nc_version is above the app's max-version $max (enabled with --force until CF1.3a)"
  else
    report PASS "Nextcloud $nc_version within the app's range $min–$max"
  fi
  check "synaplan_integration enabled" contains "$(nc_occ app:list --enabled --output=json)" '"synaplan_integration"'
  check "background jobs run by system cron" test "$(nc_occ config:app:get core backgroundjobs_mode)" = cron
  check "container synaplan-nextcloud-dev-cron running" running synaplan-nextcloud-dev-cron
  check "allow_local_remote_servers = true" test "$(nc_occ config:system:get allow_local_remote_servers)" = true
  for user in ada bob carol; do
    check "Nextcloud user $user" nc_occ user:info "$user"
  done
  listing=""
  for dir in "" Projects/ HR/; do
    listing+=$(curl -s --max-time 10 -u "$NC_ADA" -X PROPFIND -H 'Depth: 1' "$NC/remote.php/dav/files/ada/$dir")
  done
  missing=""
  for folder in Specs Incoming-DE Incoming-EN Reports Invoices Exports Projects/Q3 HR/Contracts; do
    contains "$listing" "/files/ada/$folder/<" || missing+=" $folder"
  done
  if [[ -z $missing ]]; then report PASS "ada has the eight test folders"; else report FAIL "ada lacks:$missing"; fi
  check "Nextcloud reaches Synaplan (http://backend)" docker exec synaplan-nextcloud-dev curl -fs --max-time 5 http://backend/api/v1/config/runtime
  check "Synaplan reaches Nextcloud (http://nextcloud)" docker exec synaplan-backend curl -fs --max-time 5 http://nextcloud/status.php
  if ((OIDC)); then
    check "organisation login: user_oidc provider keycloak" contains "$(nc_occ user_oidc:provider)" keycloak
  fi
fi

oc_version=""
if wants opencloud; then
  echo "== opencloud"
  oc_status=$(curl -sk --max-time 10 "$OC/status.php")
  check "OpenCloud answers ($OC)" test "$(json installed <<<"$oc_status")" = True
  oc_version=$(json productversion <<<"$oc_status")
  check "OpenCloud signs in through the Keycloak realm" contains "$(curl -sk --max-time 10 "$OC/.well-known/openid-configuration")" realms/synaplan
  check "the Synaplan service asks for a login ($OC_API/api/synaplan/me → 401)" http_is 401 "$OC_API/api/synaplan/me"
  check "Synaplan reaches the Synaplan service (host.docker.internal:9106)" test \
    "$(docker exec synaplan-backend curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://host.docker.internal:9106/api/synaplan/me 2>/dev/null)" = 401
  check "web extension built (frontend/dist)" test -f "$REPOS/synaplan-opencloud/frontend/dist/manifest.json"
  check "E2E prerequisites (frontend/node_modules)" test -d "$REPOS/synaplan-opencloud/frontend/node_modules"
fi

if wants owncloud; then
  echo "== owncloud"
  check "ownCloud.online installed ($OCO, stack E-5)" test "$(curl -s --max-time 10 "$OCO/status.php" | json installed)" = True
fi

echo "== fixture"
if docker inspect cloud-fixture >/dev/null 2>&1; then
  check "the reference client refuses an unsigned notification" test \
    "$(docker exec synaplan-backend curl -s -o /dev/null -w '%{http_code}' -X POST --max-time 5 http://cloud-fixture:8099/inbound 2>/dev/null)" = 401
else
  report SKIP "reference client cloud-fixture not built yet (E-9)"
fi

echo "== versions"
echo "Synaplan   $(git -C "$ROOT" describe --tags --always 2>/dev/null)"
((OIDC)) && echo "Keycloak   $(docker inspect -f '{{.Config.Image}}' synaplan-keycloak 2>/dev/null | sed 's/@.*//')"
if wants nextcloud; then
  echo "Nextcloud  ${nc_version:-?}, app $(grep -oE '<version>[^<]+' <<<"$info" | head -1 | cut -d'>' -f2)"
  ((OIDC)) && echo "user_oidc  $(nc_occ app:list --output=json | python3 -c 'import sys, json; print(json.load(sys.stdin)["enabled"].get("user_oidc", "not enabled"))' 2>/dev/null)"
fi
wants opencloud && echo "OpenCloud  ${oc_version:-?}, app $(git -C "$REPOS/synaplan-opencloud" describe --tags --always 2>/dev/null)"

printf '\n%d passed, %d warnings, %d failed, %d skipped\n' "$passed" "$warned" "$failed" "$skipped"
((failed == 0))
