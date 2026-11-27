<template>
  <MainLayout>
    <div
      class="flex h-full flex-col overflow-y-auto bg-chat px-3 py-4 sm:p-4 md:p-8 scroll-thin"
      data-testid="page-tools"
    >
      <div class="mx-auto w-full max-w-[100rem]">
        <PageHeader
          :title="title"
          :subtitle="plugin?.description || $t('plugins.loading')"
          icon="heroicons:puzzle-piece"
          data-testid="section-header"
        />

        <div class="surface-card min-h-[600px] p-6 rounded-xl relative">
          <div v-if="loading" class="flex items-center justify-center h-64">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"></div>
          </div>
          <div v-if="error" class="txt-secondary text-center py-12">
            {{ error }}
          </div>
          <div ref="pluginContainer" class="plugin-host">
            <!-- Plugin content will be dynamically injected here -->
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import { useAuthStore } from '@/stores/auth'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { authedFetch } from '@/services/api/authedFetch'
import { createLoadGate, pluginTranslator, readPluginLocale } from '@/services/pluginLocale'
import { pluginLabel } from '@/utils/pluginLabel'

const route = useRoute()
const { t, locale } = useI18n()
const configStore = useConfigStore()
const authStore = useAuthStore()
const pluginContainer = ref<HTMLElement | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const pluginName = computed(() => route.params.pluginName as string)
const loadGate = createLoadGate()

const plugin = computed(() => {
  return configStore.plugins.find((p) => p.name === pluginName.value)
})

const title = computed(
  () => (plugin.value && pluginLabel(plugin.value)) || t('plugins.fallbackTitle')
)

/**
 * Mounts the plugin's page: the `index.js` ES module in its assets, or its
 * `index.html` when the module cannot be loaded.
 */
async function loadPlugin() {
  const generation = loadGate.begin()
  if (!pluginContainer.value) return

  // Ensure runtime config (which lists the user's installed plugins) is
  // available before deciding the plugin is missing — otherwise a slow config
  // load would be misread as "not installed".
  await configStore.init()
  if (!loadGate.isCurrent(generation) || !pluginContainer.value) return

  // Plugin exists in the repo but is not installed for this user: surface a
  // clear message instead of hanging on "Loading plugin…" forever (#1154).
  const activePlugin = configStore.plugins.find((p) => p.name === pluginName.value)
  // Same shape the API requires. A route param outside it must not become a URL segment.
  if (!activePlugin || !authStore.user?.id || !/^[a-z0-9_-]+$/.test(pluginName.value)) {
    loading.value = false
    error.value = t('plugins.notFound')
    return
  }

  loading.value = true
  error.value = null
  pluginContainer.value.innerHTML = ''

  const pluginBaseUrl = `${configStore.apiBaseUrl}/api/v1/user/${authStore.user.id}/plugins/${encodeURIComponent(pluginName.value)}/assets`
  const entryUrl = `${pluginBaseUrl}/index.js?v=${Date.now()}`

  try {
    const module = await import(/* @vite-ignore */ entryUrl)
    if (!loadGate.isCurrent(generation) || !pluginContainer.value) return

    if (module.default && typeof module.default.mount === 'function') {
      const active = String(locale.value).slice(0, 2)
      const english = await readPluginLocale(pluginBaseUrl, 'en')
      if (!loadGate.isCurrent(generation) || !pluginContainer.value) return
      const local = active === 'en' ? english : await readPluginLocale(pluginBaseUrl, active)
      if (!loadGate.isCurrent(generation) || !pluginContainer.value) return
      if (!english && !local) {
        console.warn(
          'Plugin has no locale file; labels stay as the plugin wrote them.',
          pluginName.value
        )
      }
      module.default.mount(pluginContainer.value, {
        userId: authStore.user.id,
        apiBaseUrl: configStore.apiBaseUrl,
        pluginBaseUrl: pluginBaseUrl,
        config: activePlugin,
        locale: active,
        t: pluginTranslator(local, english),
        request: (path: string, init?: RequestInit) =>
          authedFetch(`${configStore.apiBaseUrl}${path}`, init ?? {}),
      })
    } else {
      throw new Error('Plugin does not export a default object with a mount() function')
    }
  } catch (err: unknown) {
    if (!loadGate.isCurrent(generation)) return
    console.error('Failed to load plugin', pluginName.value, err)
    error.value = t('plugins.loadFailed')

    try {
      const htmlUrl = `${pluginBaseUrl}/index.html`
      const response = await fetch(htmlUrl)
      if (response.ok && loadGate.isCurrent(generation) && pluginContainer.value) {
        const html = await response.text()
        if (!loadGate.isCurrent(generation) || !pluginContainer.value) return
        pluginContainer.value.innerHTML = html
        error.value = null
      }
    } catch {
      // The load-failed message above stays.
    }
  } finally {
    if (loadGate.isCurrent(generation)) loading.value = false
  }
}

function unloadPlugin() {
  loadGate.cancel()
  if (pluginContainer.value) {
    pluginContainer.value.innerHTML = ''
  }
}

onMounted(() => {
  loadPlugin()
})

onUnmounted(() => {
  unloadPlugin()
})

// Watch for plugin name changes to switch plugins
watch(pluginName, () => {
  loadPlugin()
})

watch(locale, () => {
  unloadPlugin()
  loadPlugin()
})
</script>

<style scoped>
.plugin-host {
  width: 100%;
  height: 100%;
}
</style>
