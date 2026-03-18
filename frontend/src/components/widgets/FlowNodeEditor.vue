<template>
  <div
    class="rounded-xl border-2 border-[var(--brand)]/30 bg-[var(--brand)]/[0.03] p-4 space-y-3"
    @click.stop
  >
    <!-- Type selector (responses only) -->
    <div v-if="nodeType === 'response'" class="relative">
      <label class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1">
        {{ $t('widgets.detail.nodeEditor.type') }}
      </label>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="rt in responseTypes"
          :key="rt.key"
          type="button"
          :disabled="rt.requiresPro && !isPro"
          :title="rt.requiresPro && !isPro ? $t('widgets.detail.nodeEditor.upgradeRequired') : ''"
          :class="[
            'flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium border transition-all',
            form.type === rt.key
              ? 'border-[var(--brand)] bg-[var(--brand)]/10 txt-brand'
              : rt.requiresPro && !isPro
                ? 'border-light-border/30 dark:border-dark-border/20 txt-secondary opacity-50 cursor-not-allowed'
                : 'border-light-border/30 dark:border-dark-border/20 txt-secondary hover:border-[var(--brand)]/40',
          ]"
          @click="!(rt.requiresPro && !isPro) && (form.type = rt.key)"
        >
          <Icon :icon="rt.icon" class="w-3.5 h-3.5" />
          {{ $t(`widgets.detail.responseTemplates.${rt.key}`) }}
          <Icon
            v-if="rt.requiresPro && !isPro"
            icon="heroicons:lock-closed-solid"
            class="w-3 h-3 ml-0.5"
          />
        </button>
      </div>
    </div>

    <!-- Label / Name -->
    <div class="relative">
      <label class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1">
        {{ $t('widgets.detail.nodeEditor.name') }}
      </label>
      <input
        ref="nameInputRef"
        v-model="form.label"
        class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
      />
    </div>

    <!-- Content (text, list, custom, triggers) -->
    <div
      v-if="
        nodeType === 'trigger' ||
        form.type === 'text' ||
        form.type === 'list' ||
        form.type === 'custom'
      "
      class="relative"
    >
      <label class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1">
        {{ $t('widgets.detail.nodeEditor.content') }}
      </label>
      <textarea
        v-model="form.content"
        :rows="form.type === 'list' ? 4 : 3"
        :placeholder="contentPlaceholder"
        class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary resize-none focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
      />
    </div>

    <!-- URL (link, api) -->
    <div v-if="form.type === 'link' || form.type === 'api'" class="relative">
      <label class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1">
        {{ form.type === 'api' ? $t('widgets.detail.nodeEditor.endpoint') : 'URL' }}
      </label>
      <input
        v-model="form.url"
        :placeholder="
          form.type === 'api'
            ? 'https://api.example.com/v1/users/{externalUserId}/profile'
            : 'https://...'
        "
        class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
      />
      <p v-if="form.type === 'api'" class="text-[10px] txt-secondary mt-1">
        {{
          $t('widgets.detail.nodeEditor.externalUserIdHint', {
            placeholder: '{externalUserId}',
          })
        }}
      </p>
    </div>

    <!-- Method (api only) -->
    <div v-if="form.type === 'api'" class="relative">
      <label class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1">
        {{ $t('widgets.detail.nodeEditor.method') }}
      </label>
      <select
        v-model="form.method"
        class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
      >
        <option value="GET">GET</option>
        <option value="POST">POST</option>
        <option value="PUT">PUT</option>
        <option value="DELETE">DELETE</option>
      </select>
    </div>

    <!-- Crawl interval (link only) -->
    <div v-if="form.type === 'link'" class="relative">
      <label class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1">
        {{ $t('widgets.detail.nodeEditor.crawlInterval') }}
      </label>
      <select
        v-model="form.crawlInterval"
        class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
      >
        <option value="never">{{ $t('widgets.detail.nodeEditor.crawlNever') }}</option>
        <option value="daily">{{ $t('widgets.detail.nodeEditor.crawlDaily') }}</option>
        <option value="weekly">{{ $t('widgets.detail.nodeEditor.crawlWeekly') }}</option>
        <option value="monthly">{{ $t('widgets.detail.nodeEditor.crawlMonthly') }}</option>
      </select>
    </div>

    <!-- Actions -->
    <div class="flex justify-end gap-2 pt-1">
      <button
        class="px-3 py-1.5 rounded-lg text-xs font-medium txt-secondary hover:txt-primary transition-colors"
        @click="emit('cancel')"
      >
        {{ $t('widgets.detail.wizard.cancel') }}
      </button>
      <button
        :disabled="!form.label.trim()"
        class="px-4 py-1.5 rounded-lg text-xs font-medium bg-[var(--brand)] text-white hover:opacity-90 transition-opacity disabled:opacity-30"
        @click="save"
      >
        {{ $t('widgets.detail.nodeEditor.save') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, nextTick, computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

type ResponseType = 'link' | 'api' | 'text' | 'list' | 'pdf' | 'custom'

interface FlowNode {
  id: string
  label: string
  type?: ResponseType
  meta?: { url?: string; method?: string; crawlInterval?: string }
}

const props = defineProps<{
  node: FlowNode
  nodeType: 'trigger' | 'response'
}>()

const emit = defineEmits<{
  save: [node: FlowNode]
  cancel: []
}>()

const { t } = useI18n()
const auth = useAuthStore()
const isPro = computed(() => auth.isPro)
const nameInputRef = ref<HTMLInputElement | null>(null)

const responseTypes: Array<{ key: ResponseType; icon: string; requiresPro?: boolean }> = [
  { key: 'text', icon: 'heroicons:document-text' },
  { key: 'link', icon: 'heroicons:globe-alt', requiresPro: true },
  { key: 'api', icon: 'heroicons:server-stack', requiresPro: true },
  { key: 'list', icon: 'heroicons:list-bullet' },
  { key: 'pdf', icon: 'heroicons:document-arrow-down' },
  { key: 'custom', icon: 'heroicons:sparkles' },
]

const splitNodeLabel = (label: string): { title: string; content: string } => {
  const colonIdx = label.indexOf(':')
  if (colonIdx > 0 && colonIdx < 40) {
    return {
      title: label.substring(0, colonIdx).trim(),
      content: label.substring(colonIdx + 1).trim(),
    }
  }
  return { title: label, content: '' }
}

const { title, content } = splitNodeLabel(props.node.label)

const form = reactive({
  label: title,
  content: content,
  type: (props.node.type ?? 'text') as ResponseType,
  url: props.node.meta?.url ?? '',
  method: props.node.meta?.method ?? 'GET',
  crawlInterval: props.node.meta?.crawlInterval ?? 'never',
})

const contentPlaceholder = computed(() => {
  if (props.nodeType === 'trigger') return t('widgets.detail.nodeEditor.triggerContentPlaceholder')
  if (form.type === 'list') return t('widgets.detail.nodeEditor.listPlaceholder')
  return t('widgets.detail.nodeEditor.textPlaceholder')
})

const save = () => {
  const label = form.label.trim()
  if (!label) return

  const fullLabel = form.content.trim() ? `${label}: ${form.content.trim()}` : label

  const node: FlowNode = {
    id: props.node.id,
    label: fullLabel,
  }

  if (props.nodeType === 'response') {
    node.type = form.type

    if (form.type === 'link' || form.type === 'api') {
      node.meta = { url: form.url.trim() || undefined }
      if (form.type === 'api') {
        node.meta.method = form.method
      }
      if (form.type === 'link' && form.crawlInterval !== 'never') {
        node.meta.crawlInterval = form.crawlInterval
      }
    }
  }

  emit('save', node)
}

onMounted(() => {
  nextTick(() => nameInputRef.value?.focus())
})
</script>
