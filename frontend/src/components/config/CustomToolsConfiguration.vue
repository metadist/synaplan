<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import CustomToolEditor from '@/components/config/CustomToolEditor.vue'
import OpenApiImportWizard from '@/components/config/OpenApiImportWizard.vue'
import { customToolsApi, type CustomTool } from '@/services/api/customToolsApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { isCustomToolsEnabled } from '@/composables/useCustomToolsFeature'

const { t } = useI18n()
const { success, error: showError } = useNotification()
const dialog = useDialog()
const tools = ref<CustomTool[]>([])
const loading = ref(true)
const editing = ref<CustomTool | null>(null)
const creating = ref(false)
const importing = ref(false)

const enabled = computed(() => isCustomToolsEnabled())
const editorKey = computed(() => editing.value?.id ?? 'new')

const load = async () => {
  if (!enabled.value) {
    tools.value = []
    loading.value = false
    return
  }
  loading.value = true
  try {
    tools.value = await customToolsApi.list()
  } catch {
    showError(t('customTools.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

const onDelete = async (tool: CustomTool) => {
  const ok = await dialog.confirm({
    title: t('customTools.delete'),
    message: t('customTools.deleteConfirm', { name: tool.title }),
    danger: true,
  })
  if (!ok) return
  try {
    await customToolsApi.remove(tool.id)
    success(t('customTools.deleted'))
    await load()
  } catch {
    showError(t('customTools.deleteFailed'))
  }
}

const classLabel = (sideEffect: string): string => {
  if (sideEffect === 'read') return t('customTools.classRead')
  if (sideEffect === 'destructive') return t('customTools.classDestructive')
  return t('customTools.classWrite')
}

const closeEditor = (): void => {
  creating.value = false
  editing.value = null
}

const onEditorSaved = (): void => {
  closeEditor()
  void load()
}

const closeImport = (): void => {
  importing.value = false
}

const onImportApplied = (): void => {
  closeImport()
  void load()
}
</script>

<template>
  <section v-if="enabled" class="space-y-4" data-testid="page-custom-tools">
    <PageHeader :title="$t('customTools.title')" :subtitle="$t('customTools.subtitle')">
      <template #actions>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="custom-tools-import"
          @click="importing = true"
        >
          {{ $t('customTools.import') }}
        </button>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="custom-tools-add"
          @click="creating = true"
        >
          {{ $t('customTools.add') }}
        </button>
      </template>
    </PageHeader>

    <p v-if="loading" class="txt-secondary text-sm">{{ $t('customTools.loading') }}</p>
    <p
      v-else-if="tools.length === 0"
      class="txt-secondary text-sm"
      data-testid="custom-tools-empty"
    >
      {{ $t('customTools.empty') }}
    </p>
    <ul v-else class="space-y-3">
      <li
        v-for="tool in tools"
        :key="tool.id"
        class="surface-card p-4 flex flex-col sm:flex-row sm:items-center gap-3"
      >
        <div class="min-w-0 flex-1">
          <p class="font-medium txt-primary">{{ tool.title }}</p>
          <p class="text-xs txt-secondary">{{ classLabel(tool.sideEffect) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            @click="editing = tool"
          >
            {{ $t('customTools.edit') }}
          </button>
          <button
            type="button"
            class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
            @click="onDelete(tool)"
          >
            {{ $t('customTools.delete') }}
          </button>
        </div>
      </li>
    </ul>

    <CustomToolEditor
      v-if="creating || editing"
      :key="editorKey"
      :tool="editing"
      @close="closeEditor"
      @saved="onEditorSaved"
    />
    <OpenApiImportWizard
      v-if="importing"
      @close="closeImport"
      @applied="onImportApplied"
    />
  </section>
</template>
