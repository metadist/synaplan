/** One tag per line. Blank lines are dropped. The server still normalizes the list. */
export function parseTagLines(value: string): string[] {
  const tags: string[] = []
  const seen = new Set<string>()
  for (const line of value.split('\n')) {
    const tag = line.trim()
    if (tag === '' || seen.has(tag)) continue
    seen.add(tag)
    tags.push(tag)
  }
  return tags
}
