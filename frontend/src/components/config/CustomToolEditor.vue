<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { customToolsApi, customToolFieldClass, type CustomTool } from '@/services/api/customToolsApi'
import CustomToolTryPanel from '@/components/config/CustomToolTryPanel.vue'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{
  tool: CustomTool | null
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()
const fieldClass = customToolFieldClass
const saving = ref(false)

const name = ref(props.tool?.name ?? '')
const title = ref(props.tool?.title ?? '')
const description = ref(props.tool?.description ?? '')
const sideEffect = ref(props.tool?.sideEffect ?? 'write')
const method = ref(String(props.tool?.spec?.method ?? 'GET'))
const url = ref(String(props.tool?.spec?.url ?? ''))
const body = ref(
  typeof props.tool?.spec?.body === 'string'
    ? props.tool.spec.body
    : JSON.stringify(props.tool?.spec?.body ?? {}, null, 2)
)

const methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']

const payload = computed(() => {
  let parsedBody: unknown = body.value
  try {
    parsedBody = body.value.trim() === '' ? undefined : JSON.parse(body.value)
  } catch {
    parsedBody = body.value
  }
  return {
    name: name.value,
    title: title.value || name.value,
    description: description.value || null,
    sideEffect: sideEffect.value,
    spec: {
      method: method.value,
      url: url.value,
      body: parsedBody,
    },
  }
})

const save = async () => {
  saving.value = true
  try {
    if (props.tool) {
      await customToolsApi.update(props.tool.id, payload.value)
    } else {
      await customToolsApi.create(payload.value)
    }
    success(t('customTools.saved'))
    emit('saved')
  } catch (err) {
    showError(err instanceof Error ? err.message : t('customTools.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="surface-card p-4 space-y-3" data-testid="custom-tool-editor">
    <h3 class="font-semibold txt-primary">
      {{ tool ? $t('customTools.edit') : $t('customTools.add') }}
    </h3>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.name') }}
      <input v-model="name" :class="fieldClass" :disabled="!!tool" />
    </label>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.titleLabel') }}
      <input v-model="title" :class="fieldClass" />
    </label>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.description') }}
      <textarea v-model="description" rows="2" :class="fieldClass" />
    </label>
    <fieldset class="space-y-2">
      <legend class="text-sm txt-primary">{{ $t('customTools.class') }}</legend>
      <label class="flex items-center gap-2 text-sm txt-primary">
        <input v-model="sideEffect" type="radio" value="read" class="accent-[var(--brand)]" />
        {{ $t('customTools.classRead') }}
      </label>
      <label class="flex items-center gap-2 text-sm txt-primary">
        <input v-model="sideEffect" type="radio" value="write" class="accent-[var(--brand)]" />
        {{ $t('customTools.classWrite') }}
      </label>
      <label class="flex items-center gap-2 text-sm txt-primary">
        <input v-model="sideEffect" type="radio" value="destructive" class="accent-[var(--brand)]" />
        {{ $t('customTools.classDestructive') }}
      </label>
    </fieldset>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.method') }}
      <select v-model="method" :class="fieldClass">
        <option v-for="item in methods" :key="item" :value="item">{{ item }}</option>
      </select>
    </label>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.url') }}
      <input v-model="url" :class="fieldClass" placeholder="https://api.example.com/tickets" />
    </label>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.body') }}
      <textarea v-model="body" rows="4" :class="fieldClass" />
    </label>
    <CustomToolTryPanel v-if="tool" :tool="tool" />
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="saving"
        @click="save"
      >
        {{ $t('customTools.save') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        @click="emit('close')"
      >
        {{ $t('customTools.cancel') }}
      </button>
    </div>
  </div>
</template>
