# OpenCloud token-exchange identity (IAM34)

A user who signs in through the browser and the same user who arrives via
OpenCloud’s RFC 8693 token exchange must be **one** Synaplan account. Groups
and shares follow that account.

## Automated check

`backend/tests/Integration/OidcBearerIdentityTest.php` calls
`OidcUserService::findOrCreateFromClaims()` twice with the same Keycloak `sub`
— once with a refresh token (browser login) and once without (bearer path).
Both calls must return the same `BUSER` and a single `BEXTERNALIDENTITIES` row.

```bash
docker compose exec -T backend ./vendor/bin/phpunit tests/Integration/OidcBearerIdentityTest.php
```

## Manual runbook (live OpenCloud)

1. Start Synaplan with the OIDC profile and the OpenCloud dev stack:

   ```bash
   docker compose --profile oidc up -d
   # then the synaplan-opencloud compose as in that repo’s README
   ```

2. In Keycloak, put the test user in group `sales`.
3. Turn on `IAM.GROUPS_ENABLED`, `IAM.SHARING_ENABLED`, and
   `IAM.DIRECTORY_SYNC_ENABLED`.
4. Sign in to Synaplan in the browser as that user. Confirm **Account → My
   groups** lists `sales`, and that a folder shared with `sales` appears under
   **Shared with me**.
5. Open the OpenCloud Synaplan panel (token-exchanged bearer). The same user
   must see the same groups and the same shared folder — not a second account.
6. `GET /api/v1/groups/mine` and `GET /api/v1/me/shared?kind=knowledge_folder`
   from both sessions must agree.
