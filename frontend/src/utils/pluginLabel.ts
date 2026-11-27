/**
 * The name people see for an installed plugin: its display name, or its id in
 * words (`hello_world` reads "Hello world"). Empty when the plugin has neither.
 */
export function pluginLabel(plugin: { name?: string; displayName?: string }): string {
  const displayName = plugin.displayName?.trim()
  if (displayName) return displayName
  const words = (plugin.name ?? '').replace(/[_-]+/g, ' ').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}
