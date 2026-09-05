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
      iam: {
        share: 'Share',
        everyone: 'Everyone in this organization',
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

const mountDialog = (isOpen: boolean) =>
  mount(ShareDialog, {
    props: {
      isOpen,
      kind: 'conversation',
      resourceId: '1',
      resourceName: 'Chat',
    },
    global: {
      plugins: [i18n],
      stubs: {
        Teleport: true,
        Transition: false,
        SubjectPicker: true,
        PermissionSelect: true,
      },
    },
  })

describe('ShareDialog', () => {
  it('does not render when closed', () => {
    const wrapper = mountDialog(false)

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(false)
  })

  it('renders when open', () => {
    const wrapper = mountDialog(true)

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(true)
  })
})
