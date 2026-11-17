<template>
  <div class="space-y-3" data-testid="section-mcp-permissions">
    <div>
      <p class="txt-primary text-sm font-medium">{{ $t('mcpServers.permissions.title') }}</p>
      <p class="txt-secondary text-sm mt-1">{{ $t('mcpServers.permissions.intro') }}</p>
    </div>

    <p v-if="loading" class="txt-secondary text-sm" data-testid="mcp-permissions-loading">
      {{ $t('mcpServers.permissions.loading') }}
    </p>
    <div v-else-if="loadError" data-testid="mcp-permissions-error">
      <p class="txt-primary text-sm">{{ $t('mcpServers.permissions.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary mt-3 px-4 py-2.5 text-sm font-medium"
        data-testid="btn-mcp-permissions-retry"
        @click="load"
      >
        {{ $t('mcpServers.permissions.retry') }}
      </button>
    </div>
    <p v-else-if="!list?.success" class="txt-secondary text-sm" data-testid="mcp-permissions-off">
      {{ $t('mcpServers.permissions.clientOff') }}
    </p>
    <p
      v-else-if="list.tools.length === 0"
      class="txt-secondary text-sm"
      data-testid="mcp-permissions-empty"
    >
      {{ $t('mcpServers.permissions.empty') }}
    </p>
    <template v-else>
      <McpToolCategory
        v-for="group in groups"
        :key="group.category"
        :category="group.category"
        :tools="group.tools"
        :disabled="saving || !list.can_edit"
        :new-names="newNames"
        @set-tool="setTool"
        @set-category="(outcome) => setCategory(group.category, group.tools, outcome)"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  mcpServersApi,
  type McpToolPermission,
  type McpToolPermissionList,
} from '@/services/api/mcpServersApi'
import { userFacingError } from '@/utils/userFacingError'
import McpToolCategory from './McpToolCategory.vue'
import { CATEGORIES, type ToolCategory, type ToolOutcome } from './toolPermissions'

const props = defineProps<{ serverId: number; serverName: string }>()

const { t } = useI18n()
const { confirm } = useDialog()
const { push, error } = useNotification()

const list = ref<McpToolPermissionList | null>(null)
const loading = ref(true)
const loadError = ref(false)
const saving = ref(false)
/** Tools that were new when the tab opened; they stay marked for this view. */
const newNames = ref(new Set<string>())

const groups = computed(() =>
  CATEGORIES.map((category) => ({
    category,
    tools: (list.value?.tools ?? []).filter((tool) => tool.category === category),
  })).filter((group) => group.tools.length > 0)
)

const outcomeLabel = (outcome: ToolOutcome) => t(`mcpServers.permissions.outcome.${outcome}`)

const load = async () => {
  loading.value = true
  loadError.value = false
  try {
    list.value = await mcpServersApi.toolPermissions(props.serverId)
    newNames.value = new Set(
      list.value.tools.filter((tool) => tool.is_new).map((tool) => tool.name)
    )
    if (list.value.can_edit && newNames.value.size > 0) {
      void mcpServersApi.markToolsSeen(props.serverId).catch(() => undefined)
    }
  } catch {
    loadError.value = true
    list.value = null
  } finally {
    loading.value = false
  }
}

/** Auto on a destructive tool needs a yes first. */
const confirmDestructive = (category: ToolCategory, outcome: ToolOutcome) =>
  category !== 'destructive' || outcome !== 'auto'
    ? Promise.resolve(true)
    : confirm({
        title: t('mcpServers.permissions.confirmTitle'),
        message: t('mcpServers.permissions.confirmMessage', { connection: props.serverName }),
        confirmText: t('mcpServers.permissions.confirmAction'),
        danger: true,
      })

/** Saves at once and offers Undo, which restores each tool's earlier choice. */
const apply = async (
  changes: Record<string, ToolOutcome>,
  previous: Record<string, ToolOutcome>,
  message: string
) => {
  saving.value = true
  try {
    list.value = await mcpServersApi.setToolPermissions(props.serverId, { tools: changes })
    push({
      type: 'success',
      message,
      duration: 8000,
      action: { label: t('mcpServers.permissions.undo'), onClick: () => void undo(previous) },
    })
  } catch (err) {
    error(userFacingError(err, t('mcpServers.permissions.saveFailed')))
    await load()
  } finally {
    saving.value = false
  }
}

const undo = async (previous: Record<string, ToolOutcome>) => {
  saving.value = true
  try {
    list.value = await mcpServersApi.setToolPermissions(props.serverId, { tools: previous })
  } catch (err) {
    error(userFacingError(err, t('mcpServers.permissions.saveFailed')))
    await load()
  } finally {
    saving.value = false
  }
}

const setTool = async (tool: McpToolPermission, outcome: ToolOutcome) => {
  if (
    outcome === tool.outcome ||
    !(await confirmDestructive(tool.category as ToolCategory, outcome))
  ) {
    await load()
    return
  }
  await apply(
    { [tool.name]: outcome },
    { [tool.name]: tool.outcome },
    t('mcpServers.permissions.saved', { tool: tool.title, outcome: outcomeLabel(outcome) })
  )
}

const setCategory = async (
  category: ToolCategory,
  tools: McpToolPermission[],
  outcome: ToolOutcome
) => {
  const touched = tools.filter((tool) => tool.outcome !== outcome && tool.locked_reason === null)
  if (touched.length === 0 || !(await confirmDestructive(category, outcome))) {
    await load()
    return
  }
  await apply(
    Object.fromEntries(touched.map((tool) => [tool.name, outcome])),
    Object.fromEntries(touched.map((tool) => [tool.name, tool.outcome])),
    t(
      'mcpServers.permissions.categorySaved',
      { count: touched.length, outcome: outcomeLabel(outcome) },
      touched.length
    )
  )
}

onMounted(() => void load())
</script>
