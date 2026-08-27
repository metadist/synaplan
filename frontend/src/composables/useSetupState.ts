import { ref } from 'vue'
import { getSetupState, type SetupState } from '@/services/api/setupApi'
import { getErrorMessage } from '@/utils/errorMessage'

/**
 * The installation's first-run state, as the setup wizard needs it.
 *
 * Deliberately NOT the runtime config: `/api/v1/setup/state` also reports which
 * access switches an environment variable has pinned, and whether a mailer is
 * configured at all. The wizard needs both to avoid offering a choice the
 * deployment has already made.
 */
export function useSetupState() {
  const state = ref<SetupState | null>(null)
  const loading = ref(false)
  const error = ref('')

  async function load(): Promise<void> {
    loading.value = true
    error.value = ''
    try {
      state.value = await getSetupState()
    } catch (err) {
      error.value = getErrorMessage(err) ?? ''
    } finally {
      loading.value = false
    }
  }

  return { state, loading, error, load }
}
