#!/bin/bash
# Keycloak realm provisioning script
# Used by both dev and test docker-compose keycloak_setup services.
#
# Environment variables:
#   KC_SERVER        - Keycloak server URL (e.g., http://keycloak:8080)
#   KC_CALLBACK      - OAuth callback URL (e.g., http://localhost:8000/api/v1/auth/keycloak/callback)
#   KC_ORIGIN        - Allowed web origin (e.g., http://localhost:8000)
#   KC_CLIENT_ID     - OIDC client ID (default: synaplan-app)
#   KC_CLIENT_SECRET - OIDC client secret (default: test-oidc-secret)

set -e

KCADM="/opt/keycloak/bin/kcadm.sh"
CLIENT_ID="${KC_CLIENT_ID:-synaplan-app}"
CLIENT_SECRET="${KC_CLIENT_SECRET:-test-oidc-secret}"

$KCADM config credentials \
  --server "${KC_SERVER}" \
  --realm master --user admin --password admin

$KCADM create realms \
  -s realm=synaplan -s enabled=true \
  -s loginWithEmailAllowed=true

$KCADM create clients -r synaplan \
  -s clientId="${CLIENT_ID}" \
  -s enabled=true \
  -s publicClient=false \
  -s secret="${CLIENT_SECRET}" \
  -s standardFlowEnabled=true \
  -s directAccessGrantsEnabled=true \
  -s "redirectUris=[\"${KC_CALLBACK}\"]" \
  -s "webOrigins=[\"${KC_ORIGIN}\"]" \
  -s 'attributes={"pkce.code.challenge.method":"S256","post.logout.redirect.uris":"'"${KC_ORIGIN}"'/*"}'

$KCADM create users -r synaplan \
  -s username=testuser \
  -s email=testuser@synaplan.com \
  -s emailVerified=true \
  -s enabled=true \
  -s firstName=Test \
  -s lastName=User

$KCADM set-password -r synaplan \
  --username testuser --new-password testpass123

# Create "administrator" realm role and assign to test user
# This exercises the configurable OIDC_ADMIN_ROLES flow in E2E tests
$KCADM create roles -r synaplan -s name=administrator
$KCADM add-roles -r synaplan --uusername testuser --rolename administrator

# --- OpenCloud integration clients (for synaplan-opencloud) ---
# These are created here pragmatically so both Synaplan and OpenCloud
# share the same Keycloak realm without maintaining separate setups.
# Requires Keycloak to run with: KC_FEATURES=token-exchange,admin-fine-grained-authz

OC_CLIENT_ID="${KC_OC_CLIENT_ID:-opencloud}"
OC_CALLBACK="${KC_OC_CALLBACK:-https://host.docker.internal:9201/oidc-callback.html}"
OC_ORIGIN="${KC_OC_ORIGIN:-https://host.docker.internal:9201}"
EXCHANGE_CLIENT_ID="${KC_EXCHANGE_CLIENT_ID:-synaplan-opencloud}"
EXCHANGE_CLIENT_SECRET="${KC_EXCHANGE_CLIENT_SECRET:-synaplan-opencloud-secret}"

# Public OIDC client for OpenCloud (users authenticate via this client)
$KCADM create clients -r synaplan \
  -s clientId="${OC_CLIENT_ID}" \
  -s enabled=true \
  -s publicClient=true \
  -s standardFlowEnabled=true \
  -s directAccessGrantsEnabled=true \
  -s "redirectUris=[\"${OC_CALLBACK}\",\"${OC_ORIGIN}/*\"]" \
  -s "webOrigins=[\"${OC_ORIGIN}\"]" \
  -s 'attributes={"pkce.code.challenge.method":"S256","post.logout.redirect.uris":"'"${OC_ORIGIN}"'/*"}'

# Confidential client for the synaplan-opencloud backend (token exchange)
$KCADM create clients -r synaplan \
  -s clientId="${EXCHANGE_CLIENT_ID}" \
  -s enabled=true \
  -s publicClient=false \
  -s secret="${EXCHANGE_CLIENT_SECRET}" \
  -s standardFlowEnabled=false \
  -s directAccessGrantsEnabled=false \
  -s serviceAccountsEnabled=true

# Configure token exchange permissions using kcadm.sh.
# Helper: extract a JSON field value using grep/sed (no python3/jq in this image).
json_field() {
  # Usage: json_field "fieldName" < json_input
  # Returns the value of the first occurrence of "fieldName" : "value"
  grep -o "\"$1\" *: *\"[^\"]*\"" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/'
}

# Get the internal UUID of the synaplan-app client
SYNAPLAN_CLIENT_UUID=$($KCADM get "clients?clientId=${CLIENT_ID}" -r synaplan --fields id | json_field id)

if [ -z "${SYNAPLAN_CLIENT_UUID}" ]; then
  echo "ERROR: Could not resolve synaplan client UUID (clientId=${CLIENT_ID})" >&2
  exit 1
fi

# Enable fine-grained permissions on the synaplan-app client.
$KCADM update "clients/${SYNAPLAN_CLIENT_UUID}/management/permissions" -r synaplan \
  -s enabled=true

EXCHANGE_CLIENT_UUID=$($KCADM get "clients?clientId=${EXCHANGE_CLIENT_ID}" -r synaplan --fields id | json_field id)
REALM_MGMT_UUID=$($KCADM get "clients?clientId=realm-management" -r synaplan --fields id | json_field id)

if [ -z "${EXCHANGE_CLIENT_UUID}" ] || [ -z "${REALM_MGMT_UUID}" ]; then
  echo "ERROR: Could not resolve exchange client (${EXCHANGE_CLIENT_ID}) or realm-management UUID" >&2
  exit 1
fi

$KCADM create "clients/${REALM_MGMT_UUID}/authz/resource-server/policy/client" -r synaplan \
  -s name=synaplan-opencloud-exchange-policy \
  -s "description=Allow synaplan-opencloud to perform token exchange" \
  -s "clients=[\"${EXCHANGE_CLIENT_UUID}\"]" \
  -s logic=POSITIVE 2>/dev/null && echo "Created token exchange policy" || echo "Policy already exists"

POLICY_UUID=$($KCADM get "clients/${REALM_MGMT_UUID}/authz/resource-server/policy?name=synaplan-opencloud-exchange-policy" -r synaplan --fields id | json_field id)
PERM_NAME="token-exchange.permission.client.${SYNAPLAN_CLIENT_UUID}"
TOKEN_EXCHANGE_PERM=$($KCADM get "clients/${REALM_MGMT_UUID}/authz/resource-server/permission?name=${PERM_NAME}" -r synaplan --fields id | json_field id)

if [ -z "${TOKEN_EXCHANGE_PERM}" ] || [ -z "${POLICY_UUID}" ]; then
  echo "ERROR: Could not resolve token-exchange permission or policy (PERM=${TOKEN_EXCHANGE_PERM}, POLICY=${POLICY_UUID})" >&2
  exit 1
fi

$KCADM update "clients/${REALM_MGMT_UUID}/authz/resource-server/permission/scope/${TOKEN_EXCHANGE_PERM}" -r synaplan \
  -s "name=${PERM_NAME}" \
  -s decisionStrategy=AFFIRMATIVE \
  -s "policies=[\"${POLICY_UUID}\"]"

echo "Token exchange permission configured for ${EXCHANGE_CLIENT_ID} -> ${CLIENT_ID}"

echo "OpenCloud integration clients provisioned"
echo "  OpenCloud client: ${OC_CLIENT_ID}"
echo "  Token exchange client: ${EXCHANGE_CLIENT_ID}"

echo "Keycloak provisioning complete"
