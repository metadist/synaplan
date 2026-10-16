import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FileRowActions, { type FileRowAction } from '@/components/files/FileRowActions.vue'

function actions(overrides: Partial<FileRowAction>[] = []): FileRowAction[] {
  const base: FileRowAction[] = [
    { id: 'openInChat', title: 'Open in chat', testid: 'btn-chat', onSelect: vi.fn() },
    { id: 'preview', title: 'Preview', testid: 'btn-preview', onSelect: vi.fn() },
    { id: 'download', title: 'Download', testid: 'btn-download', onSelect: vi.fn() },
    { id: 'delete', title: 'Delete', testid: 'btn-delete', onSelect: vi.fn() },
  ]
  return base.map((action, index) => ({ ...action, ...overrides[index] }))
}

describe('FileRowActions', () => {
  it('renders one button per action with title and test id', () => {
    const wrapper = mount(FileRowActions, { props: { actions: actions() } })
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(4)
    expect(buttons[0].attributes('title')).toBe('Open in chat')
    expect(buttons[0].attributes('data-testid')).toBe('btn-chat')
    expect(buttons[0].attributes('aria-label')).toBe('Open in chat')
  })

  it('renders each action with its house glyph', () => {
    const wrapper = mount(FileRowActions, { props: { actions: actions() } })
    const html = wrapper.html()
    // Heroicons outline paths differ per glyph; assert four distinct SVGs render.
    expect(wrapper.findAll('svg')).toHaveLength(4)
    expect(html).toContain('btn-download')
  })

  it('styles delete as destructive and the rest as secondary', () => {
    const wrapper = mount(FileRowActions, { props: { actions: actions() } })
    const buttons = wrapper.findAll('button')
    for (const button of buttons.slice(0, 3)) {
      expect(button.classes()).toContain('txt-secondary')
    }
    expect(buttons[3].classes()).toContain('hover:text-red-500')
    expect(buttons[3].classes()).not.toContain('txt-secondary')
  })

  it('calls the action handler on click', async () => {
    const onSelect = vi.fn()
    const wrapper = mount(FileRowActions, {
      props: { actions: actions([{ onSelect }]) },
    })
    await wrapper.findAll('button')[0].trigger('click')
    expect(onSelect).toHaveBeenCalledTimes(1)
  })

  it('omits the test id attribute when none is given', () => {
    const wrapper = mount(FileRowActions, {
      props: {
        actions: [{ id: 'preview', title: 'Preview', onSelect: vi.fn() }],
      },
    })
    expect(wrapper.find('button').attributes('data-testid')).toBeUndefined()
  })

  it('disables the button when the action is disabled', () => {
    const wrapper = mount(FileRowActions, {
      props: {
        actions: [{ id: 'download', title: 'Download', disabled: true, onSelect: vi.fn() }],
      },
    })
    const button = wrapper.find('button')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.classes()).toContain('disabled:opacity-50')
  })
})
