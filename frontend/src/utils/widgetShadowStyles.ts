/**
 * Adapts the application stylesheet for injection into the widget's Shadow DOM.
 *
 * Only `:root` is remapped: it carries the design tokens the widget UI reads
 * (`--bg-app`, `--txt-primary`, …), and inside a shadow tree those have to live
 * on `:host`.
 *
 * Document-level `html` / `body` rules are deliberately left untouched — they
 * simply never match inside a shadow root. Remapping them onto `:host` painted
 * the widget host, a `position: fixed; inset: 0` layer spanning the whole
 * viewport, with the app background and hid the embedding page behind an opaque
 * surface. The host must stay transparent; the chat chrome and the explicit
 * fullscreen backdrop are the only surfaces allowed to cover host page content.
 */
export function adaptStylesForShadowDom(css: string): string {
  return css.replace(/:root\b/g, ':host')
}
