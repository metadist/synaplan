<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-basics">
    <h2 class="txt-primary font-medium">{{ $t('assistants.basics') }}</h2>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.name') }}</span>
      <input
        :value="name"
        type="text"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('assistants.namePlaceholder')"
        data-testid="input-assistant-name"
        @input="patchName(($event.target as HTMLInputElement).value)"
      />
      <p
        v-if="errorFor('name')"
        class="text-sm text-red-600 dark:text-red-400 mt-1"
        data-testid="error-name"
      >
        {{ errorFor('name') }}
      </p>
    </label>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.description') }}</span>
      <textarea
        :value="description"
        rows="3"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('assistants.descriptionPlaceholder')"
        data-testid="input-assistant-description"
        @input="patchDescription(($event.target as HTMLTextAreaElement).value)"
      />
    </label>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.greeting') }}</span>
      <input
        :value="greeting"
        type="text"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('assistants.greetingPlaceholder')"
        data-testid="input-assistant-greeting"
        @input="patchGreeting(($event.target as HTMLInputElement).value)"
      />
    </label>
    <div>
      <p class="txt-secondary text-sm mb-2">{{ $t('assistants.starterPrompts') }}</p>
      <div v-for="(prompt, index) in starterPrompts" :key="index" class="flex gap-2 mb-2">
        <input
          :value="prompt"
          type="text"
          class="flex-1 min-w-0 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('assistants.starterPromptPlaceholder')"
          :data-testid="`input-starter-${index}`"
          @input="patchStarter(index, ($event.target as HTMLInputElement).value)"
        />
      </div>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-add-starter"
        @click="addStarter"
      >
        {{ $t('assistants.addStarter') }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { emptyAgentDraft, type AgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'

const store = useAgentsStore()

const name = computed(() => store.current?.name ?? '')
const description = computed(() => store.current?.description ?? '')
const greeting = computed(() => store.current?.draft?.behaviour.greeting ?? '')
const starterPrompts = computed(() => store.current?.draft?.behaviour.starterPrompts ?? [])

function errorFor(path: string): string | undefined {
  return store.fieldErrors[path]
}

function mutateDraft(patchBehaviour: Partial<AgentDraft['behaviour']>): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    behaviour: { ...draft.behaviour, ...patchBehaviour },
  }
  store.markDirty()
}

function patchName(value: string): void {
  if (!store.current) {
    return
  }
  store.current.name = value
  store.markDirty()
}

function patchDescription(value: string): void {
  if (!store.current) {
    return
  }
  store.current.description = value
  store.markDirty()
}

function patchGreeting(value: string): void {
  mutateDraft({ greeting: value, starterPrompts: starterPrompts.value })
}

function patchStarter(index: number, value: string): void {
  const next = [...starterPrompts.value]
  next[index] = value
  mutateDraft({ greeting: greeting.value, starterPrompts: next })
}

function addStarter(): void {
  mutateDraft({ greeting: greeting.value, starterPrompts: [...starterPrompts.value, ''] })
}
</script>
