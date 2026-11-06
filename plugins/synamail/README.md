# Synamail — contact profiles

Rolling profiles of the people you mail. The [Synamail Outlook add-in](https://github.com/metadist/Synamail) sends one email at a time; this plugin merges it into a summary, a tone, stable facts and open loops, and stores the result in your workspace. The add-in works without this plugin. Profiling then explains that the plugin is not installed.

The page in Synaplan lists every profile and deletes one when you confirm. **How to install in Outlook** opens the install section of the [Synamail docs](https://docs.synaplan.com/synamail#install-it).

## What is stored

One row per contact in `plugin_data` (type `synamail_profile`), for that user only:

- the email address, and a display name when the add-in sent one
- the summary, tone, facts and open loops
- how many emails were rolled in, first seen, last received, last sent
- the organisation, taken from the domain (free-mail domains stay empty)

The storage key is a hash of the address. The address itself is inside the JSON.

## What leaves the server

The stored narrative and that one email go to the user's chat model. Nothing is sent until the add-in asks for an update. This plugin does not read a mailbox.

## Install it for one user

The directory ships in the Synaplan image. It is not installed for anyone until you say so. `DEFAULT_USER_PLUGINS` is left unchanged.

```bash
docker compose exec backend php bin/console app:plugin:install <userId> synamail
```

Open **Plugins → Synamail**, or visit `/plugins/synamail`.

## Leave it out

- Do not run the install command. A person who does not have it sees an unknown page, not an empty one.
- Source build: delete `plugins/synamail` before `docker build` or before `docker compose up`. The kernel never sees the manifest.
- Uninstall for one person with the existing uninstall command. That removes their symlink. The code stays in the image.
- Deleting the directory inside a running container is undone by the next image pull.
- A switch that hides the plugin inside a pulled image, without rebuilding, is a later step and is not available yet.

Settings live in `BCONFIG` group `P_synamail`: `enabled` (default on), `profile_language` (`auto`), `max_summary_words` (`150`). Turning `enabled` off answers the profile routes with one sentence and does not call a model.
