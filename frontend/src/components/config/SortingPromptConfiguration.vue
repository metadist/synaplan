<template>
  <div class="space-y-6" data-testid="page-config-sorting-prompt">
    <div class="surface-card p-6" data-testid="section-overview">
      <h2 class="text-2xl font-semibold txt-primary mb-3">
        {{ $t('config.sortingPrompt.title') }}
      </h2>
      <p class="txt-secondary text-sm mb-2">
        {{ $t('config.sortingPrompt.description') }}
      </p>
      <p class="text-sm txt-secondary">
        See the
        <router-link 
          to="/config/task-prompts" 
          class="text-[var(--brand)] hover:underline font-medium"
        >
          Prompt Editor
        </router-link>
        {{ $t('config.sortingPrompt.promptEditorLink') }}.
      </p>
    </div>

    <div class="surface-card overflow-hidden" data-testid="section-tabs">
      <div class="flex border-b border-light-border/30 dark:border-dark-border/20">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'px-6 py-3 text-sm font-medium transition-colors relative',
            activeTab === tab.id
              ? 'txt-primary bg-[var(--brand)]/5 border-b-2 border-[var(--brand)]'
              : 'txt-secondary hover:bg-black/5 dark:hover:bg-white/5'
          ]"
          data-testid="btn-tab"
        >
          {{ tab.label }}
        </button>
      </div>

      <div class="p-6">
        <div v-if="activeTab === 'rendered'" data-testid="section-rendered">
          <div class="space-y-6">
            <div>
              <h3 class="text-xl font-semibold txt-primary mb-3">
                {{ $t('config.sortingPrompt.mainTitle') }}
              </h3>
              <p class="txt-secondary text-sm mb-4">
                {{ $t('config.sortingPrompt.mainDescription') }}
              </p>
              <p class="txt-secondary text-sm italic">
                If it fits the previous requests of the last few minutes, keep the topic going. If not, change it accordingly. Only in the JSON field.
              </p>
              <p class="txt-secondary text-sm font-medium mt-2">
                Put answers only in JSON, please.
              </p>
            </div>

            <div>
              <h4 class="text-lg font-semibold txt-primary mb-3">
                {{ $t('config.sortingPrompt.yourTasks') }}
              </h4>
              <p class="txt-secondary text-sm mb-4">
                {{ sortingPrompt.tasks }}
              </p>
              <p class="txt-secondary text-sm mb-4">
                You receive messages (as JSON objects) from random users around the world. If there is a signature in the BTEXT field, use it as a hint to classify the message and the sender.
              </p>
              <p class="txt-secondary text-sm mb-4">
                If there is an attachment, the description is in the BFILETEXT field.
              </p>
              <p class="txt-secondary text-sm font-medium">
                You will respond only in valid JSON and with the same structure you receive.
              </p>
            </div>

            <div>
              <h4 class="text-lg font-semibold txt-primary mb-3">
                Your tasks in every new message are to:
              </h4>
              <ol class="space-y-4 txt-secondary text-sm">
                <li class="flex gap-3">
                  <span class="font-semibold txt-primary">1.</span>
                  <span>{{ sortingPrompt.instructions[0] }}</span>
                </li>
                <li class="flex gap-3">
                  <span class="font-semibold txt-primary">2.</span>
                  <div>
                    <p class="mb-3">{{ sortingPrompt.instructions[1] }}. The most common is "general". This is the list, use only this:</p>
                    <ul class="space-y-3 ml-4">
                      <li
                        v-for="category in sortingPrompt.categories"
                        :key="category.name"
                        class="pl-4 border-l-2 border-[var(--brand)]/30"
                      >
                        <div class="flex items-center gap-2 mb-1">
                          <span class="font-semibold txt-primary">{{ category.name }}</span>
                          <span v-if="category.type === 'default'" class="text-xs txt-secondary">(default)</span>
                          <span v-else class="text-xs text-purple-500">(custom)</span>
                        </div>
                        <p class="text-sm txt-secondary">{{ category.description }}</p>
                      </li>
                    </ul>
                  </div>
                </li>
                <li class="flex gap-3">
                  <span class="font-semibold txt-primary">3.</span>
                  <span>{{ sortingPrompt.instructions[2] }}.</span>
                </li>
                <li class="flex gap-3">
                  <span class="font-semibold txt-primary">4.</span>
                  <span>{{ sortingPrompt.instructions[3] }}.</span>
                </li>
                <li class="flex gap-3">
                  <span class="font-semibold txt-primary">5.</span>
                  <span>{{ sortingPrompt.instructions[4] }}.</span>
                </li>
              </ol>
            </div>
          </div>
        </div>

        <div v-else-if="activeTab === 'source'" data-testid="section-source">
          <div class="space-y-4">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('config.sortingPrompt.tabSource') }}
              </h3>
              <button
                @click="editMode = !editMode"
                class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors text-sm"
                data-testid="btn-toggle-mode"
              >
                <PencilIcon v-if="!editMode" class="w-4 h-4 inline mr-1" />
                <EyeIcon v-else class="w-4 h-4 inline mr-1" />
                {{ editMode ? 'View Mode' : 'Edit Mode' }}
              </button>
            </div>

            <div v-if="!editMode" class="surface-chip p-6 rounded border border-light-border/30 dark:border-dark-border/20" data-testid="section-prompt-preview">
              <pre class="whitespace-pre-wrap font-mono text-xs txt-primary leading-relaxed">{{ sortingPrompt.promptContent }}</pre>
            </div>

            <textarea
              v-else
              v-model="sortingPrompt.promptContent"
              rows="25"
              class="w-full px-4 py-3 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none font-mono"
              data-testid="input-prompt"
            />

            <div v-if="editMode" class="flex gap-3">
              <button
                @click="savePrompt"
                class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2"
                data-testid="btn-save"
              >
                <CheckIcon class="w-5 h-5" />
                {{ $t('config.sortingPrompt.savePrompt') }}
              </button>
              <button
                @click="resetPrompt"
                class="px-6 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                data-testid="btn-reset"
              >
                {{ $t('config.sortingPrompt.resetPrompt') }}
              </button>
            </div>
          </div>
        </div>

        <div v-else-if="activeTab === 'json'" data-testid="section-json">
          <div class="space-y-4">
            <div>
              <h3 class="text-lg font-semibold txt-primary mb-2">
                {{ $t('config.sortingPrompt.tabJson') }}
              </h3>
              <p class="txt-secondary text-sm mb-3">
                {{ $t('config.sortingPrompt.jsonDescription') }}
              </p>
              <p class="txt-secondary text-sm mb-3">
                {{ $t('config.sortingPrompt.jsonNote') }}
              </p>
              <p class="txt-secondary text-sm font-medium mb-4">
                {{ $t('config.sortingPrompt.jsonExample') }}
              </p>
            </div>

            <div class="bg-black/90 dark:bg-black/50 rounded-lg p-4 font-mono text-sm text-green-400 overflow-x-auto">
              <pre>{{ sortingPrompt.jsonExample }}</pre>
            </div>

            <div class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-lg">
              <p class="text-sm txt-primary">
                <InformationCircleIcon class="w-5 h-5 text-cyan-500 inline mr-2" />
                {{ $t('config.sortingPrompt.btopicNote') }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { 
  PencilIcon, 
  EyeIcon, 
  CheckIcon,
  InformationCircleIcon
} from '@heroicons/vue/24/outline'
import { mockSortingPrompt } from '@/mocks/sortingPrompt'
import type { SortingPromptData } from '@/mocks/sortingPrompt'

const activeTab = ref('rendered')
const editMode = ref(false)
const sortingPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const originalPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })

const tabs = [
  { id: 'rendered', label: 'Rendered Result' },
  { id: 'source', label: 'Prompt Source' },
  { id: 'json', label: 'JSON Object' }
]

const loadSortingPrompt = async () => {
  sortingPrompt.value = { ...mockSortingPrompt }
  originalPrompt.value = { ...mockSortingPrompt }
}

const savePrompt = async () => {
  console.log('Save sorting prompt:', sortingPrompt.value)
  originalPrompt.value = { ...sortingPrompt.value }
  editMode.value = false
}

const resetPrompt = () => {
  sortingPrompt.value = { ...originalPrompt.value }
  editMode.value = false
}

onMounted(() => {
  loadSortingPrompt()
})
</script>

