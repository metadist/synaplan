<template>
  <article class="max-w-2xl mx-auto px-4 py-8 space-y-8" data-testid="page-using-ai-well">
    <header class="space-y-2">
      <h1 class="text-2xl font-semibold txt-primary">{{ t('pageTitles.usingAiWell') }}</h1>
      <p class="text-sm txt-secondary">{{ t('people.literacy.ask') }}</p>
    </header>
    <section v-for="(section, index) in page" :key="section.title" class="space-y-2">
      <h2 class="text-lg font-medium txt-primary">{{ index + 1 }}. {{ section.title }}</h2>
      <p class="text-sm txt-primary leading-6">{{ section.body }}</p>
    </section>
    <button
      type="button"
      class="btn-primary px-4 py-2.5 text-sm font-medium"
      data-testid="btn-literacy-done"
      @click="done"
    >
      {{ t('people.literacy.done') }}
    </button>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { sections as en } from '@/content/ai-literacy/en'
import { sections as de } from '@/content/ai-literacy/de'
import { sections as es } from '@/content/ai-literacy/es'
import { sections as fr } from '@/content/ai-literacy/fr'
import { sections as tr } from '@/content/ai-literacy/tr'
import { confirmAiLiteracy } from '@/services/api/aiLiteracyApi'

const { t, locale } = useI18n()
const router = useRouter()

const pages: Record<string, typeof en> = { en, de, es, fr, tr }
const page = computed(() => pages[locale.value] ?? en)

async function done(): Promise<void> {
  try {
    await confirmAiLiteracy()
  } catch {
    // The page stays readable when the switch is off.
  }
  await router.push({ name: 'chat' })
}
</script>
