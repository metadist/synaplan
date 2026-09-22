import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { mount, type VueWrapper } from '@vue/test-utils'
import Dialog from '@/components/Dialog.vue'
import { useDialog } from '@/composables/useDialog'

describe('Dialog prompt', () => {
  let wrapper: VueWrapper | null = null

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>'
    wrapper = mount(Dialog, { attachTo: document.getElementById('app') as HTMLElement })
  })

  function click(testid: string): void {
    const el = document.querySelector(`[data-testid="${testid}"]`) as HTMLButtonElement | null
    if (!el) {
      throw new Error(`missing ${testid}`)
    }
    el.click()
  }

  it('resolves OK with an empty note as empty string, not cancel', async () => {
    const pending = useDialog().prompt({ title: 'Reject', message: 'Why?' })
    await nextTick()
    click('btn-dialog-confirm')
    await expect(pending).resolves.toBe('')
    wrapper?.unmount()
  })

  it('resolves Cancel as null', async () => {
    const pending = useDialog().prompt({ title: 'Reject', message: 'Why?' })
    await nextTick()
    click('btn-dialog-cancel')
    await expect(pending).resolves.toBeNull()
    wrapper?.unmount()
  })

  it('confirms with Enter once and does not re-activate the opener', async () => {
    const opener = document.createElement('button')
    opener.type = 'button'
    let clicks = 0
    opener.addEventListener('click', () => {
      clicks += 1
    })
    document.getElementById('app')?.appendChild(opener)
    opener.focus()

    const pending = useDialog().prompt({ title: 'Rename group', message: 'Name' })
    await nextTick()
    await nextTick()

    const input = document.querySelector(
      '[data-testid="input-dialog-prompt"]'
    ) as HTMLInputElement | null
    if (!input) {
      throw new Error('missing prompt input')
    }
    expect(document.activeElement).toBe(input)
    input.value = 'Renamed'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    )

    expect(document.activeElement).toBe(input)
    await expect(pending).resolves.toBe('Renamed')
    expect(clicks).toBe(0)
    expect(document.querySelector('[data-testid="modal-dialog"]')).toBeNull()

    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(document.activeElement).toBe(opener)
    wrapper?.unmount()
  })
})
