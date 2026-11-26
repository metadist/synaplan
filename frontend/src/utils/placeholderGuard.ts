/**
 * Chat text may carry placeholders such as `[Memory: 3]` that the renderer
 * swaps for badge markup on the sanitised HTML string. A placeholder inside an
 * attribute value (`<a title="[Memory: 3]">`) would get that markup written
 * into the attribute, where its quotes end the value early.
 *
 * This puts an invisible word joiner behind every `[` in attribute values, so
 * no placeholder pattern matches there. In a link or image address the `[` is
 * percent-encoded instead, which keeps the address working; the bracket of an
 * IPv6 host stays. Text nodes are left alone.
 */
const WORD_JOINER = '⁠'
const URL_ATTRIBUTES = new Set(['href', 'src'])
const BRACKET_OUTSIDE_HOST = /(?<!\/\/)\[/g
const ATTRIBUTE_WITH_BRACKET = /=\s*(?:"[^"]*\[|'[^']*\[|[^\s"'>]*\[)/

export function breakPlaceholdersInAttributes(html: string): string {
  if (!ATTRIBUTE_WITH_BRACKET.test(html)) {
    return html
  }

  const template = document.createElement('template')
  template.innerHTML = html
  for (const element of Array.from(template.content.querySelectorAll('*'))) {
    for (const attribute of Array.from(element.attributes)) {
      if (!attribute.value.includes('[')) {
        continue
      }
      const safe = URL_ATTRIBUTES.has(attribute.name)
        ? attribute.value.replace(BRACKET_OUTSIDE_HOST, '%5B')
        : attribute.value.replaceAll('[', `[${WORD_JOINER}`)
      element.setAttribute(attribute.name, safe)
    }
  }

  return template.innerHTML
}
