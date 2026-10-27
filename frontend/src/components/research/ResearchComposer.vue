<template>
  <div
    v-if="composerOpen"
    class="fixed inset-x-0 bottom-0 z-50 px-3 pb-3"
    data-testid="section-research-composer"
  >
    <form
      class="mx-auto w-full max-w-3xl surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 p-3 flex flex-col gap-3 max-h-[70vh] overflow-y-auto"
      @submit.prevent="submit"
    >
      <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-medium txt-primary">{{ $t('research.title') }}</h2>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-research-close"
          @click="research.closeComposer()"
        >
          {{ $t('research.close') }}
        </button>
      </div>

      <label class="flex flex-col gap-1">
        <span class="text-sm txt-primary">{{ $t('research.questionLabel') }}</span>
        <textarea
          v-model="question"
          rows="3"
          class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="input-research-question"
        />
      </label>

      <fieldset class="flex flex-col gap-2">
        <legend class="text-sm txt-primary">{{ $t('research.depthLabel') }}</legend>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="choice in depthChoices"
            :key="choice"
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            :class="depth === choice && 'ring-2 ring-[var(--brand)]'"
            :aria-pressed="depth === choice"
            :data-testid="`btn-research-depth-${choice}`"
            @click="depth = choice"
          >
            <span class="block">{{ $t(`research.depth.${choice}`) }}</span>
            <span class="block text-xs txt-secondary">{{
              $t(`research.depthHint.${choice}`, { minutes: choice === 'thorough' ? 15 : 5 })
            }}</span>
          </button>
        </div>
      </fieldset>

      <fieldset class="flex flex-col gap-2">
        <legend class="text-sm txt-primary">{{ $t('research.sourcesLabel') }}</legend>
        <p v-if="!webAvailable && !filesAvailable" class="text-sm txt-secondary">
          {{ $t('research.noSources') }}
        </p>
        <div v-else class="flex flex-wrap gap-2">
          <button
            v-if="webAvailable"
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            :aria-pressed="web"
            data-testid="btn-research-source-web"
            @click="web = !web"
          >
            {{ $t('research.sourceWeb') }}
          </button>
          <button
            v-if="filesAvailable"
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            :aria-pressed="files"
            data-testid="btn-research-source-files"
            @click="files = !files"
          >
            {{ $t('research.sourceFiles') }}
          </button>
        </div>
      </fieldset>

      <p class="text-sm txt-secondary" data-testid="text-research-continues">
        {{ $t('research.continues') }}
      </p>

      <div>
        <button
          type="submit"
          class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!canStart"
          data-testid="btn-research-start"
        >
          {{ $t('research.start') }}
        </button>
        <p
          v-if="reasonKey"
          class="mt-2 text-sm txt-secondary"
          data-testid="text-research-start-reason"
        >
          {{ $t(`research.${reasonKey}`) }}
        </p>
        <p
          v-if="research.startError"
          class="mt-2 text-sm text-red-600 dark:text-red-400"
          data-testid="text-research-start-error"
        >
          {{ $t(`research.${research.startError}`) }}
        </p>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useResearchStore } from '@/stores/research'

const research = useResearchStore()
const { composerOpen, capabilities, starting } = storeToRefs(research)

const question = ref('')
const depth = ref<'standard' | 'thorough'>('standard')
const web = ref(true)
const files = ref(false)

const depthChoices = computed(() => {
  const offered = capabilities.value?.depths ?? []
  return (['standard', 'thorough'] as const).filter((choice) => offered.includes(choice))
})

const webAvailable = computed(() => capabilities.value?.web_search_available === true)
const filesAvailable = computed(() => capabilities.value?.knowledge_available === true)

watch(depthChoices, (choices) => {
  if (choices.length > 0 && !choices.includes(depth.value)) depth.value = choices[0]
})

watch(webAvailable, (on) => {
  if (!on) web.value = false
})

watch(filesAvailable, (on) => {
  if (!on) files.value = false
  else if (!webAvailable.value) files.value = true
})

const sourceOn = computed(
  () => (webAvailable.value && web.value) || (filesAvailable.value && files.value)
)

const reasonKey = computed(() => {
  if (question.value.trim() === '') return 'startNeedsQuestion'
  if (!sourceOn.value) {
    return webAvailable.value || filesAvailable.value ? 'startNeedsSource' : 'noSources'
  }
  return null
})

const canStart = computed(() => reasonKey.value === null && !starting.value)

function submit(): void {
  if (!canStart.value) return
  void research.begin({
    question: question.value.trim(),
    depth: depth.value,
    web: webAvailable.value && web.value,
    knowledge: files.value,
  })
}
</script>
