import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { helpContent } from '@/data/helpContent'
import { useConfigStore } from '@/stores/config'

export function useHelp() {
  const route = useRoute()
  const config = useConfigStore()
  const isOpen = ref(false)
  const isEnabled = computed(() => config.features.help)

  const currentHelpId = computed(() => route.meta.helpId as string | undefined)
  const currentHelp = computed(() => {
    if (!currentHelpId.value) return null
    return helpContent.find((section) => section.id === currentHelpId.value) || null
  })

  const openHelp = () => {
    if (isEnabled.value && currentHelp.value) {
      isOpen.value = true
    }
  }

  const closeHelp = () => {
    isOpen.value = false
  }

  return {
    isEnabled,
    isOpen,
    currentHelpId,
    currentHelp,
    openHelp,
    closeHelp,
  }
}
