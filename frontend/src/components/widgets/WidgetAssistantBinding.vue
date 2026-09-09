<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-widget-assistant">
    <h3 class="txt-primary font-medium">{{ $t('widget.useAssistant.title') }}</h3>
    <p class="txt-secondary text-sm">{{ $t('widget.useAssistant.hint') }}</p>

    <div v-if="selected" class="space-y-3" data-testid="state-widget-assistant-bound">
      <p class="txt-primary text-sm">{{ selected.name }}</p>
      <p class="txt-secondary text-sm">
        {{ $t('widget.useAssistant.summary', { version: selected.version ?? '—' }) }}
      </p>
      <div class="flex flex-wrap gap-2">
        <RouterLink
          :to="{ name: 'ai-assistants', query: { id: String(selected.id) } }"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center"
        >
          {{ $t('widget.useAssistant.open') }}
        </RouterLink>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-stop-using-assistant"
          @click="onStop"
        >
          {{ $t('widget.useAssistant.stop') }}
        </button>
      </div>
    </div>

    <label v-else class="block">
      <span class="txt-secondary text-sm">{{ $t('widget.useAssistant.choose') }}</span>
      <select
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="select-widget-assistant"
        :value="''"
        @change="onPick(Number(($event.target as HTMLSelectElement).value))"
      >
        <option value="">{{ $t('widget.useAssistant.writeHere') }}</option>
        <option v-for="card in published" :key="card.id" :value="card.id">
          {{ card.name }}
        </option>
      </select>
    </label>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { agentsApi, type GalleryCard } from '@/services/api/agentsApi'
import { updateWidget, type Widget } from '@/services/api/widgetsApi'

const props = defineProps<{
  widget: Widget
}>()

const emit = defineEmits<{
  updated: [agentId: number | null]
}>()

const { t } = useI18n()
const { confirm } = useDialog()
const { error, success } = useNotification()
const cards = ref<GalleryCard[]>([])

const published = computed(() =>
  cards.value.filter(
    (card) => card.status === 'published' && (card.origin === 'mine' || card.origin === 'shared')
  )
)
const selected = computed(
  () => published.value.find((card) => card.id === props.widget.agentId) ?? null
)

onMounted(async () => {
  try {
    cards.value = await agentsApi.gallery()
  } catch {
    cards.value = []
  }
})

async function onPick(id: number): Promise<void> {
  if (!id) {
    return
  }
  try {
    await updateWidget(props.widget.widgetId, { agentId: id })
    emit('updated', id)
    success(t('widget.useAssistant.saved'))
  } catch {
    error(t('widget.useAssistant.failed'))
  }
}

async function onStop(): Promise<void> {
  const ok = await confirm({
    title: t('widget.useAssistant.stop'),
    message: t('widget.useAssistant.stopConfirm'),
  })
  if (!ok) {
    return
  }
  try {
    await updateWidget(props.widget.widgetId, { agentId: null })
    emit('updated', null)
    success(t('widget.useAssistant.stopped'))
  } catch {
    error(t('widget.useAssistant.failed'))
  }
}
</script>
