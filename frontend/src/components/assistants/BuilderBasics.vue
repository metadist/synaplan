<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-basics">
    <h2 class="txt-primary font-medium">{{ $t('assistants.basics') }}</h2>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.name') }}</span>
      <input
        :value="name"
        type="text"
        class="mt-1 w-full"
        :placeholder="$t('assistants.namePlaceholder')"
        data-testid="input-assistant-name"
        @input="patchName(($event.target as HTMLInputElement).value)"
      />
      <p v-if="errorFor('name')" class="text-sm text-[var(--danger)] mt-1" data-testid="error-name">
        {{ errorFor('name') }}
      </p>
    </label>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.description') }}</span>
      <textarea
        :value="description"
        rows="3"
        class="mt-1 w-full"
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
        class="mt-1 w-full"
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
          class="flex-1"
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
import { useAgentsStore } from '@/stores/agents'
import { asRecord, asStringList } from './draftHelpers'

const store = useAgentsStore()

const name = computed(() => store.current?.name ?? '')
const description = computed(() => store.current?.description ?? '')
const behaviour = computed(() => asRecord(asRecord(store.current?.draft).behaviour))
const greeting = computed(() =>
  typeof behaviour.value.greeting === 'string' ? behaviour.value.greeting : ''
)
const starterPrompts = computed(() => asStringList(behaviour.value.starterPrompts))

function errorFor(path: string): string | undefined {
  return store.fieldErrors[path]
}

function mutateDraft(patchBehaviour: Record<string, unknown>): void {
  if (!store.current) {
    return
  }
  const draft = asRecord(store.current.draft)
  store.current.draft = {
    ...draft,
    behaviour: { ...asRecord(draft.behaviour), memory: 'user', ...patchBehaviour },
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
