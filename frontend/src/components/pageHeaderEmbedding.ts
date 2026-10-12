import { inject, provide, type InjectionKey } from 'vue'

/**
 * A page component rendered inside another page (an app panel inside the app
 * detail page) keeps its PageHeader as a section heading: the host page owns
 * the single h1, icon and explanation.
 */
const EMBEDDED_PAGE_HEADER: InjectionKey<boolean> = Symbol('embeddedPageHeader')

export function provideEmbeddedPageHeader(): void {
  provide(EMBEDDED_PAGE_HEADER, true)
}

export function useEmbeddedPageHeader(): boolean {
  return inject(EMBEDDED_PAGE_HEADER, false)
}
