import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DavConnectionForm from '@/components/config/DavConnectionForm.vue'

const { mockCreate, mockTest, mockSuccess, mockError } = vi.hoisted(() => ({
  mockCreate: vi.fn(),
  mockTest: vi.fn(),
  mockSuccess: vi.fn(),
  mockError: vi.fn(),
}))

vi.mock('@/services/api/connectionsApi', () => ({
  connectionsApi: { create: mockCreate, test: mockTest },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: mockError }),
}))

const mountForm = () => mount(DavConnectionForm, { global: { stubs: { Icon: true } } })

const fillNextcloudForm = async (wrapper: ReturnType<typeof mountForm>) => {
  await wrapper.find('[data-testid="btn-open-dav-form"]').trigger('click')
  await wrapper.find('[data-testid="dav-server-url"]').setValue('https://cloud.example.com/')
  await wrapper.find('[data-testid="dav-username"]').setValue('ada')
  await wrapper.find('[data-testid="dav-app-password"]').setValue('app-pass')
}

describe('DavConnectionForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockCreate.mockResolvedValue({ id: '7', name: 'x' })
    mockTest.mockResolvedValue({ succeeded: true, error: null, account: 'ada@cloud.example.com' })
  })

  it('creates a webdav and a caldav connection from the Nextcloud preset', async () => {
    const wrapper = mountForm()
    await fillNextcloudForm(wrapper)

    await wrapper.find('[data-testid="dav-form"]').trigger('submit')
    await flushPromises()

    expect(mockCreate).toHaveBeenCalledTimes(2)
    expect(mockCreate.mock.calls[0][0]).toMatchObject({
      type: 'webdav',
      secret: 'app-pass',
      config: {
        base_url: 'https://cloud.example.com/remote.php/dav/files/ada',
        username: 'ada',
        folder: 'Synaplan',
        on_conflict: 'rename',
        channel: 'nextcloud',
      },
    })
    expect(mockCreate.mock.calls[1][0]).toMatchObject({
      type: 'caldav',
      config: {
        base_url: 'https://cloud.example.com/remote.php/dav/calendars/ada/personal',
        username: 'ada',
        channel: 'calendar',
      },
    })
    // Every new connection is proven with a live test right away.
    expect(mockTest).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('created')).toHaveLength(1)
  })

  it('skips the calendar when the user opts out', async () => {
    const wrapper = mountForm()
    await fillNextcloudForm(wrapper)
    await wrapper.find('[data-testid="dav-with-calendar"]').setValue(false)

    await wrapper.find('[data-testid="dav-form"]').trigger('submit')
    await flushPromises()

    expect(mockCreate).toHaveBeenCalledTimes(1)
    expect(mockCreate.mock.calls[0][0].type).toBe('webdav')
  })

  it('accepts a local http address in development', async () => {
    const wrapper = mountForm()
    await fillNextcloudForm(wrapper)
    await wrapper.find('[data-testid="dav-server-url"]').setValue('http://nextcloud')
    await wrapper.find('[data-testid="dav-with-calendar"]').setValue(false)

    expect(wrapper.find('[data-testid="btn-dav-submit"]').attributes('disabled')).toBeUndefined()

    await wrapper.find('[data-testid="dav-form"]').trigger('submit')
    await flushPromises()

    expect(mockCreate).toHaveBeenCalledTimes(1)
    expect(mockCreate.mock.calls[0][0].config.base_url).toBe(
      'http://nextcloud/remote.php/dav/files/ada'
    )
  })

  it('surfaces the tester error when the live check fails', async () => {
    mockTest.mockResolvedValue({
      succeeded: false,
      error: 'PROPFIND answered HTTP 401',
      account: null,
    })

    const wrapper = mountForm()
    await fillNextcloudForm(wrapper)
    await wrapper.find('[data-testid="dav-with-calendar"]').setValue(false)

    await wrapper.find('[data-testid="dav-form"]').trigger('submit')
    await flushPromises()

    expect(mockError).toHaveBeenCalledWith('PROPFIND answered HTTP 401')
  })
})
