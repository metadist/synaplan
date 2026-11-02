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
      <h2 class="txt-primary text-lg font-medium">{{ $t(template.titleKey ?? '') }}</h2>
      <p class="txt-secondary text-sm">{{ $t(template.summaryKey ?? '') }}</p>
      <p class="txt-secondary text-xs">
        {{
          (template.usedBy ?? 0) > 0
            ? $t('templates.usedBy', { count: template.usedBy ?? 0 })
            : $t('templates.usedByZero')
        }}
      </p>
    </header>
    <p class="txt-primary text-sm" data-testid="text-template-preview">
      {{ $t('templates.preview') }}
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
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        :disabled="busy"
        data-testid="btn-template-install"
        @click="install('me')"
      >
        {{
          template.installedAgentId
            ? $t('templates.open')
            : busy
              ? $t('templates.installing')
              : $t('templates.install')
        }}
      </button>
      <button
        v-if="isAdmin"
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
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  agentTemplatesApi,
  type AgentTemplate,
  type AgentTemplateRequirement,
} from '@/services/api/agentTemplatesApi'

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
const auth = useAuthStore()
const { confirm } = useDialog()
const { success, error } = useNotification()
const busy = ref(false)
const isAdmin = computed(() => auth.isAdmin)
const unmet = computed(() => (props.template.requires ?? []).filter((row) => row.state !== 'ready'))

function requirementText(requirement: AgentTemplateRequirement): string {
  if (requirement.state === 'unavailable' && requirement.value === 'pic2text') {
    return t('templates.unavailableImage')
  }
  if (requirement.state === 'unavailable') {
    return t('templates.unavailableChat')
  }
  if (requirement.hintKey) return t(requirement.hintKey)
  if (requirement.kind === 'connection') return t('templates.connectMail')
  if (requirement.kind === 'knowledgeFolder') return t('templates.chooseFolder')
  return t('templates.preview')
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
