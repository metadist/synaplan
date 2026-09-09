# Synaplan plugins

Plugins live one-per-directory here, each with a `manifest.json` and a
`backend/` (PSR-4 under the manifest `namespace`) and/or `frontend/`. The kernel
discovers `plugins/*/manifest.json`, autoloads the backend namespace, and
autoconfigures its services. The plugins directory can be overridden with the
`PLUGINS_DIR` environment variable (defaults to `/plugins`, then
`backend/plugins`).

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
4. **`health()` must be cheap and never throw.** A network failure or a missing
   key returns `PlugHealth::unavailable('<reason>')`; a search/extraction/rerank
   failure returns an empty result, never an exception (a down provider must
   never break chat or an upload).
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
6. **Ship a contract test** on recorded fixtures under
   `plugins/<id>/backend/tests/` — no live network in tests.
7. **Locales:** descriptor labels are plain strings; plugins have no i18n hook
   in v1 (documented limitation).

See `plugins/serper_search/` for a complete, minimal reference: one manifest,
one adapter, a pure result mapper, and a fixture. Its behavioural contract is
exercised by `backend/tests/Integration/Plug/SerperSearchAdapterTest.php`.
