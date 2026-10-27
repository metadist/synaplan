import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const runtime = vi.hoisted(() => ({
  cloud_files: undefined as
    { glyphs?: { bird?: string | null; bird_eyes?: string | null } } | undefined,
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => runtime,
}))

import CloudGlyph from '@/components/files/CloudGlyph.vue'

describe('CloudGlyph', () => {
  it('draws the bundled bird when no override is set', () => {
    runtime.cloud_files = undefined
    const wrapper = mount(CloudGlyph, { props: { size: 16 } })
    expect(wrapper.find('svg').exists()).toBe(true)
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('draws the eyes when a watch is shown', () => {
    runtime.cloud_files = { glyphs: { bird: '', bird_eyes: '' } }
    const wrapper = mount(CloudGlyph, { props: { eyes: true, badge: 'pause', size: 20 } })
    expect(wrapper.findAll('circle').length).toBeGreaterThan(0)
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('uses the white-label urls for both base glyphs', () => {
    runtime.cloud_files = {
      glyphs: {
        bird: 'https://cdn.example/bird.svg',
        bird_eyes: 'https://cdn.example/eyes.svg',
      },
    }
    const bird = mount(CloudGlyph)
    expect(bird.find('img').attributes('src')).toBe('https://cdn.example/bird.svg')
    const eyes = mount(CloudGlyph, { props: { eyes: true } })
    expect(eyes.find('img').attributes('src')).toBe('https://cdn.example/eyes.svg')
  })
})
