<div align="center">

<a href="https://www.synaplan.com"><img src="docs/images/hero.svg" alt="Synaplan. Own the flow. Your knowledge, your models, your channels." width="100%"></a>

**Own the flow.**

Build agents on your own documents and put them wherever work happens: web, WhatsApp, email, Outlook, Nextcloud and ownCloud. Mix local models with the best cloud AI, see what every answer costs, and decide what the AI is allowed to do – open source, on your server or ours.

[**Run it in up to five minutes**](#quickstart) &nbsp;·&nbsp; [**Try it hosted**](https://web.synaplan.com/) &nbsp;·&nbsp; [**Docs**](https://docs.synaplan.com/) &nbsp;·&nbsp; [**Discord**](https://discord.com/invite/kQB3eDjWfF)

[![Latest release](https://img.shields.io/github/v/release/metadist/synaplan)](https://github.com/metadist/synaplan/releases)
[![GitHub stars](https://img.shields.io/github/stars/metadist/synaplan?style=flat&logo=github)](https://github.com/metadist/synaplan/stargazers)
[![Discord](https://img.shields.io/badge/Discord-join%20us-5865F2?logo=discord&logoColor=white)](https://discord.com/invite/kQB3eDjWfF)

<sub>Self-hosting is free. The hosted version opens with a paid plan or an invitation code – ask us at <a href="mailto:team@synaplan.com">team@synaplan.com</a>.</sub>

</div>

<p align="center">
  <img src="docs/images/synaplan-admin-tour.gif" alt="A thirty-second tour of Synaplan: a chat answer and its cost, default models with cost badges, the file library, generated images and audio, and connected apps" width="100%">
</p>

<p align="center"><sub>Chat, the cost of every answer, your files, generated media and connected apps – in about 30 seconds.</sub></p>

<p align="center"><a href="https://www.youtube.com/watch?v=WjO9mE43uec">▶ Watch the full demo on YouTube</a></p>

<!-- The GIF above is docs/images/synaplan-admin-tour.gif. scripts/build-readme-tour.sh rebuilds the tour film and stills. -->

---

## Own the flow

- **Own your channels.** The AI answers on your website, in WhatsApp, by email, in Outlook and inside Nextcloud and ownCloud – with the same knowledge and the same rules everywhere.
- **Own your models.** Hundreds of models from OpenAI, Anthropic, Google, Mistral, sovereign EU providers or locally via Ollama. Mix them per task: sensitive work stays local, everything else gets the best model for the job.
- **Own your costs.** Every answer shows its model and price. A planner splits bigger requests into steps and spends the expensive model only where it matters.
- **Own the decisions.** Actions like sending mail or creating tickets wait for your approval, and every step is logged.

**Running in production at** Megaherz Yoga · SANI Pflegedienst · National Secure Cloud · PlateArt

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
| **One click** | [RepoCloud](https://repocloud.io/details/Synaplan/) · [Elestio](https://elest.io/open-source/synaplan) · [Railway](https://railway.com/deploy/synaplan) | – |
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

| Surface | What it does | Get it |
| --- | --- | --- |
| **Web app** | Full chat and admin UI, light and dark, five languages | This repo |
| **Mobile apps** | Chat, documents and voice on iPhone and Android – on web.synaplan.com or your own server | [App Store](https://apps.apple.com/app/id6784278288?ct=github-readme) · [Google Play](https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dgithub-readme) |
| **Outlook add-in** | Find and process mail inside Outlook (Web, new & classic, Mac) without sending it anywhere | [Synamail](https://github.com/metadist/Synamail) |
| **Chat widget** | An AI chat on any website with one snippet, human takeover included | [Widget guide](https://docs.synaplan.com/widget) |
| **WhatsApp & email** | The AI answers on the channel the question came in on | [WhatsApp](docs/WHATSAPP.md) · [Email](docs/EMAIL.md) |
| **Nextcloud, ownCloud & OpenCloud** | Use the AI inside your file cloud, with its files as knowledge – your file store stays in charge | [Nextcloud](https://web.synaplan.com/apps/nextcloud) · [ownCloud](https://web.synaplan.com/apps/owncloud) · [OpenCloud](https://github.com/metadist/synaplan-opencloud) |
| **Synaplan Desktop** | Pair a computer and run skills on it (no installer yet – build from source) | [synaplan-desktop](https://github.com/metadist/synaplan-desktop) |
| **MCP & Claude Code** | Your knowledge and memories as MCP tools, plus an Anthropic-compatible endpoint | [MCP guide](https://docs.synaplan.com/mcp) |

**Connects to** Microsoft 365 · Dropbox · Nextcloud / ownCloud / WebDAV · CalDAV · IMAP · Jira & Confluence · your own MCP servers – [how to connect](docs/CONNECTIONS.md)

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

- **Stack:** Symfony (PHP) backend, Vue frontend, MariaDB VECTOR or Qdrant for document search, Redis, Docker.
- **Lean core:** app, database and Redis. Local AI, office files, web search, voice and more are building blocks you switch on when you need them.
- **Open by design:** OpenAPI REST API, MCP server and client, an Anthropic-compatible `/v1/messages` endpoint and a plugin system – extend Synaplan without forking it.

[Docs](https://docs.synaplan.com/) · [Technical manual](docs/README.md) · [Platform reference](docs/PLATFORM_REFERENCE.md) · [Discussions](https://github.com/metadist/synaplan/discussions) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [Discord](https://discord.com/invite/kQB3eDjWfF)

Changing the code? Start with [adding an AI provider](docs/PROVIDERS.md) or [following a tool call](docs/ARCHITECTURE.md#tool-path). [AGENTS.md](AGENTS.md) is the guide for coding agents.

---

## License

[Apache-2.0](LICENSE) · Built in Düsseldorf by [metadist](https://www.synaplan.com) · Member of the Open Source Business Alliance

<p align="center">
  <a href="https://osb-alliance.de/" target="_blank" rel="noopener noreferrer"><img src="docs/images/osba-member.png" alt="OSBA member" width="180" height="90"></a>
</p>

<p align="center"><b>If Synaplan helps you Own the flow, a ⭐ helps the next team find it.</b></p>
