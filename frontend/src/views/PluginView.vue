<template>
  <MainLayout>
    <div class="flex flex-col h-full overflow-y-auto bg-chat scroll-thin" data-testid="page-tools">
      <div class="max-w-[1400px] mx-auto w-full px-6 py-8">
        <div class="mb-8" data-testid="section-header">
          <h1 class="text-3xl font-semibold txt-primary mb-2">
            {{
              plugin?.name ? plugin.name.charAt(0).toUpperCase() + plugin.name.slice(1) : 'Plugin'
            }}
          </h1>
          <p class="txt-secondary">
            {{ plugin?.description || 'Loading plugin...' }}
          </p>
        </div>

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
import { getErrorMessage } from '@/utils/errorMessage'
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import { useAuthStore } from '@/stores/auth'
import MainLayout from '@/components/MainLayout.vue'

const route = useRoute()
const { t } = useI18n()
const configStore = useConfigStore()
const authStore = useAuthStore()
const pluginContainer = ref<HTMLElement | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const pluginName = computed(() => route.params.pluginName as string)

const plugin = computed(() => {
  return configStore.plugins.find((p) => p.name === pluginName.value)
})

/**
 * Loads the plugin frontend dynamically.
 * We look for an 'index.js' ES module in the plugin's assets.
 */
async function loadPlugin() {
  if (!pluginContainer.value) return

  // Ensure runtime config (which lists the user's installed plugins) is
  // available before deciding the plugin is missing — otherwise a slow config
  // load would be misread as "not installed".
  await configStore.init()

  // Plugin exists in the repo but is not installed for this user: surface a
  // clear message instead of hanging on "Loading plugin…" forever (#1154).
  const activePlugin = configStore.plugins.find((p) => p.name === pluginName.value)
  if (!activePlugin || !authStore.user?.id) {
    loading.value = false
    error.value = t('plugins.notFound')
    return
  }

  loading.value = true
  error.value = null
  pluginContainer.value.innerHTML = ''

  const pluginBaseUrl = `${configStore.apiBaseUrl}/api/v1/user/${authStore.user.id}/plugins/${pluginName.value}/assets`
  const entryUrl = `${pluginBaseUrl}/index.js?v=${Date.now()}`

  try {
    // 1. Fetch index.html if it exists to support "webpage" style plugins
    // But since the user wants everything in the div without iframe,
    // an ES module entry point is more reliable for dynamic mounting.

    // 2. Import the plugin's ES module
    const module = await import(/* @vite-ignore */ entryUrl)

    if (module.default && typeof module.default.mount === 'function') {
      // 3. Mount the plugin
      module.default.mount(pluginContainer.value, {
        userId: authStore.user.id,
        apiBaseUrl: configStore.apiBaseUrl,
        pluginBaseUrl: pluginBaseUrl,
        config: activePlugin,
        // Pass shared services if needed
      })
    } else {
      throw new Error('Plugin does not export a default object with a mount() function')
    }
  } catch (err: unknown) {
    console.error(`Failed to load plugin ${pluginName.value}:`, err)
    error.value = `Failed to load plugin: ${getErrorMessage(err) || 'Unknown error'}`

    // Fallback: Check for index.html if index.js failed
    try {
      const htmlUrl = `${pluginBaseUrl}/index.html`
      const response = await fetch(htmlUrl)
      if (response.ok) {
        const html = await response.text()
        pluginContainer.value.innerHTML = html
        error.value = null // Clear error if we found index.html
      }
    } catch {
      // Ignore html error if JS failed
    }
  } finally {
    loading.value = false
  }
}

function unloadPlugin() {
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
</script>

<style scoped>
.plugin-host {
  width: 100%;
  height: 100%;
}
</style>
