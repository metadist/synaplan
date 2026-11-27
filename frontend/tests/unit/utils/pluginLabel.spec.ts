import { describe, expect, it } from 'vitest'
import { pluginLabel } from '@/utils/pluginLabel'

describe('pluginLabel', () => {
  it('uses the display name the plugin declares', () => {
    expect(pluginLabel({ name: 'synascriber', displayName: ' Meeting notes ' })).toBe(
      'Meeting notes'
    )
  })

  it('reads the id in words when there is no display name', () => {
    expect(pluginLabel({ name: 'hello_world' })).toBe('Hello world')
    expect(pluginLabel({ name: 'serper-search', displayName: '  ' })).toBe('Serper search')
    expect(pluginLabel({ name: 'synaform' })).toBe('Synaform')
  })

  it('is empty when the plugin has neither', () => {
    expect(pluginLabel({})).toBe('')
  })
})
