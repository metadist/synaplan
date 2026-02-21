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

echo "Keycloak provisioning complete"
