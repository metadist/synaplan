import { beforeEach, describe, expect, it, vi } from 'vitest'

const prompt = vi.fn()
const push = vi.fn()
const error = vi.fn()
const status = vi.fn()
const pushFile = vi.fn()

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ prompt }) }))
vi.mock('@/composables/useNotification', () => ({ useNotification: () => ({ push, error }) }))
vi.mock('@/services/api/notionAppApi', () => ({
  notionAppApi: { status: () => status(), pushFile: (...args: unknown[]) => pushFile(...args) },
}))

const { useNotionFilePush } = await import('@/composables/useNotionFilePush')

describe('useNotionFilePush', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('is offered only while the own Notion is connected', async () => {
    const notion = useNotionFilePush()
    status.mockResolvedValueOnce({ status: 'connected' })
    await notion.load()
    expect(notion.available.value).toBe(true)

    status.mockResolvedValueOnce({ status: 'reauth_required' })
    await notion.load()
    expect(notion.available.value).toBe(false)

    status.mockRejectedValueOnce(new Error('offline'))
    await notion.load()
    expect(notion.available.value).toBe(false)
  })

  it('adds the file and offers to open the page', async () => {
    prompt.mockResolvedValue(' https://www.notion.so/Reports-1 ')
    pushFile.mockResolvedValue({
      success: true,
      page_url: 'https://www.notion.so/Reports-1',
      page_title: 'Reports',
      file_name: 'q3.pdf',
    })
    const notion = useNotionFilePush()

    await notion.pushFile(7, 'q3.pdf')

    expect(pushFile).toHaveBeenCalledWith(7, 'https://www.notion.so/Reports-1')
    expect(push).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'success', action: expect.any(Object) })
    )
    expect(notion.busyId.value).toBeNull()
  })

  it('does nothing when the dialog is cancelled', async () => {
    prompt.mockResolvedValue(null)
    await useNotionFilePush().pushFile(7, 'q3.pdf')
    expect(pushFile).not.toHaveBeenCalled()
  })

  it('shows the honest server reason when Notion refuses', async () => {
    prompt.mockResolvedValue('https://www.notion.so/Reports-1')
    pushFile.mockRejectedValue(
      new Error('Notion did not accept the file. Nothing was added to Notion.')
    )

    await useNotionFilePush().pushFile(7, 'q3.pdf')

    expect(error).toHaveBeenCalledWith(
      'Notion did not accept the file. Nothing was added to Notion.'
    )
  })
})
