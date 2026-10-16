// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { lineCount, measureSource } from './sourceMetrics'

/**
 * The measurement behind the frontend size ratchet: how a function is found,
 * how long it is, and the key it is frozen under.
 */
describe('sourceMetrics', () => {
  it('counts a function from its first to its last line and keys a nested one by its parents', () => {
    const code = [
      'export function streamAnswer() {',
      '  const onUpdate = (payload: string) => {',
      '    return payload',
      '  }',
      '  return onUpdate',
      '}',
    ].join('\n')

    expect(measureSource(code, 'stream.ts').functionLines).toEqual({
      streamAnswer: 6,
      'streamAnswer.onUpdate': 3,
    })
  })

  it('names an object method and an anonymous callback after their property', () => {
    const code = [
      'export const handlers = {',
      '  complete() {',
      '    return 1',
      '  },',
      '  error: function () {',
      '    return 2',
      '  },',
      '}',
      'streamMessage({ onUpdate: (payload: string) => payload })',
    ].join('\n')

    expect(Object.keys(measureSource(code, 'handlers.ts').functionLines)).toEqual([
      'complete',
      'error',
      'onUpdate',
    ])
  })

  it('names a callback passed to a call or constructor after the variable it initialises, else after the callee', () => {
    const code = [
      "export const useChatsStore = defineStore('chats', () => {",
      '  onMounted(() => {})',
      '  return new Promise((resolve) => resolve({}))',
      '})',
    ].join('\n')

    expect(Object.keys(measureSource(code, 'chats.ts').functionLines)).toEqual([
      'useChatsStore',
      'useChatsStore.onMounted',
      'useChatsStore.Promise',
    ])
  })

  it('numbers a repeated name in source order', () => {
    const code = ['watch(a, () => {})', 'watch(b, () => {})', 'watch(c, () => {})'].join('\n')

    expect(Object.keys(measureSource(code, 'watchers.ts').functionLines)).toEqual([
      'watch',
      'watch#2',
      'watch#3',
    ])
  })

  it('reads both script blocks of a .vue file and leaves the template alone', () => {
    const code = [
      '<template>',
      '  <button @click="() => save()">Save</button>',
      '</template>',
      '<script lang="ts">',
      'export function helper() {',
      '  return 1',
      '}',
      '</script>',
      '<script setup lang="ts">',
      'const save = () => {',
      '  helper()',
      '}',
      '</script>',
    ].join('\n')

    expect(measureSource(code, 'SaveButton.vue')).toEqual({
      lines: 13,
      functionLines: { helper: 3, save: 3 },
    })
  })

  it('counts the lines of a file with or without a final newline', () => {
    expect(lineCount('')).toBe(0)
    expect(lineCount('one\ntwo')).toBe(2)
    expect(lineCount('one\ntwo\n')).toBe(2)
  })
})
