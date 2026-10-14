# Security Policy

Synaplan runs on other people's servers and holds their documents, mail and
conversations. A security report is the most useful bug report we can get, and
we treat it that way: fast, private until fixed, and with credit to the person
who found it.

## Reporting a vulnerability

Report security problems **privately**. Do not open a public issue, pull
request or Discord thread for them — a public report gives attackers the same
head start it gives us.

### Step by step

1. **Open the private report form.** On the repository page, click
   **Security and quality → Advisories → Report a vulnerability**, or go
   straight to
   **[github.com/metadist/synaplan/security/advisories/new](https://github.com/metadist/synaplan/security/advisories/new)**.
   You need a GitHub account; nothing else. The form is GitHub's private
   vulnerability reporting: what you write is visible only to you and the
   repository maintainers, and it stays that way until an advisory is
   published.
2. **Give it a precise title.** Name the weakness and the component, for
   example `SSRF via DNS rebinding in AnalyzeImageTool (missing IP pinning)`.
3. **Fill in the description.** The form has fields for summary, details,
   proof of concept and impact. What makes a report fast to act on:
   - **Where:** the file path and the lines, and the version or commit you
     looked at (`backend/src/AI/Messages/Tools/AnalyzeImageTool.php`,
     `downloadUrl()`, `v5.4.0`).
   - **What:** the vulnerable behaviour in one or two sentences, and — if you
     spotted it — the place in the codebase that already does it right. That
     tells us the fix you expect.
   - **How to reproduce:** the preconditions (which role, which feature
     enabled) and the exact request or steps. A proof of concept against your
     own install is ideal; against the hosted service, stay within your own
     account and data.
   - **Impact:** what an attacker gets, who is affected, and whether it needs
     an account or an admin.
   - **Related findings:** if the same root cause appears elsewhere, list it
     in the same report, with your own severity estimate. Lower-severity items
     are fixed too; they just need to be named.
4. **Set severity and weakness if you can.** The form offers a CVSS
   calculator and a CWE field (`CWE-918` for SSRF). Your estimate is a
   starting point, not a verdict; we agree the final rating with you.
5. **Submit.** You receive a `GHSA-…` identifier and a private thread. Every
   question from us and every status change happens there, so turn on
   notifications for it.
6. **Stay reachable.** We may ask you to confirm the fix on a branch before it
   merges, and we add you as a credited reporter on the advisory — accept the
   credit invitation GitHub sends you if you want your name on it.

If you cannot use GitHub, ask for a private channel through
[www.synaplan.com](https://www.synaplan.com) without putting the details in
that first message.

Reports about the related repositories (`synaplan-charts`, `synaplan-desktop`,
`synaplan-docs`, `Synamail`, the plugins) can be filed there or here; we route
them.

### Please do not

- Open a public issue, pull request, discussion or Discord thread about the
  finding before the advisory is published.
- Access, change or delete data that is not yours, or run tests that degrade
  the hosted service.
- Send the details by unencrypted email unless we asked you to.

## What happens next

| Step | What we do |
| ---- | ---------- |
| **Acknowledge** | We confirm the report within three working days, usually much sooner. |
| **Triage** | We reproduce the issue, rate it (CVSS), and agree the scope with you — including findings you flagged as lower severity. |
| **Fix** | The fix is developed on a branch and reviewed like every change; the full test gate applies. Where the report leaves no safe public path, we use the advisory's temporary private fork. |
| **Release** | The fix ships in the next release and in the `ghcr.io/metadist/synaplan` images. The advisory names the fixed version. |
| **Publish** | The advisory is published with you credited as the reporter, and we request a CVE from GitHub (a CVE Numbering Authority) so downstream scanners pick it up. |

We ask that you keep the details private until the advisory is published. We
aim to get there in days, not months; if a fix needs longer, we say so in the
report thread and agree a date with you. Good-faith research that follows this
policy — no access to other people's data, no service disruption, no
destructive testing against the hosted service — is welcome, and we will not
take action against it.

### Example

[GHSA-26f3-684h-hpjh](https://github.com/metadist/synaplan/security/advisories/GHSA-26f3-684h-hpjh)
was filed through this process: a private report with the vulnerable code, a
proof of concept and a remediation proposal, filed in the morning; the fix
reviewed and merged the same afternoon ([#2489](https://github.com/metadist/synaplan/pull/2489));
the advisory published with the reporter credited; the lower-severity second
finding from the same report closed in the follow-up release.

## Supported versions

Fixes land on `main` and ship in the next release. The latest release line is
supported; older lines receive no backports.

| Version | Supported |
| ------- | --------- |
| Latest release (`ghcr.io/metadist/synaplan:latest`) | Yes |
| Older releases | No — upgrade to the latest release |

## Scope

In scope: everything in this repository — the backend, the web frontend, the
embeddable chat widget, the published Docker images and the deployment files
under `deploy/`.

Out of scope: vulnerabilities in the AI providers or other third-party services
Synaplan connects to (report those upstream), reports from automated scanners
without a demonstrated impact, volumetric denial of service, and social
engineering. Testing against the hosted service at `web.synaplan.com` is fine
when it stays non-destructive and touches only your own account and data; for
anything beyond that, ask first.

## Thanks

Every report below made Synaplan safer for everyone who runs it.

| Date | Reporter | Finding |
| ---- | -------- | ------- |
| 2026-10-14 | [@xherogasm](https://github.com/xherogasm) | SSRF via DNS rebinding in `analyze_image`, and the unguarded admin endpoint probe — [GHSA-26f3-684h-hpjh](https://github.com/metadist/synaplan/security/advisories/GHSA-26f3-684h-hpjh) |
