<template>
  <section v-if="items.length > 0" class="w-full max-w-4xl mx-auto" data-testid="marketing-news">
    <h3 class="text-sm font-semibold txt-secondary uppercase tracking-wide mb-4 text-center">
      {{ $t('marketingNews.heading') }}
    </h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <a
        v-for="item in items"
        :key="item.url"
        :href="item.url"
        target="_blank"
        rel="noopener noreferrer"
        class="marketing-news-card surface-card group flex flex-col overflow-hidden rounded-xl transition-all hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        data-testid="marketing-news-card"
      >
        <div v-if="item.imageUrl" class="marketing-news-cover">
          <img
            :src="item.imageUrl"
            :alt="item.title"
            loading="lazy"
            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
          />
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4">
          <time
            v-if="formattedDate(item.publishedAt)"
            class="text-xs txt-secondary"
            :datetime="item.publishedAt ?? undefined"
          >
            {{ formattedDate(item.publishedAt) }}
          </time>

          <h4 class="text-base font-semibold txt-primary leading-snug line-clamp-2">
            {{ item.title }}
          </h4>

          <p v-if="item.excerpt" class="text-sm txt-secondary line-clamp-3 flex-1">
            {{ item.excerpt }}
          </p>

          <div v-if="item.tags.length" class="flex flex-wrap gap-1.5 pt-1">
            <span
              v-for="tag in item.tags"
              :key="tag"
              class="surface-chip px-2 py-0.5 text-xs txt-secondary"
            >
              {{ tag }}
            </span>
          </div>

          <span
            class="mt-2 inline-flex items-center gap-1 text-sm font-medium txt-brand"
            aria-hidden="true"
          >
            {{ $t('marketingNews.readMore') }}
            <Icon icon="heroicons:arrow-right" class="h-4 w-4" />
          </span>
        </div>
      </a>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import { useDateFormat } from '@/composables/useDateFormat'
import { getLandingNews, type MarketingNewsItem } from '@/services/api/newsApi'

const { locale } = useI18n()
const { formatDate } = useDateFormat()
const configStore = useConfigStore()

const items = ref<MarketingNewsItem[]>([])

const formattedDate = (value?: string | null): string => {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return formatDate(date)
}

onMounted(async () => {
  // Defence in depth: never fetch when the admin master switch is off.
  if (!configStore.marketingNews.enabled) return

  try {
    items.value = await getLandingNews(locale.value)
  } catch {
    items.value = []
  }
})
</script>

<style scoped>
.marketing-news-cover {
  aspect-ratio: 16 / 9;
  overflow: hidden;
  background-color: var(--bg-secondary);
}
</style>
