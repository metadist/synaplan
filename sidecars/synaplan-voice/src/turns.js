export const TURN_SETTLE_MS = 1500

const otherRole = (role) => (role === 'user' ? 'assistant' : 'user')

/** Drops sound tags such as "[hum]" or "[burp]" that are not words. */
export function spokenText(text) {
  return String(text ?? '')
    .replace(/\[[^\]\n]{1,32}\]/g, ' ')
    .replace(/[ \t]{2,}/g, ' ')
    .trim()
}

/**
 * Builds one transcript turn per speaker from provider pieces, so the chat
 * shows one growing message per turn.
 *
 * Full-duplex providers interleave the caller's and the assistant's words,
 * and an echo of the reply can reach the microphone. A speaker change alone
 * therefore does not end a turn. A turn ends when the provider says so, when
 * the call ends, or when the other speaker talks after this one has been
 * quiet for TURN_SETTLE_MS. A finished assistant reply also closes the
 * caller's open sentence, so what they say next is a new message instead of
 * being written back into the sentence the reply answered. Providers that
 * close every turn themselves pass autoCommit false and only end turns
 * explicitly.
 *
 * emit receives { role, text, final }: a partial for every piece, then one
 * final with the whole turn.
 */
export function createTurnAssembler(emit, { now = () => Date.now(), settleMs = TURN_SETTLE_MS, autoCommit = true } = {}) {
  const open = new Map()
  const lastWordAt = new Map()
  const lastFinal = new Map()

  const quietSince = (role, at) => at - (lastWordAt.get(role) ?? Number.NEGATIVE_INFINITY) >= settleMs
  const spokeAfter = (role, than) =>
    (lastWordAt.get(role) ?? Number.NEGATIVE_INFINITY) > (lastWordAt.get(than) ?? Number.NEGATIVE_INFINITY)

  const finish = (role, pending, finalText) => {
    const text = spokenText(finalText || pending?.text)
    if (text === '') return
    if (!pending && lastFinal.get(role) === text) return
    lastFinal.set(role, text)
    emit({ role, text, final: true })
  }

  const commit = (role, finalText, cascade = true) => {
    const pending = open.get(role)
    open.delete(role)
    // A completed reply closes the sentence it answered. Store the one that
    // started first, so a reload keeps the order the person saw.
    const caller = cascade && role === 'assistant' ? open.get('user') : undefined
    if (caller) open.delete('user')
    const callerFirst = Boolean(caller && (!pending || caller.at <= pending.at))
    if (callerFirst) finish('user', caller)
    finish(role, pending, finalText)
    if (caller && !callerFirst) finish('user', caller)
  }

  const write = (role, nextText) => {
    const at = now()
    if (autoCommit) {
      const them = otherRole(role)
      if (open.has(them) && quietSince(them, at)) commit(them)
      if (open.has(role) && spokeAfter(them, role) && quietSince(role, at)) commit(role)
    }
    const pending = open.get(role)
    const text = nextText(pending?.text ?? '')
    lastWordAt.set(role, at)
    open.set(role, { text, at: pending?.at ?? at })
    const spoken = spokenText(text)
    if (spoken !== '') emit({ role, text: spoken, final: false })
  }

  return {
    /** Adds an incremental piece to the speaker's open turn. */
    append(role, piece) {
      if (!piece) return
      write(role, (previous) => `${previous}${piece}`)
    },
    /** Replaces the open turn with a cumulative transcript. */
    replace(role, text) {
      if (!text) return
      write(role, () => String(text))
    },
    /** Ends the speaker's turn; a provider's final text wins over the pieces. */
    commit,
    commitAll() {
      commit('user')
      commit('assistant')
    },
    /** The open turn, or the last finished one, for the speaker. */
    text(role) {
      const pending = open.get(role)
      return pending ? spokenText(pending.text) : (lastFinal.get(role) ?? '')
    },
  }
}
