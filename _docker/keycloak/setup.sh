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

# Helper: extract a JSON field value using grep/sed (no python3/jq in this image).
# Usage: json_field "fieldName" < json_input
json_field() {
  grep -o "\"$1\" *: *\"[^\"]*\"" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/'
}

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

# Resolve the synaplan-app client's internal UUID once and reuse it
# below for the audience mapper and the token-exchange permissions.
SYNAPLAN_CLIENT_UUID=$($KCADM get "clients?clientId=${CLIENT_ID}" -r synaplan --fields id | json_field id)

if [ -z "${SYNAPLAN_CLIENT_UUID}" ]; then
  echo "ERROR: Could not resolve synaplan client UUID (clientId=${CLIENT_ID})" >&2
  exit 1
fi

# Hardcoded audience mapper so every token issued for this client (ROPC,
# auth-code flow, etc.) carries aud=${CLIENT_ID}. JwtValidator validates
# aud strictly against OIDC_CLIENT_ID and there's no azp fallback, so
# without this mapper any direct grant produces aud=account and Synaplan
# rejects the token. Token exchange works around this with its own
# audience parameter, but direct grants (E2E test fixtures, ROPC-based
# integrations) need the client itself to advertise the audience.
$KCADM create "clients/${SYNAPLAN_CLIENT_UUID}/protocol-mappers/models" -r synaplan \
  -s name=synaplan-app-audience \
  -s protocol=openid-connect \
  -s protocolMapper=oidc-audience-mapper \
  -s 'config."included.client.audience"='"${CLIENT_ID}" \
  -s 'config."access.token.claim"=true' \
  -s 'config."id.token.claim"=false' \
  -s 'config."introspection.token.claim"=true'

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
OC_CALLBACK="${KC_OC_CALLBACK:-https://host.docker.internal:9200/oidc-callback.html}"
OC_ORIGIN="${KC_OC_ORIGIN:-https://host.docker.internal:9200}"
OC_DEV_ORIGIN="${KC_OC_DEV_ORIGIN:-https://host.docker.internal:9201}"
EXCHANGE_CLIENT_ID="${KC_EXCHANGE_CLIENT_ID:-synaplan-opencloud}"
EXCHANGE_CLIENT_SECRET="${KC_EXCHANGE_CLIENT_SECRET:-synaplan-opencloud-secret}"

# Public OIDC client for OpenCloud (users authenticate via this client)
$KCADM create clients -r synaplan \
  -s clientId="${OC_CLIENT_ID}" \
  -s enabled=true \
  -s publicClient=true \
  -s standardFlowEnabled=true \
  -s directAccessGrantsEnabled=true \
  -s "redirectUris=[\"${OC_CALLBACK}\",\"${OC_ORIGIN}/*\",\"${OC_DEV_ORIGIN}/*\"]" \
  -s "webOrigins=[\"${OC_ORIGIN}\",\"${OC_DEV_ORIGIN}\"]" \
  -s 'attributes={"pkce.code.challenge.method":"S256","post.logout.redirect.uris":"'"${OC_ORIGIN}/*+${OC_DEV_ORIGIN}/*"'"}'

# Hardcoded audience mapper for the opencloud client — same rationale as
# the synaplan-app one above. Tokens minted for this client now carry
# aud=${OC_CLIENT_ID} instead of relying on aud=account from the default
# Audience Resolve mapper. Token exchange continues to work because the
# source token's aud is irrelevant — the target token's audience is set
# explicitly via the audience parameter on the exchange grant.
OC_CLIENT_UUID=$($KCADM get "clients?clientId=${OC_CLIENT_ID}" -r synaplan --fields id | json_field id)

if [ -z "${OC_CLIENT_UUID}" ]; then
  echo "ERROR: Could not resolve opencloud client UUID (clientId=${OC_CLIENT_ID})" >&2
  exit 1
fi

$KCADM create "clients/${OC_CLIENT_UUID}/protocol-mappers/models" -r synaplan \
  -s name=opencloud-audience \
  -s protocol=openid-connect \
  -s protocolMapper=oidc-audience-mapper \
  -s 'config."included.client.audience"='"${OC_CLIENT_ID}" \
  -s 'config."access.token.claim"=true' \
  -s 'config."id.token.claim"=false' \
  -s 'config."introspection.token.claim"=true'

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
# (SYNAPLAN_CLIENT_UUID was resolved earlier when creating the audience mapper.)

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
