import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type { App } from 'vue'

/**
 * Install the app's single Pinia instance before any await.
 *
 * Hot updates re-run component setup on the already-open page. Pinia throws
 * if that happens while no instance is active, and the fatal error screen
 * then stays up until a manual refresh. Reusing the same instance across a
 * re-evaluation of this module keeps the stores the page already has, and
 * the before-update hook puts that instance back in front of every hot update.
 */
export function installAppPinia(app: App): Pinia {
  const hot = import.meta.hot
  const pinia = (hot?.data.pinia as Pinia | undefined) ?? createPinia()
  setActivePinia(pinia)
  app.use(pinia)
  if (hot) {
    hot.data.pinia = pinia
    hot.on('vite:beforeUpdate', () => {
      setActivePinia(pinia)
    })
  }
  return pinia
}
