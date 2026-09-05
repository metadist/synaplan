import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ShareDialog from '@/components/iam/ShareDialog.vue'

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listShares: vi.fn().mockResolvedValue([]),
    searchSubjects: vi.fn().mockResolvedValue([]),
    grantShare: vi.fn(),
    revokeShare: vi.fn(),
  },
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({
    confirm: vi.fn().mockResolvedValue(false),
  }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      common: { close: 'Close' },
      iam: {
        share: 'Share',
        everyone: 'Everyone',
        permission: { read: 'Can view', use: 'Can use', edit: 'Can edit', manage: 'Can manage' },
        dialog: {
          title: 'Share "{name}"',
          who: 'Who',
          searchPlaceholder: 'Search',
          permission: 'Permission',
          sharedWith: 'Shared with',
          empty: 'Empty',
          cancel: 'Cancel',
          remove: 'Remove',
          removeTitle: 'Stop',
          removeConfirm: 'Remove {name}?',
          publicLink: 'Public link',
          openPublicLink: 'Open public link',
        },
      },
    },
  },
})

describe('ShareDialog more kinds', () => {
  it('opens for an assistant without a public-link section', () => {
    const wrapper = mount(ShareDialog, {
      props: {
        isOpen: true,
        kind: 'assistant',
        resourceId: '12',
        resourceName: 'Sales Helper',
      },
      global: {
        plugins: [i18n],
        stubs: { Teleport: true, Transition: false, SubjectPicker: true, PermissionSelect: true },
      },
    })

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-iam-public-link"]').exists()).toBe(false)
  })
})
