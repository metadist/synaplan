import { ref } from 'vue'

const STORAGE_KEY = 'synaplan-skip-external-link-warning'

/**
 * Composable for opening external links with an optional warning dialog.
 * If the user has checked "don't ask again", links open directly.
 */
export function useExternalLink() {
  const pendingUrl = ref('')
  const warningOpen = ref(false)

  function openExternalLink(url: string) {
    if (localStorage.getItem(STORAGE_KEY) === 'true') {
      window.open(url, '_blank', 'noopener,noreferrer')
      return
    }
    pendingUrl.value = url
    warningOpen.value = true
  }

  function closeWarning() {
    warningOpen.value = false
    pendingUrl.value = ''
  }

  return {
    pendingUrl,
    warningOpen,
    openExternalLink,
    closeWarning,
  }
}
