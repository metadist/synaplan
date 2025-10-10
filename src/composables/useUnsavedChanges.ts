import { ref, watch, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotification } from './useNotification'
import { useI18n } from 'vue-i18n'

export function useUnsavedChanges<T>(
  formData: Ref<T>,
  originalData: Ref<T>
) {
  const router = useRouter()
  const { success } = useNotification()
  const { t } = useI18n()
  
  const hasUnsavedChanges = ref(false)

  // Watch for changes in form data
  watch(
    formData,
    (newVal) => {
      hasUnsavedChanges.value = JSON.stringify(newVal) !== JSON.stringify(originalData.value)
    },
    { deep: true }
  )

  // Save changes
  const saveChanges = (saveCallback: () => void | Promise<void>) => {
    return async () => {
      try {
        await saveCallback()
        originalData.value = JSON.parse(JSON.stringify(formData.value))
        
        // Delay before hiding to show success state
        await new Promise(resolve => setTimeout(resolve, 300))
        hasUnsavedChanges.value = false
        
        // Show success notification after bar is hidden
        setTimeout(() => {
          success(t('unsavedChanges.saved'))
        }, 200)
      } catch (error) {
        // If validation fails, keep the bar open
        console.error('Save failed:', error)
      }
    }
  }

  // Discard changes
  const discardChanges = () => {
    formData.value = JSON.parse(JSON.stringify(originalData.value))
    hasUnsavedChanges.value = false
  }

  // Prevent navigation if there are unsaved changes
  const confirmNavigation = () => {
    if (hasUnsavedChanges.value) {
      return window.confirm(t('unsavedChanges.confirmLeave'))
    }
    return true
  }

  // Setup navigation guard
  const setupNavigationGuard = () => {
    // Browser navigation (back/forward/close)
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (hasUnsavedChanges.value) {
        e.preventDefault()
        e.returnValue = ''
      }
    }
    window.addEventListener('beforeunload', handleBeforeUnload)

    // Vue Router navigation
    const removeGuard = router.beforeEach((_to, _from, next) => {
      if (hasUnsavedChanges.value && !confirmNavigation()) {
        next(false)
      } else {
        next()
      }
    })

    // Cleanup
    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload)
      removeGuard()
    }
  }

  return {
    hasUnsavedChanges,
    saveChanges,
    discardChanges,
    setupNavigationGuard
  }
}

