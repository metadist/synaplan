<!-- title: Setup: trust an extra certificate authority for internal AI servers, MCP servers, webhooks and WebDAV — no setting exists today -->
<!-- type: Feature -->
<!-- labels: prio:2, area:setup, area:models, security -->
<!-- issue-type: Feature -->

## Summary
A documented way to make Synaplan trust an internal certificate authority for the clients that talk to internal AI servers, MCP, webhooks, and WebDAV: an `EXTRA_CA_CERTS` PEM merged with the public root bundle and used only by those clients, then an admin field to paste a CA. Not the OS trust store, and not a "skip verification" switch.

---

## Problem / Motivation
Adding a local GPU embedding server as an OpenAI-compatible endpoint fails its Test with "unable to get local issuer certificate": the server uses an internal CA that Synaplan's containers do not trust, and there is no per-endpoint option to supply a CA certificate. The error message itself is clear (F21). The same wall hits MCP servers, webhooks, WebDAV and Nextcloud connections on internal networks. Open WebUI offers `AIOHTTP_CLIENT_SSL_CERT_FILE` and per-connection-type SSL settings. Self-hosted customers routinely run model servers behind an internal CA.

---

## Goal
An operator points Synaplan at an internal HTTPS service signed by their own CA and the Test passes — without disabling verification.

---

## Acceptance criteria
- [ ] Step 1: `EXTRA_CA_CERTS=/path/to/extra.pem` is read at boot. Build a combined bundle (the public root store plus that PEM) and pass the combined file as `cafile` only on the HttpClient instances used for OpenAI-compatible endpoints, MCP, webhooks, and WebDAV/Nextcloud. A private-only PEM as `cafile` replaces the public roots and breaks public endpoints. It is **not** installed into the container's system trust store — that would also trust the CA for billing, OIDC, and mail. `deploy/compose.yaml` and `deploy/selfhost.env.example` carry a commented mount example; `docs/CONFIGURATION.md` documents it.
- [ ] Step 2: Admin → AI infrastructure → "Trusted certificate authorities": paste PEM, stored encrypted, applied to the same clients, with "Test against <endpoint>".
- [ ] "Skip TLS verification" is not part of this issue. Do not add it.
- [ ] The endpoint Test error names a private root when the chain ends in one and links to the doc ("Your server's certificate is signed by an internal authority — add it under Trusted certificate authorities").
- [ ] A unit test with a self-signed test CA proves step 1 (PHPUnit with a local TLS fixture or a mocked client option check).

---

## Notes
- Findings: F21 — community test round on 5.2.0; comparison §"Feature request: connecting internal AI servers with private certificates".
- Review correction (planning PR #2378): Symfony HttpClient `cafile` replaces the default bundle. Scope the combined bundle to the internal clients. Do not install the CA into the OS trust store, and do not add a skip-verification switch.
- Verified in code: no `cafile`, `capath`, `EXTRA_CA_*` or per-endpoint TLS option in `backend/src`, `backend/config`, `deploy/compose.yaml`, `deploy/selfhost.env.example`. Endpoints: `backend/src/AI/Credential/OpenAiCompatibleEndpointRegistry.php`; Symfony HttpClient is configured in `backend/config/packages/framework.yaml` (`http_client.default_options`).
- Journey (U10): mount a bundle, set `EXTRA_CA_CERTS` → restart → endpoint Test passes → remove the variable → Test fails with the named reason and the doc link.

---

## Screenshots/Logs
Endpoint test (5.2.0): "SSL certificate problem: unable to get local issuer certificate".
