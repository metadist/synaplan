import { ref, watch, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotification } from './useNotification'
import { useDialog } from './useDialog'
import { useI18n } from 'vue-i18n'

export function useUnsavedChanges<T>(
  formData: Ref<T>,
  originalData: Ref<T>
) {
  const router = useRouter()
  const { success } = useNotification()
  const dialog = useDialog()
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
  const confirmNavigation = async () => {
    if (hasUnsavedChanges.value) {
      return await dialog.confirm({
        title: t('unsavedChanges.title'),
        message: t('unsavedChanges.confirmLeave'),
        confirmText: t('common.leave'),
        cancelText: t('common.stay'),
        danger: true
      })
    }
    return true
  }

  // Setup navigation guard
  const setupNavigationGuard = () => {
    // Browser navigation (back/forward/close) - must use window.confirm
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (hasUnsavedChanges.value) {
        e.preventDefault()
        e.returnValue = ''
      }
    }
    window.addEventListener('beforeunload', handleBeforeUnload)

    // Vue Router navigation - can use async dialog
    const removeGuard = router.beforeEach(async (_to, _from, next) => {
      if (hasUnsavedChanges.value) {
        const confirmed = await confirmNavigation()
        next(confirmed)
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

