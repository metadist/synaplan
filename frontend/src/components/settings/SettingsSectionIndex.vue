<template>
  <nav
    class="sticky top-0 z-10 -mx-1 bg-chat px-1 py-2"
    :aria-label="$t('settings.sections.indexLabel')"
    data-testid="nav-settings-sections"
  >
    <ul class="flex flex-wrap gap-2">
      <li v-for="item in items" :key="item.id">
        <a
          :href="`#${item.id}`"
          class="inline-flex rounded-lg px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
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
  if (route.hash === '#app') return '#app-server'
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
