<template>
  <nav
    class="md:w-56 md:shrink-0 md:sticky md:top-4 md:self-start"
    :aria-label="$t('settings.sections.indexLabel')"
    data-testid="nav-settings-sections"
  >
    <ul class="flex gap-2 overflow-x-auto pb-1 md:flex-col md:overflow-visible md:pb-0">
      <li v-for="item in items" :key="item.id" class="shrink-0 md:shrink">
        <a
          :href="`#${item.id}`"
          class="inline-flex px-3 py-2 rounded-lg text-sm font-medium whitespace-nowrap md:whitespace-normal md:w-full focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :class="
            activeSectionId() === `#${item.id}`
              ? 'txt-primary bg-[var(--brand-alpha-light)]'
              : 'txt-secondary hover-surface'
          "
          :aria-current="activeSectionId() === `#${item.id}` ? 'location' : undefined"
          :data-testid="`link-settings-${item.id}`"
          @click="goToSection(item.id, $event)"
        >
          {{ $t(item.labelKey) }}
        </a>
      </li>
    </ul>
  </nav>
</template>

<script setup lang="ts">
import { nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'

export interface SettingsSectionLink {
  id: string
  labelKey: string
}

defineProps<{
  items: SettingsSectionLink[]
}>()

const route = useRoute()
const router = useRouter()

function activeSectionId(): string {
  if (route.hash === '#memories') return '#chat'
  return route.hash
}

async function goToSection(id: string, event: MouseEvent) {
  event.preventDefault()
  if (route.hash !== `#${id}`) {
    await router.push({ hash: `#${id}` })
  }
  await nextTick()
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
</script>
