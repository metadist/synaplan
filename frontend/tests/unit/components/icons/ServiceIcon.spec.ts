import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceIcon from '@/components/icons/ServiceIcon.vue'

// `<script setup>` resolves the imported component directly, so a name-based
// stub never matches — the module has to be replaced instead.
vi.mock('@iconify/vue', () => ({
  Icon: { name: 'Icon', props: ['icon'], template: '<i :data-icon="icon" />' },
}))

const iconsOf = (service: string, props: Record<string, unknown> = {}) =>
  mount(ServiceIcon, { props: { service, ...props } })
    .findAll('i')
    .map((i) => i.attributes('data-icon'))

describe('ServiceIcon', () => {
  it('shows the jurisdiction flag by default, because model pickers rely on it', () => {
    expect(iconsOf('openai')).toEqual(['simple-icons:openai', 'circle-flags:us'])
  })

  it('drops the flag when a surface asks for the bare logo', () => {
    expect(iconsOf('openai', { showFlag: false })).toEqual(['simple-icons:openai'])
  })

  it('has no flag to show for an empty service', () => {
    expect(iconsOf('')).toEqual(['mdi:robot'])
  })
})
