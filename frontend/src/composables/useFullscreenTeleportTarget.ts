import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * In real browser fullscreen mode, only the fullscreen element and its descendants are rendered.
 * Any modal teleported to <body> becomes invisible. This composable provides a reactive teleport
 * target that switches to document.fullscreenElement when present.
 */
export function useFullscreenTeleportTarget() {
  const teleportTarget = ref<HTMLElement | string>('body')

  const update = () => {
    teleportTarget.value = (document.fullscreenElement as HTMLElement | null) ?? 'body'
  }

  onMounted(() => {
    update()
    document.addEventListener('fullscreenchange', update)
  })

  onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', update)
  })

  return { teleportTarget }
}
