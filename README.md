<div align="center">

<a href="https://www.synaplan.com"><img src="docs/images/hero.svg" alt="Synaplan. Own the flow. Your knowledge, your models, your channels." width="100%"></a>

**Own the flow.**

Build agents on your own documents and put them wherever work happens: web, WhatsApp, email, Outlook, Nextcloud and ownCloud. Mix local models with the best cloud AI, see what every answer costs, and decide what the AI is allowed to do – open source, on your server or ours.

<p align="center">
  <img src="docs/images/synaplan-product.gif" alt="Synaplan in about 30 seconds: a chat answer and its cost, ready-made agents, models with a cost on each one, then the tools it connects, from WhatsApp, email and Outlook to Nextcloud, Dropbox and more" width="100%">
</p>

<p align="center"><sub>A chat answer and its cost, agents, the model for each job, then the tools it connects: WhatsApp, email, Outlook, Nextcloud, Dropbox, and more.</sub></p>

<p align="center"><a href="https://www.youtube.com/watch?v=WjO9mE43uec">▶ Watch the full demo on YouTube</a></p>

[**Run it**](#quickstart) &nbsp;·&nbsp; [**Try it hosted**](https://web.synaplan.com/) &nbsp;·&nbsp; [**Docs**](https://docs.synaplan.com/) &nbsp;·&nbsp; [**Discord**](https://discord.com/invite/kQB3eDjWfF)

[![Latest release](https://img.shields.io/github/v/release/metadist/synaplan)](https://github.com/metadist/synaplan/releases)
[![GitHub stars](https://img.shields.io/github/stars/metadist/synaplan?style=flat&logo=github)](https://github.com/metadist/synaplan/stargazers)
[![Discord](https://img.shields.io/badge/Discord-join%20us-5865F2?logo=discord&logoColor=white)](https://discord.com/invite/kQB3eDjWfF)
[![metadist/synaplan MCP server](https://glama.ai/mcp/servers/metadist/synaplan/badges/score.svg)](https://glama.ai/mcp/servers/metadist/synaplan)
[![Synaplan MCP connector](https://glama.ai/mcp/connectors/io.github.metadist/synaplan/badges/score.svg)](https://glama.ai/mcp/connectors/io.github.metadist/synaplan)

<sub>Self-hosting is free. The hosted version opens with a paid plan or an invitation code – ask us at <a href="mailto:team@synaplan.com">team@synaplan.com</a>.</sub>

</div>

---

## Own the flow

The same assistant answers on your website, in WhatsApp, by email, in Outlook and inside Nextcloud and ownCloud. Sensitive work can stay on Ollama. Everything else can go to OpenAI, Anthropic, Google, Mistral or a sovereign EU provider. Every answer shows its model and its price. A planner spends the expensive model only where it matters, and a mail or a ticket goes out only after a person approves it.

It runs in production at a yoga studio ([Megaherz Yoga](https://megaherz-yoga.com)), a care service (SANI Pflegedienst), a public-sector cloud (National Secure Cloud) and an ecommerce solution for custom-made kitchen panels.

---

## Quickstart

**You need:** Docker with Compose v2 and 8 GB of RAM.

```bash
git clone https://github.com/metadist/synaplan.git
cd synaplan
make up
```

Open **<http://localhost:5173>**, log in as `admin@synaplan.com` / `admin123` and say hello. Your first message starts the **Synaplan Demo – no API key needed** (40 messages a day for 14 days, [how it works](https://docs.synaplan.com/demo)). When you're ready, add [your own key](https://docs.synaplan.com/own-ai-keys) (free: [Groq](https://console.groq.com)) or run [local models with Ollama](https://docs.synaplan.com/modules/local-ai).

The first start downloads everything and takes 5–15 minutes. After that, Synaplan starts in seconds.

> [!IMPORTANT]
> These logins are for trying Synaplan on your own machine. On a server, use the [installer](#install-it-your-way) – it creates its own admin password.

---

## Install it your way

| Where | How | Guide |
| --- | --- | --- |
| **Published image, no git** | One `compose.yaml`, then `docker compose up -d` | [Published image](docs/PLATFORM_REFERENCE.md#published-image-no-git-checkout) |
| **Docker GUI** | Portainer, Dockge, Synology: paste the compose file as a stack | [Docker GUI](docs/PLATFORM_REFERENCE.md#published-image-no-git-checkout) |
| **Closed network** | No route to the internet, HTTPS on your LAN address | [Closed network](docs/PLATFORM_REFERENCE.md#closed-network) |
| **Linux server** | The installer pins a release, generates secrets and runs a smoke test | [On a server](docs/PLATFORM_REFERENCE.md#on-a-server) |
| **Kubernetes** | Helm charts for clusters | [synaplan-charts](https://github.com/metadist/synaplan-charts) |
| **Coolify** | Paste the service file, then Deploy. Not in the Coolify catalog yet | [On Coolify](deploy/coolify/README.md) |
| **One click** | [RepoCloud](https://repocloud.io/details/Synaplan/) · [Elestio](https://elest.io/open-source/synaplan) | – |
| **Railway** | Optional one-click template for the published image | [Deploy on Railway](https://railway.com/deploy/synaplan) |
| **Public sector (EU/CH)** | openDesk, Nextcloud and OpenCloud, on-premises or in an EU cloud | [synaplan.eu](https://www.synaplan.eu/) |

A Linux server in one line:

```bash
curl -fsSL https://raw.githubusercontent.com/metadist/synaplan/main/install.sh | \
  bash -s -- --mode server --domain https://ai.example.com
```

**Own the stack.** On-prem, EU cloud or fully air-gapped. No training on your data, no forced telemetry, no feature paywall: self-hosted Synaplan is the same software as our cloud, under Apache-2.0.

---

## How it compares

People usually arrive comparing Synaplan with a chat window or an app builder. This is what each project is for. It is not a scoreboard.

| | Synaplan | Open WebUI | LibreChat | Dify |
| --- | --- | --- | --- | --- |
| What it is | A team platform: chat, your files as knowledge, a site widget, and the same AI on WhatsApp and email | A chat interface for local and cloud models | A chat app for many providers | A builder for AI apps and workflows |
| First run | Several containers, 8 GB of RAM | One container | A Compose stack | A Compose stack, and a hosted cloud |
| Cost of an answer | Shown on the answer | Not on the answer | Not on the answer | In the builder's usage logs, not on the chat answer |
| Same AI on your site, WhatsApp and email | Yes | No | No | Site widget yes. WhatsApp and email are not built in |

If you only want a chat window, Open WebUI or LibreChat gets you there faster.

---

## Where the AI shows up

The web app in this repo is the full chat, in five languages, light and dark. The same account is on the [App Store](https://apps.apple.com/app/id6784278288?ct=github-readme) and [Google Play](https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dgithub-readme). Mail stays inside Outlook with the [Synamail](https://github.com/metadist/Synamail) add-in.

One snippet puts a [chat widget](https://docs.synaplan.com/widget) on a website, and a person can take the conversation over. [WhatsApp](docs/WHATSAPP.md) and [email](docs/EMAIL.md) answer on the channel the question came in on. The file store stays in charge: [Nextcloud](https://web.synaplan.com/apps/nextcloud), [ownCloud](https://web.synaplan.com/apps/owncloud) and [OpenCloud](https://github.com/metadist/synaplan-opencloud).

[Synaplan Desktop](https://github.com/metadist/synaplan-desktop) pairs a computer and runs skills on it. There is no installer yet, so you build it from source. [MCP](https://docs.synaplan.com/mcp) exposes your knowledge to Claude Code and any other client. The same assistant also reaches Microsoft 365, Dropbox, WebDAV, CalDAV, IMAP, Jira, Confluence and your own MCP servers. [How to connect.](docs/CONNECTIONS.md)

---

## Troubleshooting

| Problem | What to do |
| --- | --- |
| "Port already in use" on start | A port Synaplan needs is taken – see [Port conflicts](docs/INSTALLATION.md#port-conflicts) |
| Containers stop right after start | Give Docker at least 8 GB of RAM, then check `docker compose logs -f backend` |
| Chat gives no answers | Use the Synaplan Demo, paste one provider key, or add the `local-ai` profile – see the [quickstart](#quickstart) |
| First start seems stuck | The first start downloads all images and takes 5–15 minutes. `docker compose logs -f startup-notes` shows where it is |
| Anything else | Ask in [Discord](https://discord.com/invite/kQB3eDjWfF) or open a [GitHub issue](https://github.com/metadist/synaplan/issues) |

---

## Under the hood

Symfony and Vue, MariaDB VECTOR or Qdrant, Redis, Docker. The core is the app, the database and Redis. Local AI, office files, web search and voice are blocks you switch on. The API is OpenAPI, there is an MCP server and client, an Anthropic-compatible `/v1/messages` endpoint and a plugin system, so you can extend it without forking it.

[Docs](https://docs.synaplan.com/) · [Technical manual](docs/README.md) · [Platform reference](docs/PLATFORM_REFERENCE.md) · [Discussions](https://github.com/metadist/synaplan/discussions) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [Discord](https://discord.com/invite/kQB3eDjWfF)

Changing the code? Start with [adding an AI provider](docs/PROVIDERS.md) or [following a tool call](docs/ARCHITECTURE.md#tool-path). [AGENTS.md](AGENTS.md) is the guide for coding agents.

---

## License

[Apache-2.0](LICENSE) · Built in Düsseldorf by [metadist](https://www.synaplan.com) · Member of the Open Source Business Alliance

<p align="center">
  <a href="https://osb-alliance.de/" target="_blank" rel="noopener noreferrer"><img src="docs/images/osba-member.png" alt="OSBA member" width="180" height="90"></a>
</p>

<p align="center"><b>If Synaplan helps you Own the flow, a ⭐ helps the next team find it.</b></p>
