<!-- title: MCP: the SSRF guard blocks MCP servers on the same Docker network and NAT'd public hostnames — admin-controlled trusted-local allowlist, later native STDIO transport -->
<!-- type: Feature -->
<!-- labels: prio:2, security -->
<!-- issue-type: Feature -->

## Summary
An admin-controlled allowlist of hosts / CIDRs that the MCP client may reach although they resolve to private, loopback or link-local addresses; and, as a second step, a native STDIO transport for MCP servers that run as sidecars.

---

## Problem / Motivation
The SSRF guard rejects private, loopback and privately resolved addresses, so a self-hosted Synaplan cannot reach an MCP server on the same Docker network. A public hostname fails too when the host sits behind NAT and cannot hairpin (F1; confirmed on 5.0.6, the 5.2.0 MCP tests used a public endpoint). The guard is the right default — the gap is the missing operator override.

---

## Goal
An operator lists `mcp-filesystem:3000` (or `10.0.0.0/24`) once, with a warning that explains the risk, and users can connect to those servers; everything else stays blocked.

---

## Acceptance criteria
- [ ] `MCP_TRUSTED_HOSTS` (env, comma-separated host[:port] and CIDR entries) plus the same list editable under Admin → AI infrastructure when not env-locked; entries are matched after DNS resolution so a public name resolving to a listed private address is allowed and an unlisted one is still blocked.
- [ ] The MCP server config Test reports "blocked by the private-network guard — ask your administrator to allow <host>" with the resolved address (U8).
- [ ] The allowlist applies to the MCP client only (not to the page reader or webhooks) unless a separate setting says so.
- [ ] Follow-up (own issue when picked up): STDIO transport for sidecar MCP servers defined in compose.
- [ ] Documented in `docs/` next to the MCP server configuration.

---

## Notes
- Findings: F1 — community test round on 5.2.0; the testers have raised it with Synaplan directly. Pairs with the extra-CA-trust issue for internal networks.
- Code: SSRF guard usage in `backend/src/Controller/McpServerConfigController.php` and the shared guard service (also used by `UrlContentService`, `NewsController`, `DropboxClient`, federation).
- Journey (U10): add an MCP server on the compose network → Test says blocked and names the host → admin allows it → Test passes → tools listed → remove from the allowlist → next call fails with the same clear reason.

---

## Screenshots/Logs
—
