/**
 * Widget-build stand-in for `@/router`.
 *
 * `httpClient` dynamically imports the SPA router on 401 / SETUP_REQUIRED.
 * The embed uses `inlineDynamicImports`, so that `import()` would otherwise
 * pull every lazy view plus the app i18n loader (all 55 locale files) into
 * `widget.js`. The embed has no Vue Router — session errors stay in-widget.
 */
export default {
  push(): Promise<void> {
    return Promise.resolve()
  },
  replace(): Promise<void> {
    return Promise.resolve()
  },
}
