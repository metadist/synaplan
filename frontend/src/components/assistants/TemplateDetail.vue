<template>
  <article class="surface-card rounded-xl p-5 space-y-4" data-testid="section-template-detail">
    <button
      type="button"
      class="btn-secondary px-4 py-2.5 text-sm font-medium"
      data-testid="btn-template-back"
      @click="emit('back')"
    >
      {{ $t('templates.back') }}
    </button>
    <header class="space-y-1">
      <p v-if="isPlugin" class="txt-secondary text-xs" data-testid="badge-from-plugin">
        {{ $t('templates.fromPlugin', { name: template.pluginName }) }}
      </p>
      <h2 class="txt-primary text-lg font-medium">{{ templateTitle(template, t) }}</h2>
      <p class="txt-secondary text-sm">{{ templateSummary(template, t) }}</p>
      <p v-if="!isPlugin" class="txt-secondary text-xs">
        {{
          (template.usedBy ?? 0) > 0
            ? $t('templates.usedBy', { count: template.usedBy ?? 0 })
            : $t('templates.usedByZero')
        }}
      </p>
    </header>
    <p class="txt-primary text-sm" data-testid="text-template-preview">
      {{ preview }}
    </p>
    <p v-if="isPlugin && !sharingOff" class="txt-secondary text-sm" data-testid="text-plugin-stop">
      {{ $t('templates.pluginStop') }}
    </p>
    <ul class="space-y-2" data-testid="list-template-requires">
      <li
        v-for="(requirement, index) in unmet"
        :key="`${requirement.kind}-${index}`"
        class="txt-secondary text-sm"
      >
        {{ requirementText(requirement) }}
      </li>
    </ul>
    <div class="flex flex-wrap gap-2">
      <button
        v-if="showTry"
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-template-try"
        @click="tryPlugin"
      >
        {{ $t('templates.tryIt') }}
      </button>
      <button
        v-else-if="!isPlugin || showAdd || installedId != null"
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        :disabled="busy"
        data-testid="btn-template-install"
        @click="onPrimary"
      >
        {{ primaryLabel }}
      </button>
      <button
        v-if="isAdmin && !isPlugin"
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        :disabled="busy"
        data-testid="btn-template-install-everyone"
        @click="install('everyone')"
      >
        {{ $t('templates.installForEveryone') }}
      </button>
    </div>
  </article>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  agentTemplatesApi,
  templateSummary,
  templateTitle,
  type AgentTemplate,
  type AgentTemplateRequirement,
} from '@/services/api/agentTemplatesApi'
import { requirementLabel, templatePreview } from './templatePreview'

const props = defineProps<{
  template: AgentTemplate
  peopleCount: number | null
  publishReviewRequired: boolean
}>()

const emit = defineEmits<{
  back: []
  installed: [id: number]
}>()

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const { confirm } = useDialog()
const { success, error } = useNotification()
const busy = ref(false)
const isAdmin = computed(() => auth.isAdmin)
const isPlugin = computed(() => Boolean(props.template.pluginName))
const sharingOff = computed(() => props.template.sharingOff === true)
const installedId = computed(() => props.template.installedAgentId ?? null)
const showTry = computed(
  () => isPlugin.value && installedId.value != null && props.template.canEdit !== true
)
const showAdd = computed(() => isPlugin.value && installedId.value == null && isAdmin.value)
const unmet = computed(() => (props.template.requires ?? []).filter((row) => row.state !== 'ready'))
const preview = computed(() => {
  if (!isPlugin.value) return templatePreview(props.template, t)
  const name = props.template.pluginName ?? ''
  if (installedId.value == null) {
    return sharingOff.value
      ? t('templates.pluginAddSharingOff', { name })
      : t('templates.pluginAddConfirm', { name })
  }
  if (props.template.canEdit === true) {
    return sharingOff.value
      ? t('templates.pluginOwnerSharingOff', { name })
      : t('templates.pluginReady', { name })
  }
  return t('templates.pluginUse', { name })
})
const primaryLabel = computed(() => {
  if (isPlugin.value && installedId.value == null) {
    return busy.value
      ? t('templates.installing')
      : sharingOff.value
        ? t('templates.pluginAddMine')
        : t('templates.pluginAdd')
  }
  if (installedId.value != null) return t('templates.open')
  return busy.value ? t('templates.installing') : t('templates.install')
})

function requirementText(requirement: AgentTemplateRequirement): string {
  return requirementLabel(requirement, t)
}

function onPrimary(): void {
  if (isPlugin.value && installedId.value == null) {
    void addPlugin()
    return
  }
  void install('me')
}

async function tryPlugin(): Promise<void> {
  const id = installedId.value
  if (id == null) return
  const example = (props.template.tryIt ?? []).find(
    (line) => line !== '' && !line.startsWith('templates.')
  )
  await router.push({
    name: 'chat',
    query: {
      agentId: String(id),
      ...(example ? { prefill: example } : {}),
    },
  })
}

async function addPlugin(): Promise<void> {
  const id = props.template.id
  if (!id) return
  const name = props.template.pluginName ?? ''
  const ok = await confirm({
    title: sharingOff.value ? t('templates.pluginAddMine') : t('templates.pluginAddConfirmTitle'),
    message: sharingOff.value
      ? t('templates.pluginAddSharingOff', { name })
      : t('templates.pluginAddConfirm', { name }),
  })
  if (!ok) return
  busy.value = true
  try {
    const result = await agentTemplatesApi.install(id, {
      audience: 'everyone',
      title: props.template.title ?? name,
      description: props.template.summary ?? '',
      tryIt: [],
    })
    if (result.agentId == null) {
      error(t('templates.installFailed'))
      return
    }
    if (result.alreadyInstalled) success(t('templates.alreadyInstalled'))
    else if (result.sharingOff) success(t('templates.installedSharingOff'))
    else if (result.shared) success(t('templates.installedShared'))
    else success(t('templates.installed'))
    emit('installed', result.agentId)
  } catch {
    error(t('templates.installFailed'))
  } finally {
    busy.value = false
  }
}

async function install(audience: 'me' | 'everyone'): Promise<void> {
  const id = props.template.id
  if (!id) return
  if (audience === 'me' && props.template.installedAgentId) {
    emit('installed', props.template.installedAgentId)
    return
  }
  if (audience === 'everyone') {
    const ok = await confirm({
      title: t('templates.installForEveryoneConfirmTitle'),
      message: props.publishReviewRequired
        ? t('templates.installForEveryoneConfirmReview', { count: props.peopleCount ?? 0 })
        : t('templates.installForEveryoneConfirm', { count: props.peopleCount ?? 0 }),
    })
    if (!ok) return
  }
  busy.value = true
  try {
    const result = await agentTemplatesApi.install(id, {
      audience,
      title: t(props.template.titleKey ?? ''),
      description: t(props.template.summaryKey ?? ''),
      tryIt: (props.template.tryIt ?? []).map((key) => t(key)),
    })
    if (result.agentId == null) {
      error(t('templates.installFailed'))
      return
    }
    if (result.waitingForReview) success(t('templates.installedWaitingReview'))
    else if (result.alreadyInstalled) success(t('templates.alreadyInstalled'))
    else if (result.sharingOff) success(t('templates.installedSharingOff'))
    else if (result.shared) success(t('templates.installedShared'))
    else success(t('templates.installed'))
    emit('installed', result.agentId)
  } catch {
    error(t('templates.installFailed'))
  } finally {
    busy.value = false
  }
}
</script>
