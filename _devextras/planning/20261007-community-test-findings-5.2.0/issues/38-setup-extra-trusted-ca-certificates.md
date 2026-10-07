<!-- title: Setup: trust an extra certificate authority for internal AI servers, MCP servers, webhooks and WebDAV — no setting exists today -->
<!-- type: Feature -->
<!-- labels: prio:2, area:setup, area:models, security -->
<!-- issue-type: Feature -->

## Summary
A documented way to make Synaplan's outbound HTTP trust an internal certificate authority: smallest first, an `EXTRA_CA_CERTS` PEM path applied to every outbound HttpClient and to curl in the PHP containers with a compose example that mounts the file; then an admin field to paste a CA certificate; then per-endpoint TLS options for OpenAI-compatible endpoints and MCP servers.

---

## Problem / Motivation
Adding a local GPU embedding server as an OpenAI-compatible endpoint fails its Test with "unable to get local issuer certificate": the server uses an internal CA that Synaplan's containers do not trust, and there is no per-endpoint option to supply a CA certificate. The error message itself is clear (F21). The same wall hits MCP servers, webhooks, WebDAV and Nextcloud connections on internal networks. Open WebUI offers `AIOHTTP_CLIENT_SSL_CERT_FILE` and per-connection-type SSL settings. Self-hosted customers routinely run model servers behind an internal CA.

---

## Goal
An operator points Synaplan at an internal HTTPS service signed by their own CA and the Test passes — without disabling verification.

---

## Acceptance criteria
- [ ] Step 1: `EXTRA_CA_CERTS=/path/to/bundle.pem` is read at boot; the bundle is appended to the trust used by Symfony HttpClient (`cafile` default option) and by `curl` / PHP streams in the container (e.g. appended to the system store by the entrypoint); `deploy/compose.yaml` and `deploy/selfhost.env.example` carry a commented example; `docs/CONFIGURATION.md` documents it.
- [ ] Step 2: Admin → AI infrastructure → "Trusted certificate authorities": paste PEM, stored encrypted, applied the same way, with "Test against <endpoint>".
- [ ] Step 3: per-endpoint option on OpenAI-compatible endpoints and MCP servers: default trust, or this CA; "skip verification" only as admin-only with a red warning, never default.
- [ ] The endpoint Test error names a private root when the chain ends in one and links to the doc ("Your server's certificate is signed by an internal authority — add it under Trusted certificate authorities").
- [ ] A unit test with a self-signed test CA proves step 1 (PHPUnit with a local TLS fixture or a mocked client option check).

---

## Notes
- Findings: F21 — community test round on 5.2.0; comparison §"Feature request: connecting internal AI servers with private certificates".
- Verified in code: no `cafile`, `capath`, `EXTRA_CA_*` or per-endpoint TLS option in `backend/src`, `backend/config`, `deploy/compose.yaml`, `deploy/selfhost.env.example`. Endpoints: `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php`; Symfony HttpClient is configured in `backend/config/packages/framework.yaml` (`http_client.default_options`).
- Journey (U10): mount a bundle, set `EXTRA_CA_CERTS` → restart → endpoint Test passes → remove the variable → Test fails with the named reason and the doc link.

---

## Screenshots/Logs
Endpoint test (5.2.0): "SSL certificate problem: unable to get local issuer certificate".
