import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  FIRST_RUN_ADMIN_EMAIL,
  FIRST_RUN_ADMIN_PASSWORD,
  FIRST_RUN_SETUP_PATH,
} from '@/constants/firstRunAdmin'

/**
 * Sign in as the default administrator (if needed) and open AI provider setup.
 *
 * Already an admin → just go there. Guest / non-admin → log in with the
 * documented first-run credentials, then redirect. If that login fails
 * (password already changed), fall through to the normal login page with
 * the email and redirect prefilled.
 */
export function useFirstRunSetup() {
  const router = useRouter()
  const authStore = useAuthStore()

  async function goToProviderSetup(): Promise<void> {
    if (authStore.isAdmin) {
      await router.push(FIRST_RUN_SETUP_PATH)
      return
    }

    // Do not overwrite an already-signed-in non-admin with the seeded admin.
    if (!authStore.isAuthenticated) {
      const ok = await authStore.login(FIRST_RUN_ADMIN_EMAIL, FIRST_RUN_ADMIN_PASSWORD)
      if (ok && authStore.isAdmin) {
        await router.push(FIRST_RUN_SETUP_PATH)
        return
      }
    }

    await router.push({
      path: '/login',
      query: { redirect: FIRST_RUN_SETUP_PATH, email: FIRST_RUN_ADMIN_EMAIL },
    })
  }

  return { goToProviderSetup }
}
