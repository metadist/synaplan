# Synamail Plugin — Contact AI Profiling

Server-side companion for the [Synamail Outlook add-in](https://github.com/metadist/Synamail).
It gives every mailing partner a **rolling AI profile**: a growing summary of who the
person is, the tone of the relationship, stable facts, and open loops ("they owe you
the contract draft") — updated one email at a time from Outlook.

## What it provides

| Endpoint (under `/api/v1/user/{userId}/plugins/synamail`) | Purpose                                      |
| --------------------------------------------------------- | -------------------------------------------- |
| `GET /profiles`                                           | List all stored profiles (newest first)      |
| `GET /profiles/{email}`                                   | One contact's profile (`null` if none yet)   |
| `POST /profiles/{email}/update`                           | Roll ONE email into the profile (AI merge)   |
| `DELETE /profiles/{email}`                                | Delete the profile entirely (privacy / GDPR) |

The rolling-summary prompt lives in `backend/Controller/SynamailController.php` —
the AI merges `summary` / `tone` / `facts` / `openLoops`; deterministic fields
(email count, first/last seen, org-from-domain) are computed in PHP, never by the
model. Profiles are stored per user in Synaplan's generic `plugin_data` table —
**no core schema changes**. A small panel in the Synaplan web UI lists all
profiles and lets the user delete any of them.

## Install

```bash
# 1. Copy this directory into your Synaplan checkout / server
cp -r synamail-plugin /path/to/synaplan/plugins/synamail

# 2. Reload the backend so the plugin routes register
docker compose restart backend worker      # or: php bin/console cache:clear

# 3. Install per user (runs the idempotent migration)
docker compose exec backend php bin/console app:plugin:install <userId> synamail
# or for everyone:  app:plugin:install-verified-users synamail
```

## Configuration (`BCONFIG` group `P_synamail`)

| Key                 | Default | Meaning                                               |
| ------------------- | ------- | ----------------------------------------------------- |
| `enabled`           | `1`     | Gate for all plugin endpoints                         |
| `profile_language`  | `auto`  | Summary language (`auto` = follow the correspondence) |
| `max_summary_words` | `150`   | Upper bound for the rolling summary                   |

## Privacy

Profiles describe identifiable people. They live **only in the user's own
Synaplan workspace**, are visible in both Outlook and the Synaplan web panel,
and are deletable with one click from either side. No central aggregation.

## Notes

- Without this plugin, the Synamail add-in still works fully — the profile card
  simply shows an install hint.
- Storage keys are hashed (`p_<sha1(email)>`) because `plugin_data` sanitizes
  keys to `[a-z0-9_]`; the full email lives inside the profile JSON.
- Uses the user's default CHAT model via `AiFacade` — no extra AI configuration.
