<template>
  <div class="space-y-6" data-testid="page-config-mcp-servers">
    <!-- Header -->
    <div class="surface-card p-6" data-testid="section-mcp-overview">
      <div class="flex items-start gap-3">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10">
          <Icon icon="heroicons:server-stack" class="w-6 h-6 text-[var(--brand)]" />
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="text-2xl font-semibold txt-primary mb-1">{{ $t('mcpServers.title') }}</h2>
          <p class="txt-secondary text-sm leading-relaxed">{{ $t('mcpServers.description') }}</p>
          <p
            v-if="!clientEnabled"
            class="text-sm mt-3 px-3 py-2 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400"
            data-testid="mcp-client-disabled-hint"
          >
            {{ $t('mcpServers.clientDisabledHint') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Server list -->
    <div class="surface-card p-6" data-testid="section-mcp-list">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold txt-primary">{{ $t('mcpServers.listTitle') }}</h3>
        <button
          type="button"
          class="btn-primary px-4 py-2 rounded-lg text-sm font-medium"
          data-testid="btn-mcp-add"
          @click="startCreate"
        >
          {{ $t('mcpServers.add') }}
        </button>
      </div>

      <p v-if="!loading && servers.length === 0" class="txt-secondary text-sm">
        {{ $t('mcpServers.empty') }}
      </p>

      <ul v-else class="divide-y divide-light-border/20 dark:divide-dark-border/20">
        <li
          v-for="server in servers"
          :key="server.id"
          class="py-3 flex flex-wrap items-center gap-3"
          :data-testid="`mcp-server-${server.id}`"
        >
          <div class="flex-1 min-w-0">
            <p class="txt-primary text-sm font-medium truncate">{{ server.name }}</p>
            <p class="txt-secondary text-xs font-mono truncate">{{ server.url }}</p>
          </div>
          <span
            class="text-xs px-2 py-1 rounded-full"
            :class="
              server.enabled
                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                : 'bg-gray-500/10 txt-secondary'
            "
          >
            {{ server.enabled ? $t('mcpServers.enabled') : $t('mcpServers.disabled') }}
          </span>
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="text-sm text-[var(--brand)] hover:underline"
              :disabled="testingId === server.id"
              :data-testid="`btn-mcp-test-${server.id}`"
              @click="testServer(server)"
            >
              {{ testingId === server.id ? $t('mcpServers.testing') : $t('mcpServers.test') }}
            </button>
            <button
              type="button"
              class="text-sm text-[var(--brand)] hover:underline"
              @click="startEdit(server)"
            >
              {{ $t('common.edit') }}
            </button>
            <button
              type="button"
              class="text-sm text-red-500 hover:underline"
              @click="removeServer(server)"
            >
              {{ $t('common.delete') }}
            </button>
          </div>
          <div v-if="toolsByServer[server.id ?? -1]" class="w-full pl-1">
            <p class="text-xs txt-secondary mb-1">
              {{
                $t('mcpServers.discoveredTools', { count: toolsByServer[server.id ?? -1].length })
              }}
            </p>
            <ul class="flex flex-wrap gap-2">
              <li
                v-for="tool in toolsByServer[server.id ?? -1]"
                :key="tool.name"
                class="text-xs px-2 py-1 rounded bg-[var(--brand)]/10 txt-primary font-mono"
                :title="tool.description"
              >
                {{ tool.name }}
              </li>
            </ul>
          </div>
        </li>
      </ul>
    </div>

    <!-- Task usage: which tasks may pull data from the connected servers.
         Connecting a server alone does nothing — every task (routing topic)
         must opt in via its `tool_mcp` prompt metadata. Surfacing the flip
         switches HERE closes the "I connected a server but nothing happens"
         onboarding gap. -->
    <div
      v-if="servers.length > 0 && taskPrompts.length > 0"
      class="surface-card p-6"
      data-testid="section-mcp-usage"
    >
      <h3 class="text-lg font-semibold txt-primary mb-1">{{ $t('mcpServers.usageTitle') }}</h3>
      <p class="txt-secondary text-sm leading-relaxed mb-4">
        {{ $t('mcpServers.usageDescription') }}
      </p>

      <p
        v-if="showNotUsedWarning"
        class="text-sm mb-4 px-3 py-2 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400"
        data-testid="mcp-usage-warning"
      >
        {{ $t('mcpServers.usageNotUsedWarning') }}
      </p>

      <ul class="divide-y divide-light-border/20 dark:divide-dark-border/20">
        <li
          v-for="prompt in taskPrompts"
          :key="prompt.id"
          class="py-3 flex items-center gap-3"
          :data-testid="`mcp-usage-${prompt.topic}`"
        >
          <div class="flex-1 min-w-0">
            <p class="txt-primary text-sm font-medium truncate">{{ prompt.name }}</p>
            <p class="txt-secondary text-xs truncate">{{ prompt.shortDescription }}</p>
          </div>
          <label class="inline-flex items-center gap-3 cursor-pointer flex-shrink-0">
            <span class="text-xs txt-secondary">
              {{
                prompt.metadata?.tool_mcp === true
                  ? $t('mcpServers.usageOn')
                  : $t('mcpServers.usageOff')
              }}
            </span>
            <span class="relative inline-flex">
              <input
                type="checkbox"
                class="sr-only peer"
                :checked="prompt.metadata?.tool_mcp === true"
                :disabled="togglingPromptId === prompt.id"
                :data-testid="`toggle-mcp-topic-${prompt.topic}`"
                @change="toggleTopicMcp(prompt)"
              />
              <span
                class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand)] peer-disabled:opacity-50 transition-colors"
              ></span>
              <span
                class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"
              ></span>
            </span>
          </label>
        </li>
      </ul>

      <p class="text-xs txt-secondary mt-4">
        {{ $t('mcpServers.usageAdvancedHint') }}
        <RouterLink to="/ai/instructions" class="text-[var(--brand)] hover:underline">
          {{ $t('mcpServers.usageAdvancedLink') }}
        </RouterLink>
      </p>
    </div>

    <!-- Editor -->
    <div v-if="editorOpen" class="surface-card p-6" data-testid="section-mcp-editor">
      <h3 class="text-lg font-semibold txt-primary mb-1">
        {{ editingId ? $t('mcpServers.editTitle') : $t('mcpServers.addTitle') }}
      </h3>
      <p class="txt-secondary text-sm mb-5">{{ $t('mcpServers.formHint') }}</p>

      <div class="space-y-4">
        <label class="block">
          <span class="text-sm font-medium txt-primary">{{ $t('mcpServers.nameLabel') }}</span>
          <input
            v-model="form.name"
            type="text"
            class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-mcp-name"
          />
        </label>
        <label class="block">
          <span class="text-sm font-medium txt-primary">{{ $t('mcpServers.urlLabel') }}</span>
          <input
            v-model="form.url"
            type="url"
            placeholder="https://example.com/mcp"
            class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
            data-testid="input-mcp-url"
          />
        </label>
        <div class="grid sm:grid-cols-2 gap-4">
          <label class="block">
            <span class="text-sm font-medium txt-primary">{{
              $t('mcpServers.authHeaderLabel')
            }}</span>
            <input
              v-model="form.authHeader"
              type="text"
              placeholder="Authorization"
              class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
              data-testid="input-mcp-auth-header"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium txt-primary">{{
              $t('mcpServers.authTokenLabel')
            }}</span>
            <input
              v-model="form.authToken"
              type="password"
              autocomplete="off"
              :placeholder="editingHasToken ? '••••••••' : $t('mcpServers.authTokenPlaceholder')"
              class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
              data-testid="input-mcp-auth-token"
            />
          </label>
        </div>
        <label class="inline-flex items-center gap-2 text-sm txt-primary">
          <input v-model="form.enabled" type="checkbox" class="accent-[var(--brand)]" />
          {{ $t('mcpServers.enabledLabel') }}
        </label>
      </div>

      <div class="flex flex-wrap items-center gap-3 mt-6">
        <button
          type="button"
          class="btn-primary px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
          :disabled="saving || !form.name.trim() || !form.url.trim()"
          data-testid="btn-mcp-save"
          @click="save"
        >
          {{ saving ? $t('common.saving') : $t('common.save') }}
        </button>
        <button
          type="button"
          class="px-4 py-2 rounded-lg text-sm txt-secondary hover:txt-primary"
          @click="closeEditor"
        >
          {{ $t('common.cancel') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { mcpServersApi, type McpServer, type McpTool } from '@/services/api/mcpServersApi'
import { promptsApi, type PromptMetadata, type TaskPrompt } from '@/services/api/promptsApi'
import { useAuthStore } from '@/stores/auth'

const { t, locale } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()
const authStore = useAuthStore()
const isAdmin = computed(() => authStore.isAdmin)

const loading = ref(true)
const saving = ref(false)
const clientEnabled = ref(false)
const servers = ref<McpServer[]>([])
const toolsByServer = reactive<Record<number, McpTool[]>>({})
const testingId = ref<number | null>(null)

// Task usage panel: routing topics with their `tool_mcp` opt-in state.
const taskPrompts = ref<TaskPrompt[]>([])
const togglingPromptId = ref<number | null>(null)

const showNotUsedWarning = computed(
  () =>
    clientEnabled.value &&
    servers.value.some((s) => s.enabled) &&
    taskPrompts.value.length > 0 &&
    !taskPrompts.value.some((p) => p.metadata?.tool_mcp === true)
)

const editorOpen = ref(false)
const editingId = ref<number | null>(null)
const editingHasToken = ref(false)
const form = reactive({ name: '', url: '', authHeader: '', authToken: '', enabled: true })

const load = async () => {
  loading.value = true
  try {
    const data = await mcpServersApi.list()
    clientEnabled.value = data.clientEnabled
    servers.value = data.servers
  } catch {
    error(t('mcpServers.loadFailed'))
  } finally {
    loading.value = false
  }
}

const loadTaskPrompts = async () => {
  try {
    const prompts = await promptsApi.getPrompts(locale.value || 'en')
    // Widget assistants (w_*) are not routing topics; they cannot opt in.
    taskPrompts.value = prompts.filter((p) => !p.topic.startsWith('w_'))
  } catch {
    // Non-fatal: the usage panel simply stays hidden.
    taskPrompts.value = []
  }
}

/**
 * Flip the per-task "MCP Data Sources" opt-in (`tool_mcp` prompt metadata)
 * directly from the connections page. Mirrors TaskPromptsConfiguration's
 * save semantics: a plain user flipping a system default gets a personal
 * override copy; user-owned prompts (and admins on system prompts) update
 * in place. The full metadata map is sent because the backend replaces
 * metadata wholesale on save.
 */
const toggleTopicMcp = async (prompt: TaskPrompt) => {
  const next = !(prompt.metadata?.tool_mcp === true)
  const metadata: PromptMetadata = { ...(prompt.metadata || {}), tool_mcp: next }
  if (typeof metadata.aiModel !== 'number' || metadata.aiModel < 0) {
    metadata.aiModel = 0
  }

  togglingPromptId.value = prompt.id
  try {
    let updated: TaskPrompt
    if (prompt.isDefault && !prompt.isUserOverride && !isAdmin.value) {
      updated = await promptsApi.createPrompt({
        topic: prompt.topic,
        shortDescription: prompt.shortDescription,
        prompt: prompt.prompt,
        language: prompt.language || 'en',
        selectionRules: prompt.selectionRules ?? null,
        metadata,
      })
      updated = { ...updated, isUserOverride: true }
    } else {
      updated = await promptsApi.updatePrompt(prompt.id, { metadata })
    }

    const index = taskPrompts.value.findIndex((p) => p.id === prompt.id)
    if (index !== -1) {
      taskPrompts.value[index] = { ...taskPrompts.value[index], ...updated }
    }
    success(next ? t('mcpServers.usageEnabled') : t('mcpServers.usageDisabled'))
  } catch (err) {
    error(err instanceof Error && err.message ? err.message : t('mcpServers.usageUpdateFailed'))
  } finally {
    togglingPromptId.value = null
  }
}

const startCreate = () => {
  editingId.value = null
  editingHasToken.value = false
  Object.assign(form, { name: '', url: '', authHeader: '', authToken: '', enabled: true })
  editorOpen.value = true
}

const startEdit = (server: McpServer) => {
  editingId.value = server.id ?? null
  editingHasToken.value = server.has_auth_token ?? false
  Object.assign(form, {
    name: server.name ?? '',
    url: server.url ?? '',
    authHeader: server.auth_header ?? '',
    authToken: '',
    enabled: server.enabled ?? true,
  })
  editorOpen.value = true
}

const closeEditor = () => {
  editorOpen.value = false
}

const save = async () => {
  saving.value = true
  try {
    const payload = {
      name: form.name.trim(),
      url: form.url.trim(),
      auth_header: form.authHeader.trim(),
      enabled: form.enabled,
      // Only send the secret when the user actually typed one — absent keeps
      // the stored value.
      ...(form.authToken !== '' ? { auth_token: form.authToken } : {}),
    }
    if (editingId.value !== null) {
      await mcpServersApi.update(editingId.value, payload)
    } else {
      await mcpServersApi.create(payload)
    }
    success(t('mcpServers.saved'))
    editorOpen.value = false
    await load()
  } catch (err) {
    error(err instanceof Error && err.message ? err.message : t('mcpServers.saveFailed'))
  } finally {
    saving.value = false
  }
}

const removeServer = async (server: McpServer) => {
  const confirmed = await confirm({
    title: t('mcpServers.deleteTitle'),
    message: t('mcpServers.deleteMessage', { name: server.name ?? '' }),
    confirmText: t('common.delete'),
    danger: true,
  })
  if (!confirmed || server.id === undefined) return

  try {
    await mcpServersApi.remove(server.id)
    success(t('mcpServers.deleted'))
    await load()
  } catch {
    error(t('mcpServers.deleteFailed'))
  }
}

const testServer = async (server: McpServer) => {
  if (server.id === undefined) return
  testingId.value = server.id
  try {
    const result = await mcpServersApi.test(server.id)
    if (result.success) {
      toolsByServer[server.id] = result.tools
      success(t('mcpServers.testSuccess', { count: result.tools.length }))
    } else {
      error(result.error || t('mcpServers.testFailed'))
    }
  } catch {
    error(t('mcpServers.testFailed'))
  } finally {
    testingId.value = null
  }
}

onMounted(() => {
  void load()
  void loadTaskPrompts()
})
</script>
