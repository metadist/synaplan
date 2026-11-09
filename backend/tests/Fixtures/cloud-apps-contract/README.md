# Cloud-apps contract

The Synaplan routes the cloud apps call with a person's API key — the key a
linked or provisioned Nextcloud or ownCloud.online user carries, scoped
`chat files rag` plus `memories` when the app's admin enabled memories — and
the scopes that grant each route. OpenCloud signs people in with organisation
login tokens, which API-key scopes do not restrict.

`ApiKeyScopeTest` reads `paths.json`: every route is reachable with each of its
scopes, and the other platform scopes do not reach it.

Change this file together with the apps. A route an app starts calling is added
in the same change as the scope that grants it; removing a route or narrowing
its scopes breaks app versions already installed.

Source: `synaplan-nextcloud` 1.6.0 (`a80e2d8`) and `synaplan-owncloud-online`
1.0.0 (`3b916f6`) — `lib/Service/SynaplanClient.php` and
`lib/Controller/ChatController.php` in both.

The folder becomes the frozen contract v1 of the cloud file sharing plan
(`_devextras/planning/20261013-cloud-file-sharing/`, step CF5.10).

`notify-vectors.json` is the signed-notification fixture. A notification is a
JSON Web Token in compact JWS form (`typ: synaplan-notification+jwt`), EdDSA
(Ed25519) with a key from Synaplan's JWK Set (`/.well-known/jwks.json`),
claims `iss` (Synaplan's public URL), `aud` (instance id), `sub` (person),
`type`, `id`, `iat`, `exp`, `jti`. A cloud reads the issuer it expects from
`/.well-known/synaplan-configuration` at the address it reaches Synaplan by,
and never fetches keys from a URL inside a token. Each cloud app verifies the
tokens in its own language. The PHP check is `NotifyVectorTest`.
