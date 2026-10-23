import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FolderFetchCard from '@/components/multitask/FolderFetchCard.vue'
import TaskCard from '@/components/multitask/TaskCard.vue'
import { createPinia, setActivePinia } from 'pinia'
import type { FolderRead } from '@/utils/folderRead'

function read(overrides: Partial<FolderRead> = {}): FolderRead {
  return {
    outcome: 'read',
    app: 'nextcloud',
    label: 'Nextcloud',
    host: 'files.example.org',
    folder: 'Invoices/2026',
    read: 12,
    total: 12,
    files: Array.from({ length: 12 }, (_, i) => `inv-${String(i + 1).padStart(4, '0')}.pdf`),
    skipped: [],
    stopped: false,
    openUrl: 'https://files.example.org/index.php/apps/files/?dir=%2FInvoices%2F2026',
    ...overrides,
  }
}

describe('FolderFetchCard', () => {
  it('names the cloud, the folder and the first files of a read', () => {
    const wrapper = mount(FolderFetchCard, { props: { read: read() } })

    expect(wrapper.get('[data-testid="folder-read-headline"]').text()).toBe(
      'Read 12 files from Nextcloud › Invoices/2026'
    )
    const chips = wrapper.get('[data-testid="folder-read-files"]').findAll('li')
    expect(chips.map((chip) => chip.text())).toEqual([
      'inv-0001.pdf',
      'inv-0002.pdf',
      'inv-0003.pdf',
      '+9',
    ])
  })

  it('shows every name once expanded', async () => {
    const wrapper = mount(FolderFetchCard, { props: { read: read() } })

    await wrapper.get('[data-testid="folder-read-toggle"]').trigger('click')

    expect(wrapper.get('[data-testid="folder-read-files"]').findAll('li')).toHaveLength(12)
    expect(wrapper.get('[data-testid="folder-read-toggle"]').text()).toBe('Show fewer')
  })

  it('says what a partial read left out and why', () => {
    const wrapper = mount(FolderFetchCard, {
      props: {
        read: read({
          outcome: 'partial',
          read: 2,
          total: 4,
          files: ['a.pdf', 'b.pdf'],
          skipped: [
            { name: 'scan.tiff', reason: 'too_large' },
            { name: 'old.tiff', reason: 'too_large' },
          ],
        }),
        full: true,
      },
    })

    expect(wrapper.text()).toContain('2 files were larger than 100 MB and were left out.')
    expect(wrapper.get('[data-testid="folder-read-skipped"]').text()).toContain(
      'scan.tiff — larger than 100 MB'
    )
    expect(wrapper.find('[data-testid="folder-read-toggle"]').exists()).toBe(false)
  })

  it('refuses with the way out and a link into the cloud', () => {
    const wrapper = mount(FolderFetchCard, {
      props: { read: read({ outcome: 'refused', folder: 'HR/Contracts', files: [], read: 0 }) },
    })

    expect(wrapper.get('[data-testid="folder-read-headline"]').text()).toBe(
      'Synaplan may not read HR/Contracts in your Nextcloud. Nothing was read.'
    )
    expect(wrapper.get('[data-testid="folder-read-hint"]').text()).toBe(
      'Open the folder in Nextcloud and choose Let Synaplan read this folder.'
    )
    const link = wrapper.get('[data-testid="folder-read-open"]')
    expect(link.text()).toBe('Open in Nextcloud')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
  })

  it('names the host it could not reach', () => {
    const wrapper = mount(FolderFetchCard, {
      props: { read: read({ outcome: 'unreachable', files: [], read: 0, openUrl: null }) },
    })

    expect(wrapper.get('[data-testid="folder-read-headline"]').text()).toBe(
      'Synaplan could not reach files.example.org. Nothing was read.'
    )
    expect(wrapper.find('[data-testid="folder-read-open"]').exists()).toBe(false)
  })

  it('does not call too-large files "no match"', () => {
    const wrapper = mount(FolderFetchCard, {
      props: {
        read: read({
          outcome: 'nothing',
          read: 0,
          files: [],
          skipped: [{ name: 'scan.tiff', reason: 'too_large' }],
        }),
      },
    })

    expect(wrapper.get('[data-testid="folder-read-headline"]').text()).toBe(
      'Nothing was read from Nextcloud › Invoices/2026.'
    )
    expect(wrapper.text()).toContain('1 file was larger than 100 MB and was left out.')
  })

  it('counts while the cloud sends files', () => {
    const wrapper = mount(FolderFetchCard, {
      props: { read: read({ outcome: 'reading', read: 12, total: 52, files: [] }) },
    })

    expect(wrapper.get('[data-testid="folder-read-headline"]').text()).toBe(
      'Reading files from Nextcloud…'
    )
    expect(wrapper.get('[data-testid="folder-read-progress"]').text()).toBe('12 of 52')
  })

  it('reads in German', async () => {
    const wrapper = mount(FolderFetchCard, {
      props: { read: read({ outcome: 'refused', folder: 'HR/Contracts' }) },
    })
    const i18n = (wrapper.vm as unknown as { $i18n: { locale: string } }).$i18n
    i18n.locale = 'de'
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="folder-read-headline"]').text()).toBe(
      'Synaplan darf HR/Contracts in deinem Nextcloud nicht lesen. Es wurde nichts gelesen.'
    )
    i18n.locale = 'en'
  })
})

describe('TaskCard with a folder read', () => {
  it('renders the folder summary instead of the first file as media', () => {
    setActivePinia(createPinia())
    const wrapper = mount(TaskCard, {
      props: {
        card: {
          nodeId: 'n1',
          capability: 'folder_fetch',
          kind: 'search',
          state: 'done',
          url: '/api/v1/files/uploads/07/inv-0001.pdf',
          folderRead: read(),
        },
      },
      global: { stubs: { MessageText: true, TaskCardMedia: true } },
    })

    expect(wrapper.find('[data-testid="folder-read-card"]').exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'TaskCardMedia' }).exists()).toBe(false)
    expect(wrapper.text()).toContain('Reading files')
  })
})
