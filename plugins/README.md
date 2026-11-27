# Synaplan plugins

Plugins live one-per-directory here, each with a `manifest.json` and a
`backend/` (PSR-4 under the manifest `namespace`) and/or `frontend/`. The kernel
discovers `plugins/*/manifest.json`, autoloads the backend namespace, and
autoconfigures its services. The plugins directory can be overridden with the
`PLUGINS_DIR` environment variable (defaults to `/plugins`, then
`backend/plugins`).

## In this image

Seven directories ship in the image:

| Plugin          | What it is                                    | Locales            | Still open                                                    |
| --------------- | --------------------------------------------- | ------------------ | ------------------------------------------------------------- |
| `synascriber`   | Meeting notes for Jitsi and OpenTalk          | `locales/`, five   | manifest `homepage` points at an archived repository          |
| `synamail`      | Contact profiles for the Outlook add-in       | `locales/`, five   | —                                                             |
| `synaform`      | Forms, Word templates and document generation | `i18n/`, no French | controller and page over the size bar, outside PHPStan/ESLint |
| `synafastbill`  | FastBill from the chat with `/fastbill`       | `i18n/`, no French | French, and a walk against a live FastBill account            |
| `castingdata`   | Casting platform data in chat                 | none, English page | locale files                                                  |
| `serper_search` | Reference `web_search` plug adapter, no page  | —                  | —                                                             |
| `hello_world`   | Smallest page and route, for plugin authors   | `locales/`, five   | —                                                             |

A plugin with a page is installed per user with
`app:plugin:install <userId> <id>`; `DEFAULT_USER_PLUGINS` is empty unless
you set it. The page, the Apps card and the menu entry show the manifest's
`displayName`, or the id in words when there is none.

`synamail` stores contact profiles for the Outlook add-in. The add-in
itself stays in [metadist/Synamail](https://github.com/metadist/Synamail).
It is not added to `DEFAULT_USER_PLUGINS`. To leave it out of a build you
make yourself, delete `plugins/synamail` before the image is built. A switch that hides
a plugin inside an image you pulled, without rebuilding, is a later step
and is not available yet. The rules for a plugin we ship are in
[`_devextras/planning/20261104-hosted-plugins/01_rules.md`](../_devextras/planning/20261104-hosted-plugins/01_rules.md).

## Frontend files

Files under `plugins/<id>/frontend/` are served publicly at
`/api/v1/plugins/<id>/assets/<path>` (and, for the plugin page inside
Synaplan, at `/api/v1/user/<userId>/plugins/<id>/assets/<path>` once the user
installed the plugin). Use the first address for pages outside Synaplan, such
as a script tag in another app: it does not depend on an account. Nothing
outside `frontend/` is reachable, so never put a secret there.

## Contributing a plug adapter

A **plug** is a capability slot with interchangeable providers. A plugin can
contribute an adapter to one of these ports:

| Port         | Interface                                             | Registry               |
| ------------ | ---------------------------------------------------- | ---------------------- |
| `extraction` | `App\Plug\Extraction\ContentExtractorInterface`      | `ExtractionRegistry`   |
| `web_search` | `App\Plug\WebSearch\WebSearchProviderInterface`      | `WebSearchRegistry`    |
| `rerank`     | `App\Plug\Rerank\RerankProviderInterface`            | `RerankRegistry`       |

Steps:

1. **Pick the port** and implement its interface from `backend/src/Plug/<Port>/`
   in a class under your plugin's own namespace.
2. **Return a complete `PlugDescriptor`** from `descriptor()`: `key`, `label`,
   `docsUrl`, `requiredSettings`, sovereignty (`self-hosted` / `eu` / `us_cloud`)
   and your `pluginId` (so the admin UI can show "from plugin X").
3. **Secrets** go through `App\Plug\PlugKeyStore`: read with
   `getKey('<key>')`, declare `"plug_keys"` in the manifest `permissions`, and
   never read an env var directly or log the key. Your adapter's key is
   automatically allowed once it is declared in `provides.plugs`.
4. **`health()` must be cheap, config-only, and never throw** — a missing key
   or URL returns `PlugHealth::unavailable('<reason>')`. Do not do live I/O
   here: chat search calls `health()` on every request. For a live
   reachability / credential check (admin badges, key verification), also
   implement `App\Plug\WebSearch\WebSearchLiveProbeInterface` (`probe()`).
   Plugins that skip that interface still load; the admin UI then shows
   "Key stored — not verified" instead of a live badge. For the search
   operation itself, return an empty result when there is genuinely nothing
   to return; a transient upstream failure (timeout, HTTP 5xx/429) may throw
   — the registry catches it and falls back to another provider, so a down
   provider never breaks chat or an upload while a recoverable error still
   triggers a retry.
5. **Declare the adapter** in `manifest.json` under `provides.plugs` — the
   backend refuses to boot otherwise:

   ```json
   {
     "id": "serper_search",
     "namespace": "Plugin\\SerperSearch",
     "provides": {
       "plugs": [
         {
           "port": "web_search",
           "class": "Plugin\\SerperSearch\\Plug\\SerperSearchAdapter",
           "key": "serper"
         }
       ]
     },
     "permissions": ["network:google.serper.dev", "plug_keys"]
   }
   ```

   The `class` must live under the plugin `namespace`; the `key` must match your
   adapter's `key()`. Two adapters on the same port may not share a key (prefix
   with the plugin id when in doubt) — a collision is a boot error.
6. **Ship a contract test** under `backend/tests/Plugin/<PluginId>/`.
   `plugins/<id>/backend/tests/` keeps fixtures only — PHPUnit, PHPStan and
   php-cs-fixer already reach the test tree next to the app.
7. **Locales:** a plugin page ships `frontend/locales/{de,en,es,fr,tr}.json`.
   Synaplan loads the active locale (English as fallback) and passes `t(key)`
   and `locale` into `mount`. Keys are the plugin's own. A script outside the
   app, such as the Jitsi loader, fetches
   `/api/v1/plugins/<id>/assets/locales/<lang>.json`.
8. **Checks.** Plugin PHP is in `make -C backend lint` and `make -C backend phpstan`
   (level 5, findings fixed in the plugin). Plugin JavaScript is in
   `make -C frontend lint` (ESLint + Prettier). `make plugins-format` writes
   the fixes; the running containers mount `plugins/` read-only. A host
   PHPStan run without `/plugins` fails, which is the honest outcome — the
   gate runs in the container and in CI, where `/plugins` exists.

See `plugins/serper_search/` for a complete, minimal reference: one manifest,
one adapter, a pure result mapper, and a fixture. Its behavioural contract is
exercised by `backend/tests/Integration/Plug/SerperSearchAdapterTest.php`.
