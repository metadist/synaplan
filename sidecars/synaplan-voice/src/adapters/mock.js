function listen(map, event, fn) {
  const list = map.get(event) ?? []
  list.push(fn)
  map.set(event, list)
}

function emit(map, event, payload) {
  for (const fn of map.get(event) ?? []) fn(payload)
}

/**
 * Speaks enough of one provider dialect for the scripted client.
 * No network. The first audio chunk asks Synaplan; inject speaks the answer.
 */
export function createMockAdapter(dialect) {
  const listeners = new Map()
  const adapter = {
    dialect,
    injects: [],
    closed: false,
    asked: false,
    on: (event, fn) => listen(listeners, event, fn),
    async open() {},
    sendAudio() {
      if (adapter.asked) return
      adapter.asked = true
      emit(listeners, 'transcript', { role: 'user', text: 'What time is it?', final: true })
      if (dialect === 'mock-google') {
        emit(listeners, 'tool', { id: 'call-1', name: 'consult_synaplan', question: 'What time is it?' })
        return
      }
      emit(listeners, 'delegate', { id: 'del-1', question: 'What time is it?' })
    },
    interrupt() {
      emit(listeners, 'interrupted')
    },
    inject(payload) {
      adapter.injects.push(payload)
      if (payload.kind === 'thinking') return
      emit(listeners, 'transcript', { role: 'assistant', text: String(payload.text ?? ''), final: true })
      emit(listeners, 'audio', Buffer.from([1, 2, 3, 4]))
      emit(listeners, 'usage', {
        seconds: 1,
        audioInTokens: 25,
        audioOutTokens: 25,
        audioCacheTokens: 0,
        textInTokens: 5,
        textOutTokens: 8,
      })
    },
    close() {
      adapter.closed = true
      emit(listeners, 'closed')
    },
  }
  return adapter
}
