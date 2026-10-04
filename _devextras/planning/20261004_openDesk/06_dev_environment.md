# 06 — Development environment

Development runs against a real openDesk dev cluster (no real data), because
the bridge path cannot be faked convincingly in Docker Compose. The concrete
cluster (hostnames, namespace, credentials, scripts) is described in the
private `vultr-cluster` repository (page "Meeting notes plugin: the Vultr
dev cluster"). This file holds the rules and the pieces that are the same on
any openDesk cluster.

---

## 1. Rules

1. **Do not change the shared openDesk edition repository** while
   developing. Other teams deploy other clusters from it. Everything for
   development is a **private overlay** that patches live objects on the dev
   cluster and can be re-applied and reverted with one command each.
2. A `helmfile sync` of the Jitsi or Synaplan releases from the edition repo
   **reverts** the overlay. After every sync: run `apply.sh` again. The
   overlay's `check.sh` says which pieces are missing.
3. The overlay never touches other namespaces, other releases, or Keycloak
   clients it did not create.
4. Production packaging (Helm values, edition switches) is written **after**
   the journeys are walked (`MN-12`), as a merge request with default-off
   switches, reviewed by the edition owner.

## 2. Overlay layout (private repo)

```text
<private overlay repo>/dev/meeting-notes/
├── apply.sh        # idempotent; each step prints "= unchanged" / "~ updated" / "+ created"
├── revert.sh       # removes everything apply.sh added, restarts the touched pods
├── check.sh        # one line per piece: ok / missing / outdated
├── env.example     # which secrets are read from where (no values)
└── manifests/      # transcriber Deployment+Service, ConfigMaps, patches
```

`apply.sh` steps, in order:

| # | Piece | How (dev) |
|---|-------|-----------|
| 1 | Secrets | Read the Prosody secret and transcriber token from the plugin's `POST admin/connection` answer (once) into a gitignored env file; create two Kubernetes Secrets. |
| 2 | Plugin into Synaplan | §3. |
| 3 | Transcriber | Deployment + ClusterIP Service from the published image (`MN-1c`) or a dev tag; env from the Secret. |
| 4 | Prosody | ConfigMap with `mod_synaplan_notes.lua` mounted into `/prosody-plugins-custom`, `conf.d` snippet with the options, `XMPP_MODULES` appended, secret env; restart Prosody (drops running meetings). |
| 5 | Jicofo | ConfigMap `custom-jicofo.conf` mounted at `/config/custom-jicofo.conf`, token env; restart Jicofo. |
| 6 | Jitsi web | Append the loader include to `custom-config.js`, add `synaplan-notes-silent.html`, add the `config.transcription` block; restart web. |
| 7 | Keycloak | Create or update the public client from `deploy/keycloak/…json` through the admin API (same approach as the cluster's existing Keycloak setup script). |
| 8 | Plugin settings | `PUT admin/settings` with the dev URLs (Prosody, transcriber, Jitsi host), `enabled=1`. |

## 3. The plugin into Synaplan

The Synaplan image bakes some plugins into `/plugins`. The meeting-notes
plugin is mounted **next to them** at `/plugins/meeting_notes` in **all
three** Synaplan deployments (web, worker, scheduler), because the worker
runs plugin services and the scheduler runs the watchdog.

- **Release / stable dev:** an init container from a small image
  `ghcr.io/metadist/synaplan-meetingnotes:<tag>` (just the plugin directory)
  copies it into an `emptyDir` mounted at `/plugins/meeting_notes`. The
  generic chart values `additionalInitContainers`, `volumes`, `volumeMounts`
  already allow this; a first-class `plugins:` value in `synaplan-charts`
  can follow.
- **Fast inner loop:** `make dev-sync` in the plugin repo copies
  `meetingnotes-plugin/` into the running pods' `emptyDir`
  (`kubectl cp`), then clears the Symfony cache and restarts PHP workers.
  A container restart keeps the `emptyDir`; a pod restart needs the init
  container (or another `make dev-sync`).
- After the plugin appears: `php bin/console app:plugin:list` shows it;
  install it for the admin account (`app:plugin:install <id> meeting_notes`).

## 4. Speech engine for development

Until the GPU engine exists (`MN-8`), pick one, in this order:

1. **Local whisper.cpp in the Synaplan pod:** set `WHISPER_ENABLED=true`,
   provide a model file (`small` for speed, `large-v3-turbo` q5 for
   quality) through an init container into the whisper model directory,
   select "Whisper (local)" as the plugin's speech model. Enough for one or
   two synthetic speakers.
2. **A temporary self-hosted server** (CPU faster-whisper with int8) in the
   cluster, once the provider from `MN-8a` exists.
3. **A cloud model** with `allow_cloud_stt` switched on — only on the dev
   cluster, only with synthetic audio, and recorded in `STATUS.md`.

## 5. Synthetic meetings

Playwright drives real Jitsi meetings, so the bridge, Prosody, Jicofo and
the transcriber run exactly as in production.

- Chromium with `--use-fake-ui-for-media-stream
  --use-fake-device-for-media-stream
  --use-file-for-fake-audio-capture=<voice>.wav`; one browser context per
  participant, each with its own WAV (16-bit, 48 kHz mono).
- Sign-in: open the realm's account page first and log in, then open the
  meeting (openDesk's Jitsi does a silent Keycloak check, then shows the
  pre-join screen; click **Join meeting**). The spike script in the private
  repo does exactly this.
- Voices: speech WAVs from set A of [05 §9](./05_stt_quality.md#9-benchmark-and-acceptance)
  with their reference texts, so each run can score WER.
- Assertions read Jitsi state (`APP.store`), the loader's shadow DOM, the
  transcriber metrics and the plugin API — never fixed sleeps; wait for
  states.
- Test users: the cluster's demo accounts (directory users). The admin
  account has a one-time password; admin journeys are walked by hand or
  with a TOTP secret provided only to the test runner.

## 6. Done-for-the-day checklist

- `check.sh` all ok, or `revert.sh` run.
- No notes left `running` (personal page and admin page).
- `STATUS.md` has a line for anything walked or measured.
