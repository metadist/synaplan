import { describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import ProfileTimezoneField from '@/components/settings/ProfileTimezoneField.vue'
import { profileSettingsKey, type ProfileSettings } from '@/composables/useProfileSettings'
import { mockProfile } from '@/mocks/profile'
import type { UserProfile } from '@/mocks/profile'

function mountField(timezone = 'Europe/Berlin') {
  const formData = ref<UserProfile>({ ...mockProfile, timezone })
  const profileLoaded = ref(true)
  const submits: Event[] = []
  const Host = defineComponent({
    setup() {
      return () =>
        h(
          'form',
          {
            onSubmit: (event: Event) => {
              submits.push(event)
            },
          },
          [h(ProfileTimezoneField)]
        )
    },
  })
  const wrapper = mount(Host, {
    global: {
      provide: {
        [profileSettingsKey as symbol]: {
          formData,
          profileLoaded,
        } as ProfileSettings,
      },
    },
  })
  return { wrapper, formData, submits }
}

describe('ProfileTimezoneField', () => {
  it('shows matching places as soon as a city is typed', async () => {
    const { wrapper } = mountField()
    const input = wrapper.get('[data-testid="input-timezone-search"]')
    expect(wrapper.find('[data-testid="list-timezone-options"]').exists()).toBe(false)

    await input.trigger('focus')
    expect(wrapper.find('[data-testid="list-timezone-options"]').exists()).toBe(true)

    await input.setValue('tokyo')
    await flushPromises()

    const list = wrapper.get('[data-testid="list-timezone-options"]')
    expect(list.text()).toContain('Asia/Tokyo')
    expect(wrapper.get('[data-testid="timezone-match-count"]').text()).toMatch(/1/)
    expect(list.text()).not.toContain('Pacific/Midway')
  })

  it('chooses the highlighted place on Enter and does not submit the form', async () => {
    const { wrapper, formData, submits } = mountField()
    const input = wrapper.get('[data-testid="input-timezone-search"]')
    await input.setValue('tokyo')
    await input.trigger('keydown', { key: 'Enter' })

    expect(formData.value.timezone).toBe('Asia/Tokyo')
    expect(wrapper.get('[data-testid="timezone-current"]').text()).toContain('Asia/Tokyo')
    expect(wrapper.find('[data-testid="list-timezone-options"]').exists()).toBe(false)
    expect(submits).toHaveLength(0)
  })

  it('does not change the time zone when Enter is pressed in an empty search', async () => {
    const { wrapper, formData, submits } = mountField()
    const input = wrapper.get('[data-testid="input-timezone-search"]')
    await input.trigger('focus')
    await input.trigger('keydown', { key: 'Enter' })

    expect(formData.value.timezone).toBe('Europe/Berlin')
    expect(submits).toHaveLength(0)
  })

  it('says when nothing matches', async () => {
    const { wrapper } = mountField()
    await wrapper.get('[data-testid="input-timezone-search"]').setValue('xyzzy')
    expect(wrapper.get('[data-testid="timezone-no-match"]').text()).toContain('No timezone matches')
  })

  it('finds a place by its offset', async () => {
    const { wrapper } = mountField()
    await wrapper.get('[data-testid="input-timezone-search"]').setValue('+05:45')
    const list = wrapper.get('[data-testid="list-timezone-options"]')
    expect(list.text()).toMatch(/Kathmandu|Katmandu/)
  })
})
