import { ref, type Ref } from 'vue'

const SHOW_JUMP_DISTANCE = 120
const HIDE_JUMP_DISTANCE = 48

export type JumpScroll = 'continue' | 'consume' | 'arrived'

export function useJumpToBottom(chatContainer: Ref<HTMLElement | null>) {
  const showJumpToBottom = ref(false)
  let jumpingToBottom = false
  let jumpAnchor = 0

  const updateJumpToBottom = (scrollTop: number, scrollHeight: number, clientHeight: number) => {
    if (jumpingToBottom) return
    const distance = scrollHeight - clientHeight - scrollTop
    if (distance >= SHOW_JUMP_DISTANCE) {
      showJumpToBottom.value = true
    } else if (distance <= HIDE_JUMP_DISTANCE) {
      showJumpToBottom.value = false
    }
  }

  const jumpToBottom = (): boolean => {
    const el = chatContainer.value
    if (!el) return false
    jumpingToBottom = true
    jumpAnchor = el.scrollTop
    showJumpToBottom.value = false
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    el.scrollTo({
      top: el.scrollHeight,
      behavior: reduceMotion ? 'auto' : 'smooth',
    })
    return true
  }

  const noteScroll = (
    scrollTop: number,
    scrollHeight: number,
    clientHeight: number
  ): JumpScroll => {
    updateJumpToBottom(scrollTop, scrollHeight, clientHeight)
    if (!jumpingToBottom) return 'continue'
    if (scrollTop < jumpAnchor - 8) {
      jumpingToBottom = false
      updateJumpToBottom(scrollTop, scrollHeight, clientHeight)
      return 'continue'
    }
    jumpAnchor = scrollTop
    const distance = scrollHeight - clientHeight - scrollTop
    if (distance <= HIDE_JUMP_DISTANCE) {
      jumpingToBottom = false
      return 'arrived'
    }
    return 'consume'
  }

  return { showJumpToBottom, jumpToBottom, noteScroll }
}
