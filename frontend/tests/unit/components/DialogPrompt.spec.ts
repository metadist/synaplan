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
})
