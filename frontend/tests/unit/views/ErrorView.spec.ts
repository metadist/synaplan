import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ErrorView from '@/views/ErrorView.vue'
import { useAuthStore } from '@/stores/auth'

const mountOptions = (router: ReturnType<typeof createRouter>) => ({
  global: {
    plugins: [router],
    stubs: {
      ExclamationTriangleIcon: true,
      HomeIcon: true,
      ArrowPathIcon: true,
      CodeBracketIcon: true,
      ChevronRightIcon: true,
      ChatBubbleLeftRightIcon: true,
      ClipboardDocumentIcon: true,
    },
  },
})

const buildRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/dummy', name: 'dummy', component: { template: '<div />' } },
    ],
  })

describe('ErrorView', () => {
  let router: ReturnType<typeof buildRouter>
  const originalTitle = document.title

  beforeEach(async () => {
    setActivePinia(createPinia())
    router = buildRouter()
    await router.push('/dummy')
    await router.isReady()
  })

  afterEach(() => {
    document.title = originalTitle
    vi.restoreAllMocks()
  })

  it('renders the generic error copy without leaking details to non-admin users', () => {
    const wrapper = mount(ErrorView, {
      ...mountOptions(router),
      props: {
        error: {
          message: 'database exploded',
          statusCode: 500,
          stack: 'Error: database exploded\n  at db.ts:42',
          source: 'fetch:/api/v1/foo',
          reason: 'unknown',
        },
      },
    })

    // Generic UX is always present
    expect(wrapper.find('[data-testid="page-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-retry"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-home"]').exists()).toBe(true)

    // Sensitive details panel must NOT render for end users
    expect(wrapper.find('[data-testid="section-error-details"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="text-error-message"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="text-error-stack"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="badge-admin-only"]').exists()).toBe(false)

    // The raw message must not appear anywhere in the rendered HTML
    expect(wrapper.html()).not.toContain('database exploded')
    expect(wrapper.html()).not.toContain('db.ts:42')
  })

  it('renders the full diagnostic payload for admin users', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
      isAdmin: true,
    }

    const wrapper = mount(ErrorView, {
      ...mountOptions(router),
      props: {
        error: {
          message: 'database exploded',
          statusCode: 500,
          stack: 'Error: database exploded\n  at db.ts:42',
          source: 'fetch:/api/v1/foo',
          reason: 'unknown',
        },
      },
    })

    expect(wrapper.find('[data-testid="section-error-details"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="badge-admin-only"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="text-error-message"]').text()).toBe('database exploded')
    expect(wrapper.find('[data-testid="text-error-status"]').text()).toContain('500')
    expect(wrapper.find('[data-testid="text-error-source"]').text()).toContain('fetch:/api/v1/foo')

    // Stack starts collapsed and only renders after the toggle
    expect(wrapper.find('[data-testid="text-error-stack"]').exists()).toBe(false)
    await wrapper.find('[data-testid="btn-toggle-stack"]').trigger('click')
    expect(wrapper.find('[data-testid="text-error-stack"]').text()).toContain('db.ts:42')
  })

  it('renders the full diagnostic payload while an admin is impersonating a non-admin', () => {
    // The CURRENT user is the impersonated non-admin — `isAdmin` is therefore
    // false. But the operator (`impersonator`) is an admin, so the debug panel
    // must still render. This is the whole point of the impersonation feature
    // for the spec ("Fehler dem Admin auch beim Impersonate komplett zeigen").
    const authStore = useAuthStore()
    authStore.user = {
      id: 99,
      email: 'normal-user@example.com',
      level: 'PRO',
      isAdmin: false,
    }
    authStore.impersonator = {
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
    }

    const wrapper = mount(ErrorView, {
      ...mountOptions(router),
      props: {
        error: {
          message: 'database exploded',
          statusCode: 500,
          source: 'fetch:/api/v1/foo',
          reason: 'unknown',
        },
      },
    })

    expect(wrapper.find('[data-testid="section-error-details"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="badge-admin-only"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="text-error-message"]').text()).toBe('database exploded')
    expect(wrapper.find('[data-testid="text-error-status"]').text()).toContain('500')
  })

  it('uses the friendly redirect-loop copy when reason="redirect_loop"', () => {
    const wrapper = mount(ErrorView, {
      ...mountOptions(router),
      props: {
        error: { reason: 'redirect_loop' },
      },
    })

    // The reason-specific heading replaces the generic title for everyone
    expect(wrapper.find('[data-testid="page-error"] h1').text()).toContain('Redirect Loop')
  })

  it('invokes onRetry / onGoHome callbacks instead of navigating away when provided', async () => {
    const onRetry = vi.fn()
    const onGoHome = vi.fn()

    const wrapper = mount(ErrorView, {
      ...mountOptions(router),
      props: { onRetry, onGoHome },
    })

    await wrapper.find('[data-testid="btn-retry"]').trigger('click')
    await wrapper.find('[data-testid="btn-home"]').trigger('click')

    expect(onRetry).toHaveBeenCalledTimes(1)
    expect(onGoHome).toHaveBeenCalledTimes(1)
  })

  it('mirrors the document title on mount and restores it on unmount', () => {
    document.title = 'Original Title'
    const wrapper = mount(ErrorView, mountOptions(router))

    expect(document.title).toContain('Error')
    expect(document.title).toContain('Synaplan')

    wrapper.unmount()
    expect(document.title).toBe('Original Title')
  })
})
