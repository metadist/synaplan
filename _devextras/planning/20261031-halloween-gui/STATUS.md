# Status — Halloween GUI (row 33)

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message ([`../AGENTS.md`](../AGENTS.md)).

| Step | Topic | State |
| ---- | ----- | ----- |
| Plan | — | written 2026-10-30; §0 unticked |
| HG0 | Measure the `/v1` route; the model stub | not started |
| HG1 | Fresh install for developers | not started |
| HG2 | A model's identity (its server, `:latest`) | not started |
| HG3 | The server's own capability list | not started |
| HG4 | Connect by address | not started |
| HG5 | Import with a cost, choose for chat, console | not started |
| HG6 | The card in AI infrastructure | not started |
| HG7 | The wizard offers your own server | not started |
| HG8 | One honest Ollama address | not started |
| HG9 | Host name for a model server on the same machine | not started |
| HG10 | CI walks the first run | not started |
| HG11 | Docs | not started |
| HG12 | Release walk, archive | not started |

## Measurements

### 2026-10-30 — Plan baseline

Dev stack running the branch merged as
[#2898](https://github.com/metadist/synaplan/pull/2898); the discovery and
import code it ran is unchanged on `466b96818`.
`COMPOSE_PROFILES=compute,local-ai`. Read-only; nothing was imported or
changed. Details and commands in [`01_findings.md`](./01_findings.md).

- Bundled Ollama `0.40.12`: `/api/tags` sends `capabilities`,
  `context_length` and `embedding_length`; `/api/show` sends
  `capabilities`; `/v1/models` lists the same two ids.
- Host Ollama `0.32.1` (reached as `host.docker.internal:11434` from the
  dev backend): the same fields, plus `remote_host:
  https://ollama.com:443` and `size: 338` on a `:cloud` model.
- Import preview for source `ollama`: `gpt-oss:20b` exists;
  `bge-m3:latest` is offered as new although the seeded `bge-m3` row is
  the same model (F7).
- `OLLAMA_BASE_URL`: a LAN address in `backend/.env`, the profile's
  `http://ollama:11434` in the app process, an empty value in a
  `docker compose exec` shell (F3).

## Walks

None yet.
