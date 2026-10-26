# cloud-fixture — the reference client

A small stand-in for a cloud app (Nextcloud, ownCloud.online, OpenCloud) that
plays the app side of every protocol of the cloud file sharing plan, before
and beside the real apps (`_devextras/planning/20261013-cloud-file-sharing/`,
row 45, brief E-9). Development only: nothing here asks for a login.

It answers like an app:

- `POST /inbound` takes Synaplan's signed notifications. Anything that is not
  signed by Synaplan for this instance, addressed to it, and at most five
  minutes old is refused with `401` (the five vectors in
  `backend/tests/Fixtures/cloud-apps-contract/notify-vectors.json`).
- **Copies** (Copy to cloud, CF3.1): claim, download, write into the person's
  files, report the result. A copy with no folder lands in the kind folder
  Synaplan's menu names (`Synaplan/Documents`, `Synaplan/Images`, …).
- **Folder reads** (pickups, CF3.6): claim, check the folder against what the
  person lets Synaplan read, list it, upload what Synaplan asks for, report.
- **Linking** a person through Synaplan's confirm page and the one-time code.
- **Revoked links** (`identity_revoked`): the person is no longer linked.

Each core brief that adds an app-facing protocol adds its app side here in the
same pull request (`08_agent_briefs.md` §0.8, DoD-11).

## Start

```bash
docker compose up -d                      # the Synaplan dev stack
docker compose -f _devextras/testing/cloud-apps/fixture-app/compose.yml up -d
_devextras/testing/cloud-apps/env-check.sh   # group "fixture": PASS
```

Synaplan reaches it as `http://cloud-fixture:8099` on the Docker network; the
host port `127.0.0.1:8099` is for scripts and the browser. Its data (state,
files, log) lives in the volume `cloud-fixture-data`.

Offline checks (the notification vectors, both profiles, the path rules):

```bash
tar c _devextras/testing/cloud-apps/fixture-app backend/tests/Fixtures/cloud-apps-contract \
  | docker run -i --rm php:8.4-cli sh -c 'mkdir /r && cd /r && tar x && php _devextras/testing/cloud-apps/fixture-app/selftest.php'
```

(The pipe stands in for a bind mount, which does not see WSL paths when
Docker Desktop runs the containers.)

## Set it up for a walk

```bash
F=http://localhost:8099/control

# 1. Register. Without an admin key the instance waits for an administrator
#    (People → Platform instances → Approve); with one it is active at once.
curl -s -X POST $F/register -H 'content-type: application/json' -d '{"client":"owncloud"}'

# 2. Link a person: open this in a browser signed in to Synaplan, confirm.
#    http://localhost:8099/link/start?user=ada

# 3. What ada lets Synaplan read (kept here, reported to Synaplan).
curl -s -X POST $F/scope -H 'content-type: application/json' \
  -d '{"person":"ada","mode":"allowed","folders":["Invoices"]}'

# 4. Give ada files.
curl -s -X PUT --data-binary @inv-0101.txt $F/files/ada/Invoices/inv-0101.txt
```

Register as `owncloud` when the person also has a WebDAV folder connection on
the `nextcloud` channel, so the planner sees two distinct folder channels.

## Control endpoints

| Call | What it does |
| ---- | ------------ |
| `GET /control/state` | Profile, client, credential (secret shown as `set`), switches, scopes, linked people |
| `POST /control/register` `{client?, admin_key?}` | Register with Synaplan, announce the inbound address and the capabilities (`deliveries`, `pickups`), read the notify keys. `admin_key` is used for this call only |
| `POST /control/credential` `{instance_id, instance_secret, client?}` | Use an instance registered elsewhere; announces and reads keys like `register` |
| `POST /control/scope` `{person, mode, folders}` | `mode` is `watched`, `allowed` or `all`; folders are paths |
| `POST /control/sso-status` `{external_id, bearer}` | Asks Synaplan whether this platform user is bound. The answer is Synaplan's |
| `POST /control/bind` `{external_id, bearer}` | Binds the person (`sso/bind`) with the exchanged token and this instance's credential |
| `POST /control/adopt` `{external_id, api_key}` | Adopts a provisioned person's legacy row with that person's key |
| `PUT /control/files/{person}/{path}` | Put a file (the request body is the content) |
| `GET /control/files/{person}` | List a person's files |
| `DELETE /control/files/{person}/{path}` | Remove a file |
| `GET /control/written` | The copies Synaplan wrote |
| `GET /control/log` | The last notifications and what came of them |
| `POST /control/profile` `{profile}` | `keyed` or `sso` |
| `POST /control/switch` `{name, on}` | One of the three test switches below |
| `POST /control/reset` | Forget everything: state, files, log |

`GET /files/{person}/{path}` serves a file; it is the "Open in …" address a
copy reports.

## Profiles

- `keyed` (default) behaves like Nextcloud: numeric file ids, bare etags.
- `sso` behaves like OpenCloud: `storage$space!node` ids, quoted etags.
  `sso-status` and `bind` are the organisation-login calls; the background
  key comes with CF2.3.

## Test switches

The three switches every app offers in development (`08` §0.8):

| Switch | Effect |
| ------ | ------ |
| `hold_inbound` | A verified notification is accepted with `202` and nothing is done (the pending / unconfirmed path) |
| `mute_events` | File events are not forwarded. No effect until CF4.1 adds source events |
| `allow_self_write` | A watch may write into its own folder. No effect until CF5.1 adds watches |
