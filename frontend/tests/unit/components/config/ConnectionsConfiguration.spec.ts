import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ConnectionsConfiguration from '@/components/config/ConnectionsConfiguration.vue'
import type { ConnectionItem } from '@/services/api/connectionsApi'

const {
  mockList,
  mockTest,
  mockUpdate,
  mockRemove,
  mockStatus,
  mockAuthorizeUrl,
  mockSuccess,
  mockError,
  mockConfirm,
  mockReplace,
  isAdmin,
  route,
} = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockTest: vi.fn(),
  mockUpdate: vi.fn(),
  mockRemove: vi.fn(),
  mockStatus: vi.fn(),
  mockAuthorizeUrl: vi.fn(),
  mockSuccess: vi.fn(),
  mockError: vi.fn(),
  mockConfirm: vi.fn(),
  mockReplace: vi.fn(),
  isAdmin: { value: false },
  route: { query: {} as Record<string, string> },
}))

vi.mock('@/services/api/connectionsApi', () => ({
  connectionsApi: { list: mockList, test: mockTest, update: mockUpdate, remove: mockRemove },
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: mockConfirm }),
}))

vi.mock('@/services/api/m365Api', () => ({
  m365Api: { status: mockStatus, authorizeUrl: mockAuthorizeUrl },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: mockError }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ push: vi.fn(), replace: mockReplace }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAdmin: isAdmin.value }),
}))

function connection(overrides: Partial<ConnectionItem> = {}): ConnectionItem {
  return {
    id: '4',
    source: 'registry',
    type: 'm365',
    name: 'ada@contoso.com',
    status: 'connected',
    last_checked: null,
    has_secret: true,
    ...overrides,
  }
}

const mountPage = async () => {
  const wrapper = mount(ConnectionsConfiguration, {
    global: {
      stubs: {
        Icon: true,
        // Keeps `to` inspectable: the admin hint is only useful if it lands on
        // the Microsoft 365 section, not on the default settings tab.
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('ConnectionsConfiguration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockList.mockResolvedValue([])
    mockStatus.mockResolvedValue({ available: false, redirectUri: '' })
    isAdmin.value = false
    route.query = {}
    Object.defineProperty(window, 'location', { value: { href: '' }, writable: true })
  })

  it('offers the three ways to connect something, even with an empty list', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()
    expect(wrapper.get('[data-testid="connections-empty"]').text()).toContain('Nothing connected')
    expect(text).toContain('Microsoft 365')
    expect(text).toContain('Mailbox (IMAP)')
    expect(text).toContain('Connected system (MCP)')
  })

  it('explains why Microsoft 365 cannot be connected yet, and points admins at the setting', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="btn-connect-m365"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="provider-m365"]').text()).toContain('Not available yet')
    expect(wrapper.get('[data-testid="provider-m365"]').text()).toContain(
      'Ask an administrator to enable it.'
    )

    isAdmin.value = true
    const adminWrapper = await mountPage()
    const m365Card = adminWrapper.get('[data-testid="provider-m365"]')
    expect(m365Card.text()).toContain('Set it up in system settings.')
    expect(JSON.parse(m365Card.get('a').attributes('data-to') ?? '{}')).toEqual({
      path: '/admin/config',
      query: { tab: 'channels', section: 'm365' },
    })
  })

  it('sends the user to Microsoft when the connector is configured', async () => {
    mockStatus.mockResolvedValue({ available: true, redirectUri: 'https://app/callback' })
    mockAuthorizeUrl.mockResolvedValue('https://login.microsoftonline.com/authorize')

    const wrapper = await mountPage()
    await wrapper.get('[data-testid="btn-connect-m365"]').trigger('click')
    await flushPromises()

    expect(window.location.href).toBe('https://login.microsoftonline.com/authorize')
  })

  it('reports the tester’s own reason instead of a generic failure', async () => {
    mockList.mockResolvedValue([connection()])
    mockTest.mockResolvedValue({
      connection: connection({ status: 'reauth_required' }),
      succeeded: false,
      error: 'Microsoft rejected the saved sign-in.',
      account: null,
    })

    const wrapper = await mountPage()
    await wrapper.get('[data-testid="btn-test-connection"]').trigger('click')
    await flushPromises()

    expect(mockError).toHaveBeenCalledWith('Microsoft rejected the saved sign-in.')
    expect(wrapper.text()).toContain('Reconnect')
  })

  it('names the signed-in account when a test succeeds', async () => {
    mockList.mockResolvedValue([connection()])
    mockTest.mockResolvedValue({
      connection: connection(),
      succeeded: true,
      error: null,
      account: 'ada@contoso.com',
    })

    const wrapper = await mountPage()
    await wrapper.get('[data-testid="btn-test-connection"]').trigger('click')
    await flushPromises()

    expect(mockSuccess).toHaveBeenCalledWith(
      expect.stringContaining('signed in as ada@contoso.com')
    )
  })

  it('lets the user edit and remove a registry connection', async () => {
    const dav = connection({
      id: '1',
      type: 'webdav',
      name: 'nextcloud folder',
      config: {
        base_url: 'http://nextcloud/remote.php/dav/files/admin',
        username: 'admin',
        folder: 'Synaplan',
      },
    })
    mockList.mockResolvedValue([dav])
    mockConfirm.mockResolvedValue(true)
    mockRemove.mockResolvedValue(undefined)

    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="btn-edit-connection"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-delete-connection"]').exists()).toBe(true)

    await wrapper.get('[data-testid="btn-delete-connection"]').trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalled()
    expect(mockRemove).toHaveBeenCalledWith('1')
    expect(wrapper.find('[data-testid="connection-row"]').exists()).toBe(false)
  })

  it('saves a renamed Nextcloud folder without requiring a new app password', async () => {
    const dav = connection({
      id: '1',
      type: 'webdav',
      name: 'nextcloud folder',
      config: {
        base_url: 'http://nextcloud/remote.php/dav/files/admin',
        username: 'admin',
        folder: 'Synaplan',
      },
    })
    mockList.mockResolvedValue([dav])
    mockUpdate.mockResolvedValue({ ...dav, name: 'Work files' })
    mockTest.mockResolvedValue({
      connection: { ...dav, name: 'Work files', status: 'connected' },
      succeeded: true,
      error: null,
      account: 'admin',
    })

    const wrapper = await mountPage()
    await wrapper.get('[data-testid="btn-edit-connection"]').trigger('click')
    await wrapper.get('[data-testid="connection-edit-name"]').setValue('Work files')
    await wrapper.get('[data-testid="connection-edit-form"]').trigger('submit')
    await flushPromises()

    expect(mockUpdate).toHaveBeenCalledWith('1', {
      name: 'Work files',
      config: {
        base_url: 'http://nextcloud/remote.php/dav/files/admin',
        username: 'admin',
        folder: 'Synaplan',
      },
    })
    expect(mockTest).toHaveBeenCalledWith('1')
  })

  it('turns the callback result into a message and clears it from the URL', async () => {
    route.query = { m365: 'error', reason: 'access_denied' }
    await mountPage()

    expect(mockError).toHaveBeenCalledWith(expect.stringContaining('cancelled'))
    expect(mockReplace).toHaveBeenCalledWith({ query: {} })
  })
})
