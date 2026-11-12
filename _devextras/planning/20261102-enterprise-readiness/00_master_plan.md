# Enterprise readiness — what a company or a public body asks before it buys

Synaplan wants more users and, above all, companies and public bodies that
book it. A market analysis on 2026-11-02 compared Synaplan's `main` with
Langdock, Open WebUI, LibreChat, Dify, Onyx, AnythingLLM, nele.ai,
moin.ai, Intercom Fin, Microsoft Copilot and ChatGPT Business, and read
two real public tenders for a generative AI platform. The product is broad
and sovereign: many providers, local models, RAG, a chat widget, mail,
WhatsApp, Telegram, agents with approvals, federation, openDesk,
Kubernetes. Deals are lost on the purchase checklist, not on features.
Buyers ask about company sign-in, provisioning, audit evidence, the AI Act,
certification and the tools their staff already use.

This plan is the **umbrella** (row 34). It owns no code. It does four things:

- holds the buyer's checklist (§1) with the state on `main` and the track that closes each gap;
- registers eight engineering tracks as rows 35–42 (§3), each with its own folder, master plan and `STATUS.md`;
- plans the work outside this repository's code (§4): trust center, supply chain, certification, public sector, partners, distribution;
- fixes one order (§6), driven by the one legal deadline, **2026-12-02**.

Security-sensitive findings are not written here. They go to
`synaplan-platform/planning/` (private) until fixed and released, as row
29 does.

## 1. The buyer's checklist (checked 2026-11-02 against `main`)

Sources:

- Landkreis Rotenburg (Wümme), "Beschaffung einer generativen KI-Plattform": published 2026-08-03, 500–2,500 users, 48 months ([tendigo](https://tendigo.de/ausschreibung/beschaffung-einer-generativen-ki-plattform-767f8f107e));
- the LWL tender "KI-Assistenzplattform": deadline 2026-11-23;
- Langdock's Business plan;
- the AI Act;
- Bitkom 2026: 57 % of companies use AI. They are held back by missing know-how (85 %), legal uncertainty (66 %) and cost (63 %). 93 % prefer an AI from Germany.

State: ✓ present, ~ partial, ✗ missing.

| # | Requirement (as buyers write it) | State on `main` | Evidence | Closed by |
| - | -------------------------------- | --------------- | -------- | --------- |
| C1 | Single sign-on with the company directory (Entra ID, Okta, Keycloak, ADFS) | ~ OIDC only | `backend/src/Service/Auth/OidcUserService.php`, `OidcAccessPolicy.php`; no SAML code anywhere | row 35 (SAML) |
| C2 | Users and groups provisioned and **de-provisioned** by IT ("on-/offboarding only by the IT department") | ~ directory groups are synced at sign-in; a person who left keeps an active account until someone deletes it | `Service/Iam/DirectoryGroupSync.php`; no SCIM endpoint | row 35 (SCIM) |
| C3 | Two-factor authentication for local accounts | ✗ | no TOTP or WebAuthn code in `backend/src` | row 35 |
| C4 | Role-based permissions with organisational units | ✓ groups, roles, group policies (allowed models, features, rate tier) | `Entity/Group.php` (`BKIND`, `BPARENTID`), `Service/Iam/Policy/PolicyAllowList.php` | — (row 35 maps SCIM groups onto it) |
| C5 | Audit trail an auditor or SOC can read, export and keep | ~ `BAUDITLOG` with retention and a list endpoint; no export, no streaming; sign-in, sign-out, failed sign-in, settings, API keys, model changes and data exports are not recorded | `Entity/AuditLogEntry.php`, `Controller/AdminAuditController.php`, `Command/ReapAuditLogCommand.php`; action names in `AuditLogWriter::record()` callers | row 37 |
| C6 | Usage and cost per department, exportable | ~ per user and per account only | `Service/Usage/AdminUsageService.php`, `Controller/UsageStatsController.php` (`/export` is per account) | row 37 |
| C7 | AI Act Art. 50(1): people know they talk to an AI | ~ the widget shows "This chat is AI-powered"; mail, WhatsApp and Telegram answers say nothing | `frontend/src/components/widgets/ChatWidget.vue:221`, `i18n/locales/en/widgets.json:143` | row 36 |
| C8 | AI Act Art. 50(2): generated images, audio and video are marked machine-readably | ✗ | no C2PA or IPTC code; `Service/Message/Handler/Media/` stores provider bytes | row 36 |
| C9 | AI Act Art. 4: staff are AI-literate, and the deployer can prove it | ✗ | — | row 36 |
| C10 | AVV / DPA under Art. 28 GDPR, TOMs under Art. 32, list of sub-processors | ✗ public; offers in `synaplan-internal` | — | §4 ER1 |
| C11 | Hosting and model processing only in the EEA or Switzerland; zero data retention; no training on inputs | ✓ technically (self-host, EU providers, local models, Swiss repo) / ~ not shown per answer | `docs/EGRESS.md`; use-case research Q8 "sovereignty as a job-wide setting" | row 41 (per-group "local only") + ER1 (evidence) |
| C12 | **BSI C5 attestation** (Type 1 or a plan to finish within one year; "ISO 27001 alone is not enough") | ✗ | — | §4 ER3 |
| C13 | Separation between tenants (county / municipalities) and between production and test | ~ one install per tenant, federation between installs | `Service/Federation/` | §4 ER5 (decision D6) |
| C14 | AI incident reporting both ways with an escalation path | ✗ documented process | `SECURITY.md` covers vulnerabilities only | §4 ER1 |
| C15 | At least four model providers, two large current ones, vendor-neutral | ✓ | `docs/PROVIDERS.md` | — |
| C16 | Art. 9 data (health data) can be processed | ~ possible with local models; no masking | — | row 41 (PII masking) |
| C17 | Knowledge from SharePoint / OneDrive / Google Drive / Confluence with the source's permissions | ~ Dropbox, WebDAV, Nextcloud, OpenCloud, ownCloud; no SharePoint, no permission sync | `Service/Dropbox/`, `Service/Dav/`, `Service/Microsoft/GraphClient.php` (mail only) | row 40 |
| C18 | The assistant where staff work: Microsoft Teams, Slack | ✗ (Slack only as a webhook notification for the widget handoff) | `Service/Notification/SlackNotificationService.php` | row 39 |
| C19 | Customer service: answer rate, handoff into the helpdesk, gaps in the knowledge | ~ summaries, topics, sentiment, human takeover, a webhook handoff; no resolution rate, no ticket system | `Entity/WidgetSummary.php`, `Service/Widget/WidgetHandoff.php`, `HumanTakeoverService.php` | row 38 |
| C20 | Ready-made assistants for typical jobs | ~ plugins can install agent packs; no gallery | `Service/Plugin/PluginAgentInstaller.php`; use-case research Tier B | row 42 |
| C21 | Availability ≥ 99.5 %, maintenance windows announced 14 days ahead, TTFT ≤ 2 s | ~ cluster, status page in progress; no public SLA | `synaplan-platform/docs/WEB-CLUSTER-OPS.md` | §4 ER1 (status page, SLA text) |
| C22 | Two reference projects with ≥ 100 users each | owner | — | §4 ER4 |
| C23 | Accessible UI (BITV 2.0 / WCAG 2.1 AA for public bodies) | ~ the contract asks for AA contrast; no accessibility statement | `docs/UX_CONTRACT.md` U9 | §4 ER1 (statement) |

## 2. What is fine — do not rebuild it

- **OIDC.** Sign-in with access policy (organisation, role, permission), a localized denial, Bearer token exchange for Nextcloud, OpenCloud and Desktop, directory groups (`BKIND=directory`). SAML and SCIM attach to this model; they do not replace it (row 29 IAM consolidation, "directory" door).
- **Group policies.** `MODELS.ALLOWED`, default models, features and rate tier per group, with merge rules. Governance (row 41) adds keys to `PolicyAllowList`; it does not invent a second policy store.
- **Audit log.** One writer (`AuditLogWriter::record`), one table, retention (`IAM.AUDIT_RETENTION_DAYS`, default 365), admin list. Row 37 adds events and delivery, not a new log.
- **Usage log.** `BUSELOG` already carries tokens, cost, price snapshot, latency, status and metadata per call. Department reports are a query and a page, not a new meter.
- **Egress policy.** `Service/Egress/` with modes, rules, a directory and an SSRF guard. "Local only" and connector allowlists reuse it.
- **Erasure.** `Service/Iam/Erasure/` has one eraser per data kind. Every new table in rows 35–42 gets its eraser in the same PR.
- **Feature modules.** Every new optional feature is a `FeatureModule` in `backend/src/Module/`: absent when unconfigured (U11), env listed in `.env.minimal` and `docker-compose.minimal.yml`.
- **Licence.** Apache 2.0 — no branding clause, unlike Open WebUI. This is a sales argument and stays (D1).

## 3. Engineering tracks (rows 35–42)

Each row has its own folder with `00_master_plan.md` and `STATUS.md`. Step
ids are unique across the directory.

| Row | Track | Folder | Closes | Size | First step |
| --- | ----- | ------ | ------ | ---- | ---------- |
| 35 | **Enterprise identity** — SAML 2.0 sign-in, SCIM 2.0 provisioning (Entra ID, Okta, Keycloak), two-factor for local accounts, the chart | [`20261102-enterprise-identity/`](../20261102-enterprise-identity/00_master_plan.md) | C1, C2, C3 | L | EI0 (after row 29 IC2–IC6) |
| 36 | **AI Act transparency** — AI disclosure on every channel, C2PA marking of generated media, keep the provider's marks, an AI-literacy page and record | [`20261102-ai-act-transparency/`](../2026-archive/20261102-ai-act-transparency/00_master_plan.md) | C7, C8, C9 | M | AT1 — **deadline 2026-12-02** |
| 37 | **Audit and usage reporting** — complete audit events, export, streaming to a SIEM (HTTPS, Splunk HEC, syslog over TLS), cost per department, the chart | [`20261102-audit-and-usage-reporting/`](../20261102-audit-and-usage-reporting/00_master_plan.md) | C5, C6 | M | AE1 |
| 38 | **Service desk** — resolution and handoff rate per widget, unanswered questions as knowledge gaps, handoff into Zendesk, Freshdesk, HubSpot | [`20261102-service-desk/`](../20261102-service-desk/00_master_plan.md) | C19 | M | SD1 |
| 39 | **Workplace channels** — the assistant in Microsoft Teams and Slack, per company, as feature modules beside Telegram | [`20261102-workplace-channels/`](../20261102-workplace-channels/00_master_plan.md) | C18 | L | WC0 spike |
| 40 | **Permission-aware connectors** — SharePoint / OneDrive, Google Drive, Confluence as knowledge sources that keep the source's permissions | [`20261102-permission-aware-connectors/`](../20261102-permission-aware-connectors/00_master_plan.md) | C17 | XL | KC0 spike |
| 41 | **Governance and privacy** — personal data masked before a cloud model, "local only" per group, agent review before publish, policy overview | [`20261102-governance-and-privacy/`](../20261102-governance-and-privacy/00_master_plan.md) | C11, C16 | L | GV1 |
| 42 | **Agent template gallery** — the ten use cases as installable, tested templates in Assistants → New | [`20261102-agent-template-gallery/`](../2026-archive/20261102-agent-template-gallery/00_master_plan.md) | C20 | M | TG1 |

## 4. Work outside this repository's code (ER steps)

These steps do not ship in the app. Each names the repository that owns
it. Anything with infrastructure, IPs, credentials, prices of a concrete
offer or audit evidence stays private (`synaplan-platform`,
`synaplan-internal`).

### ER1 — Trust center

One public page that answers the security and privacy part of a tender
without a call. It lives on the sovereignty website (`synaplan-org-website`,
Next.js) and the product website (`synaplan-website`, pricing and
enterprise pages already exist), in German and English.

| Item | Content | Repository |
| ---- | ------- | ---------- |
| AVV / DPA template | Art. 28 GDPR, hosted platform and support contract for self-host | `synaplan-internal` (source), website (download) |
| TOMs | Art. 32 GDPR. Written once from the platform's real controls, reviewed by the data protection officer | `synaplan-platform` (source), website (summary) |
| Sub-processor list | Hosting, mail, payment, every model provider with region and whether it trains on data; generated from the provider descriptors where possible (`docs/PROVIDERS.md`) | website |
| Data flow per model | Which provider sees what, in which region, retention, training off. Taken from the same descriptors | website + `docs/` |
| Security overview | `SECURITY.md` and its disclosure process, pentest summary (ER3), image signing and SBOM (ER2), `security.txt` | website, this repo |
| AI incident process | Two-way reporting (C14): what counts as an AI incident, contact, 24 h first answer, escalation | website + `SECURITY.md` section |
| Status page and SLA text | Public status for the hosted platform; availability target, maintenance window rule (14 days notice, outside Mon–Fri 07:00–18:00), support hours | `synaplan-platform` (ops), website |
| Accessibility statement | BITV 2.0 / EN 301 549 statement with known gaps and a contact (C23) | website |
| AI Act page | Risk classification of Synaplan (general-purpose deployer tool, not high-risk by itself), what row 36 does, what the customer must do as deployer | website + `docs/AI_ACT.md` (row 36 AT6) |

### ER2 — Supply chain evidence

SBOM per image (CycloneDX via syft), signed images and attestations
(cosign keyless with GitHub OIDC), provenance (SLSA level 2 from GitHub
Actions), and the same for `synaplan-base-php` and the chart (Helm OCI
signing). This changes CI and Docker builds, so it is the owner's
decision (D4). Once ticked it becomes one PR per repository:

- `synaplan`: `release-*.yml`;
- `synaplan-base-php`;
- `synaplan-charts`: chart OCI push.

Each carries a `docs/SUPPLY_CHAIN.md` section that says how a customer verifies an image.

### ER3 — Certification path

| Stage | What | Why | When (proposal) |
| ----- | ---- | --- | --------------- |
| 0 | External pentest of the hosted platform and the self-host image; fix, publish summary | Every tender asks; cheapest proof | Q1 2027 |
| 1 | ISMS on ISO 27001 (based on IT-Grundschutz if the public sector is the target) | Base for C5; private sector accepts it alone | start Q1 2027, certificate ~9–12 months |
| 2 | BSI C5 **Type 1** for the hosted platform (`web.synaplan.com`), on C5:2026 | Public tenders: "ISO 27001 alone is not enough"; tenders accept "audit planned, finished within one year" | after stage 1 controls exist |
| 3 | C5 Type 2 (observation period 3–12 months) | Renewed yearly | following year |

C5:2026 applies from 2027-06-01, so start on C5:2026 and not on C5:2020.
The scope is the hosted platform. Self-hosters inherit the product
controls, which are documented in ER1 and ER2. The plan of record lives in
`synaplan-platform/planning/` (new folder, private), next to
`SECURITY-AUDIT-TODO.md`. Budget and auditor are owner decisions (D3).

### ER4 — Public sector and openDesk

- **openDesk agentic AI.** ZenDiS builds agentic AI in openDesk through MCP, and openDesk SaaS is on the Marktplatz Deutschland Digital from 01/2027.
  - Synaplan already ships an openDesk chart (`synaplan-charts/charts/synaplan-opendesk`), the private `synaplan-opendesk` overlay, meeting notes and an MCP server.
  - Steps:
    - document Synaplan as an MCP tool provider and an MCP client inside openDesk (one page in `docs/`);
    - get listed in openCode.de;
    - prepare the Marktplatz listing with ZenDiS.
  - Owner: business, plus `synaplan-opendesk` for the overlay.
- **Tender kit** (`synaplan-internal`, private):
  - one answer document per §1 row, kept current from this plan;
  - price sheet patterns (per user / flat);
  - references;
  - EVB-IT notes.
  - The LWL tender (deadline 2026-11-23) is the first use.
- **References (C22).** Two public customers with ≥ 100 users each are a precondition for most tenders. This is owner work, listed so it is not forgotten.

### ER5 — Partners and multi-tenant operation

Integrators and IT service providers resell Synaplan to many small
customers. Today each customer is one install, and federation connects
installs.

- **Now:**
  - a partner programme page (terms, margin, enablement) on the website;
  - a Helm values pattern "one release per customer" in `synaplan-charts` (namespace per tenant, shared Qdrant forbidden, documented).
- **Later (decision D6):** a fleet view for a partner (versions, health, update status of their installs), built on `BPLATFORMINSTANCES` and the update manifest (`Service/Update/`). It is not a new tenancy model inside one database.

True multi-tenancy in one install is a non-goal (§8). C13 is answered by
"one install per tenant, separated by Kubernetes namespace".

### ER6 — Distribution and growth

Plan of record for marketing is
`synaplan-platform/planning/OPEN-SOURCE-MARKETING.md` and `BOUNTY-PLAN.md`.
This plan adds only what the enterprise tracks need:

- **AWS Marketplace.** Engineering is done (`docs/AWS_MARKETPLACE_LISTING.md`); seller registration is open (owner). Azure Marketplace follows once row 35 SAML lands, because Entra customers buy there.
- **Artifact Hub** for `synaplan-charts`, and an `artifacthub-repo.yml` in the chart repo.
- **Comparison pages** on `synaplan-website`: Synaplan vs Langdock, vs Open WebUI, vs Copilot. They are written from §1 facts, with dates, and kept honest. The 25 €/user Langdock price and its on-prem minimum of 5,000 users are the hook.
- **Row 33 Halloween GUI** (your own model server in five minutes) is the first-run story for self-hosters. Every comparison page links to it.
- **Opt-in install telemetry** (count of active installs and versions, nothing else, off by default, one switch in the setup wizard) is decision D5. Without it the "how many installs" question stays unanswerable.

### ER7 — Packaging

Today the seeded plans are PRO 19.95, TEAM 49.95 and BUSINESS 99.95 EUR
(`backend/src/Seed/SubscriptionPlanSeeder.php`). Enterprise features
(SAML, SCIM, audit streaming, department reports, PII masking) need a
place:

- **Hosted:** in Business and an "Enterprise" offer by quote (SLA, AVV, dedicated install, support).
- **Self-host:** every feature stays in the Apache 2.0 code (D1). Revenue comes from support contracts, the hosted platform, certification-backed hosting and partners — not from locked code.

The feature module system already decides "present / absent". It must
never gate on a licence key (D1).

## 5. Decisions (ticked by the owner before the step that needs it)

- [ ] **D1 (all).** No open-core split: SAML, SCIM, audit streaming, department reports and masking stay Apache 2.0 in this repository. Enterprise revenue is hosting, support, certification and partners. *Alternative:* an `ee/` folder under another licence (GitLab, Mattermost model) — rejected for now: it splits CI, confuses contributors and contradicts the sovereignty message; revisit only with sales data.
- [ ] **D2 (§6).** Order: row 36 first because of the legal date; then row 35 and row 37 in parallel (different files); then row 41; rows 38, 39, 42 by sales demand; row 40 last (largest, depends on row 35 identities).
- [ ] **D3 (ER3).** Pentest Q1 2027; ISO 27001 then C5 Type 1 for the hosted platform; budget and auditor named in the private plan.
- [ ] **D4 (ER2).** SBOM + cosign + provenance in the release workflows of `synaplan`, `synaplan-base-php`, `synaplan-charts`. CI change, owner call.
- [ ] **D5 (ER6).** Opt-in install telemetry: yes (count + version only, off by default, stated in the setup wizard) or no.
- [ ] **D6 (ER5).** Partner fleet view on `BPLATFORMINSTANCES`: in scope for 2027 H1, or partners only get the chart pattern.
- [x] **D7 (ER1).** Confirmed by the owner on 2026-11-06: the trust center lives on `synaplan-org-website`, and `synaplan-website` links to it.

## 6. Order

```text
2026-11  row 36 AT1–AT4 (disclosure + C2PA + keep provider marks) ─┐ deadline 2026-12-02
         ER1 trust center v1 (AVV, TOMs, sub-processors, AI Act page)┘ LWL tender 2026-11-23
2026-12  row 29 IC2–IC6 finish → row 35 EI0–EI3 (2FA, SAML)       ∥ row 37 AE1–AE4
2027-01  row 35 EI4–EI7 (SCIM, chart)                              ∥ row 37 AE5–AE7, row 41 GV1–GV3
2027-Q1  ER3 pentest, ER2 supply chain, ER6 Azure listing after EI3
2027-Q1+ rows 38 / 39 / 42 by demand; row 40 after row 35 SCIM
```

Rows 22 (architecture update), 31 (review) and 32 (adaptive research) keep
their places. This plan does not move them. Row 36 touches the widget,
the channel formatters and the media store. It does not touch the chat
pipeline files that row 22 is splitting.

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Eight tracks at once dilute the team | §6 order; only rows 35–37 start before 2027; each row has its own exit |
| Enterprise features make the self-host first run harder | Every feature is a `FeatureModule`, absent without configuration (U11); row 33's first-run journey stays green in CI |
| Certification is started and stalls | ER3 begins with the pentest (a fixed-price deliverable), then ISO; C5 only with an auditor contract |
| A trust center page says something the platform does not do | Every ER1 statement names its control owner and the file or runbook that proves it; reviewed at each release by the platform owner |
| Comparison pages age and become wrong | Each page carries its check date; a quarterly check is a STATUS entry |

## 8. Non-goals

- Multi-tenancy inside one database or one install.
- A licence-key mechanism or locked enterprise code (D1).
- Vertical connectors (ERP, claims, Fachverfahren) in core. They come through custom tools or MCP (use-case research §4).
- High-risk AI Act use cases (HR selection, credit scoring) as product features.

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from the market analysis of 2026-11-02, two public tenders, Langdock's Business plan, the final EU Code of Practice on the transparency of AI-generated content, Microsoft's SCIM and Teams documentation, and a read of `main`, `synaplan-charts`, `synaplan-platform` and the org repositories. No code changed. |
