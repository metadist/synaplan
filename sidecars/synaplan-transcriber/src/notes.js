export function meetingMarkdown({ title, when, language, lines }) {
  const body = lines.length
    ? lines.map((line) => (line.speaker ? `**${line.speaker}:** ${line.text}` : line.text)).join('\n\n')
    : 'No speech in this meeting.'
  return `# ${title}\n\n${when}\n\nLanguage: ${language || 'auto'}\n\nAudio was not kept.\n\n${body}\n`
}

export function safeFilename(meetingId, when = new Date()) {
  const day = when.toISOString().slice(0, 10)
  const clock = when.toISOString().slice(11, 19).replace(/:/g, '')
  const slug = String(meetingId || 'meeting')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 40) || 'meeting'
  return `${day}-${clock}-${slug}.md`
}

/**
 * One sentence for the end of a meeting.
 * `destinations` lists only places the operator turned on.
 * Each item: `{ name, ok, detail }`.
 */
export function notesSavedSentence(destinations, { hadSpeech = false } = {}) {
  const done = destinations.filter((item) => item.ok).map((item) => item.detail)
  const failed = destinations.filter((item) => !item.ok).map((item) => item.name)
  if (!done.length && failed.length) {
    if (hadSpeech) {
      return 'Notes could not be saved. Captions were shown in the meeting. Audio was not kept. Try again or Disconnect.'
    }
    return 'Notes could not be saved. The meeting was not recorded. Try again or Disconnect.'
  }
  if (!done.length) {
    return 'Captions were shown. Audio was not kept.'
  }
  const missed = failed.length ? ` ${failed.join(' and ')} did not receive a copy.` : ''
  return `${done.join(' ')} Audio was not kept.${missed}`
}
