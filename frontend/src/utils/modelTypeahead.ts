const NO_MATCH = 4

const editDistance = (left: string, right: string): number => {
  const rows = left.length + 1
  const cols = right.length + 1
  const grid = Array.from({ length: rows }, () => new Array<number>(cols).fill(0))
  for (let row = 0; row < rows; row += 1) grid[row][0] = row
  for (let col = 0; col < cols; col += 1) grid[0][col] = col
  for (let row = 1; row < rows; row += 1) {
    for (let col = 1; col < cols; col += 1) {
      const cost = left[row - 1] === right[col - 1] ? 0 : 1
      grid[row][col] = Math.min(
        grid[row - 1][col] + 1,
        grid[row][col - 1] + 1,
        grid[row - 1][col - 1] + cost
      )
    }
  }
  return grid[left.length][right.length]
}

/** One typo against the start of a name, so "cha" still reaches "Claude". */
const missesPrefixByOne = (text: string, needle: string): boolean => {
  if (needle.length < 2 || text.length < needle.length) return false
  return editDistance(text.slice(0, needle.length), needle) === 1
}

/**
 * How well a label matches a typed query. Lower is better.
 * 0 = the name starts with it, 1 = a word does, 2 = the name contains it,
 * 3 = the start of the name or a word is one character off.
 */
const matchRank = (label: string, query: string): number => {
  const name = label.toLocaleLowerCase()
  const needle = query.toLocaleLowerCase()
  if (needle === '') return NO_MATCH
  if (name.startsWith(needle)) return 0
  const words = name.split(/\s+/)
  if (words.some((word) => word.startsWith(needle))) return 1
  if (needle.length >= 2 && name.includes(needle)) return 2
  if (missesPrefixByOne(name, needle) || words.some((word) => missesPrefixByOne(word, needle))) {
    return 3
  }
  return NO_MATCH
}

export const matchTypeaheadIndex = (
  labels: string[],
  query: string,
  fromIndex: number,
  skipCurrent: boolean
): number => {
  if (labels.length === 0 || query === '') return -1

  const ranks = labels.map((label) => matchRank(label, query))
  const best = Math.min(...ranks)
  if (best === NO_MATCH) return -1

  const start = Math.max(0, skipCurrent ? fromIndex + 1 : fromIndex)
  for (let step = 0; step < labels.length; step += 1) {
    const index = (((start + step) % labels.length) + labels.length) % labels.length
    if (ranks[index] === best) return index
  }
  return -1
}

/**
 * Extend the typed buffer by one character and return the list index to focus.
 * A character that matches nothing is ignored, so a stray key does not wipe the buffer.
 * Repeating the same single character moves to the next name that starts with it.
 */
export const nextTypeaheadTarget = (
  labels: string[],
  query: string,
  char: string,
  fromIndex: number
): { query: string; index: number } => {
  const letter = char.toLocaleLowerCase()
  const origin = fromIndex < 0 ? 0 : fromIndex
  const repeat = query.length === 1 && query === letter
  const candidate = repeat ? letter : `${query}${letter}`
  const index = matchTypeaheadIndex(labels, candidate, origin, repeat)
  if (index < 0) return { query, index: -1 }
  return { query: candidate, index }
}
