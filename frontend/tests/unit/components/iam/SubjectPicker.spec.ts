import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SubjectPicker from '@/components/iam/SubjectPicker.vue'
import { iamApi } from '@/services/api/iamApi'

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    searchSubjects: vi.fn().mockResolvedValue({ subjects: [], personScope: 'shared-group' }),
  },
}))

const mountPicker = () =>
  mount(SubjectPicker, {
    props: { modelValue: null, active: true },
  })

describe('SubjectPicker', () => {
  it('says when no person or group matches', async () => {
    vi.mocked(iamApi.searchSubjects).mockResolvedValueOnce({
      subjects: [],
      personScope: 'shared-group',
    })
    vi.useFakeTimers()
    try {
      const wrapper = mountPicker()
      await wrapper.get('[data-testid="input-iam-subject-search"]').setValue('zzz')
      await wrapper.get('[data-testid="input-iam-subject-search"]').trigger('focus')
      await vi.advanceTimersByTimeAsync(250)
      await flushPromises()

      expect(wrapper.get('[data-testid="text-iam-no-matches"]').text()).toBe(
        'No one in a group you share, and no group, matches that name.'
      )
      wrapper.unmount()
    } finally {
      vi.useRealTimers()
    }
  })

  it('shows try again when search fails', async () => {
    vi.mocked(iamApi.searchSubjects).mockRejectedValueOnce(new Error('network'))
    vi.useFakeTimers()
    try {
      const wrapper = mountPicker()
      await wrapper.get('[data-testid="input-iam-subject-search"]').trigger('focus')
      await vi.advanceTimersByTimeAsync(250)
      await flushPromises()

      expect(wrapper.get('[data-testid="text-iam-search-failed"]').text()).toContain(
        "Couldn't search people or groups."
      )
      vi.mocked(iamApi.searchSubjects).mockResolvedValueOnce({
        subjects: [{ type: 'group', id: 2, name: 'Sales', pinned: true }],
        personScope: 'shared-group',
      })
      await wrapper.get('[data-testid="btn-iam-search-retry"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid="btn-iam-subject-group-2"]').exists()).toBe(true)
      wrapper.unmount()
    } finally {
      vi.useRealTimers()
    }
  })
})
