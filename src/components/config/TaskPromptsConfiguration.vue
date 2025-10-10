<template>
  <div class="space-y-6">
    <div class="surface-card p-6">
      <h2 class="text-2xl font-semibold txt-primary mb-6">
        {{ $t('config.taskPrompts.title') }}
      </h2>

      <div class="space-y-5">
        <div>
          <label class="block text-sm font-semibold txt-primary mb-2">
            {{ $t('config.taskPrompts.selectPrompt') }}
          </label>
          <select
            v-model="selectedPromptId"
            @change="loadPrompt"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
          >
            <option
              v-for="prompt in prompts"
              :key="prompt.id"
              :value="prompt.id"
            >
              {{ prompt.name }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('config.taskPrompts.selectPromptHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-semibold txt-primary mb-2">
            {{ $t('config.taskPrompts.rulesForSelection') }}
          </label>
          <textarea
            v-model="currentPrompt.rules"
            rows="3"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
            :placeholder="$t('config.taskPrompts.rulesHelp')"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('config.taskPrompts.rulesHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-semibold txt-primary mb-2">
            {{ $t('config.taskPrompts.saveAsNew') }}
          </label>
          <div class="flex gap-2">
            <input
              v-model="newPromptName"
              type="text"
              class="flex-1 px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              :placeholder="$t('config.taskPrompts.saveAsNewPlaceholder')"
            />
            <button
              @click="saveAsNew"
              :disabled="!newPromptName.trim()"
              class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
            >
              <PlusIcon class="w-4 h-4 inline mr-1" />
              {{ $t('config.taskPrompts.saveAsNewButton') }}
            </button>
          </div>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('config.taskPrompts.saveAsNewHelp') }}
          </p>
        </div>

        <div class="flex gap-3">
          <button
            @click="saveChanges"
            class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2"
          >
            <CheckIcon class="w-5 h-5" />
            {{ $t('config.taskPrompts.saveChanges') }}
          </button>
          <button
            @click="deletePrompt"
            :disabled="currentPrompt.isDefault"
            class="px-6 py-2.5 rounded-lg border border-red-500/30 text-red-500 hover:bg-red-500/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
          >
            <TrashIcon class="w-5 h-5" />
            {{ $t('config.taskPrompts.deletePrompt') }}
          </button>
        </div>
      </div>
    </div>

    <div class="surface-card p-6">
      <h3 class="text-xl font-semibold txt-primary mb-4">
        {{ $t('config.taskPrompts.configuration') }}
      </h3>

      <div class="space-y-5">
        <div>
          <label class="block text-sm font-semibold txt-primary mb-2">
            {{ $t('config.taskPrompts.aiModel') }}
          </label>
          <select
            v-model="currentPrompt.aiModel"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option value="AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]">
              AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]
            </option>
            <option
              v-for="model in availableModels"
              :key="model.id"
              :value="`${model.name} (${model.service})`"
            >
              {{ model.name }} ({{ model.service }})
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('config.taskPrompts.aiModelHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-semibold txt-primary mb-3">
            {{ $t('config.taskPrompts.availableTools') }}
          </label>
          <div class="flex flex-wrap gap-4">
            <label
              v-for="tool in availableToolsList"
              :key="tool.value"
              class="flex items-center gap-2 cursor-pointer"
            >
              <input
                v-model="currentPrompt.availableTools"
                type="checkbox"
                :value="tool.value"
                class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
              />
              <span class="text-sm txt-primary">{{ tool.label }}</span>
            </label>
          </div>
          <p class="text-xs txt-secondary mt-2">
            {{ $t('config.taskPrompts.availableToolsHelp') }}
          </p>
        </div>
      </div>
    </div>

    <div class="surface-card p-6">
      <h3 class="text-xl font-semibold txt-primary mb-4">
        {{ $t('config.taskPrompts.promptContent') }}
      </h3>

      <div class="border border-light-border/30 dark:border-dark-border/20 rounded-lg overflow-hidden">
        <div class="flex items-center gap-1 p-2 border-b border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5">
          <button
            @click="insertMarkdown('**', '**')"
            class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary"
            title="Bold"
          >
            <span class="font-bold text-sm">B</span>
          </button>
          <button
            @click="insertMarkdown('*', '*')"
            class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary"
            title="Italic"
          >
            <span class="italic text-sm">I</span>
          </button>
          <button
            @click="insertMarkdown('# ', '')"
            class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary"
            title="Heading"
          >
            <span class="font-bold text-sm">H</span>
          </button>
          <div class="w-px h-6 bg-light-border/30 dark:bg-dark-border/20 mx-1"></div>
          <button
            @click="insertMarkdown('`', '`')"
            class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary font-mono text-sm"
            title="Code"
          >
            &lt;/&gt;
          </button>
          <button
            @click="insertMarkdown('- ', '')"
            class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary"
            title="List"
          >
            <ListBulletIcon class="w-4 h-4" />
          </button>
          <button
            @click="insertMarkdown('[', '](url)')"
            class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-secondary"
            title="Link"
          >
            <LinkIcon class="w-4 h-4" />
          </button>
        </div>

        <textarea
          ref="contentTextarea"
          v-model="currentPrompt.content"
          rows="15"
          class="w-full px-4 py-3 surface-card txt-primary text-sm focus:outline-none resize-none font-mono"
          placeholder="Enter your prompt content here..."
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import {
  CheckIcon,
  TrashIcon,
  PlusIcon,
  ListBulletIcon,
  LinkIcon
} from '@heroicons/vue/24/outline'
import type { TaskPrompt } from '@/mocks/prompts'
import { mockTaskPrompts, availableToolsList } from '@/mocks/prompts'
import { mockAvailableModels } from '@/mocks/aiModels'

const prompts = ref<TaskPrompt[]>([...mockTaskPrompts])
const selectedPromptId = ref(prompts.value[0]?.id || '')
const currentPrompt = ref<TaskPrompt>({ ...prompts.value[0] })
const newPromptName = ref('')
const contentTextarea = ref<HTMLTextAreaElement | null>(null)

const availableModels = computed(() => {
  return mockAvailableModels.filter(m => m.purpose === 'chat')
})

const loadPrompt = () => {
  const prompt = prompts.value.find(p => p.id === selectedPromptId.value)
  if (prompt) {
    currentPrompt.value = { ...prompt }
  }
}

const saveChanges = () => {
  const index = prompts.value.findIndex(p => p.id === selectedPromptId.value)
  if (index !== -1) {
    prompts.value[index] = { ...currentPrompt.value }
  }
  console.log('Save changes:', currentPrompt.value)
}

const saveAsNew = () => {
  if (!newPromptName.value.trim()) return
  
  const newPrompt: TaskPrompt = {
    ...currentPrompt.value,
    id: `custom-${Date.now()}`,
    name: newPromptName.value,
    isDefault: false
  }
  
  prompts.value.push(newPrompt)
  selectedPromptId.value = newPrompt.id
  currentPrompt.value = { ...newPrompt }
  newPromptName.value = ''
  
  console.log('Saved as new:', newPrompt)
}

const deletePrompt = () => {
  if (currentPrompt.value.isDefault) return
  
  const index = prompts.value.findIndex(p => p.id === selectedPromptId.value)
  if (index !== -1) {
    prompts.value.splice(index, 1)
    if (prompts.value.length > 0) {
      selectedPromptId.value = prompts.value[0].id
      loadPrompt()
    }
  }
  
  console.log('Deleted prompt')
}

const insertMarkdown = (before: string, after: string) => {
  const textarea = contentTextarea.value
  if (!textarea) return
  
  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const text = currentPrompt.value.content
  const selectedText = text.substring(start, end)
  
  currentPrompt.value.content =
    text.substring(0, start) +
    before +
    selectedText +
    after +
    text.substring(end)
  
  setTimeout(() => {
    textarea.focus()
    textarea.setSelectionRange(start + before.length, end + before.length)
  }, 0)
}

onMounted(() => {
  loadPrompt()
})
</script>

