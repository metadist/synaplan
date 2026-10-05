<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import SectionJumpNav from '@/components/SectionJumpNav.vue'
import ConfigSectionBody from '@/components/admin/ConfigSectionBody.vue'
import { useAccordion } from '@/composables/useAccordion'
import type { ConfigSectionRef } from '@/constants/operateSettings'
import type { ResolvedConfigSection, SystemConfigHandle } from '@/composables/useSystemConfig'

const props = withDefaults(
  defineProps<{
    config: SystemConfigHandle
    sections: readonly ConfigSectionRef[]
    /** Provider-key fields have their editor on the same page: do not report them again. */
    hideManaged?: boolean
    hiddenFields?: readonly string[]
    testid?: string
  }>(),
  {
    hideManaged: false,
    hiddenFields: () => [],
    testid: 'config-accordion',
  }
)

const route = useRoute()
const router = useRouter()

const resolved = computed<ResolvedConfigSection[]>(() =>
  props.sections
    .map((ref) =>
      props.config.resolveSection(ref, {
        hideManaged: props.hideManaged,
        hiddenFields: props.hiddenFields,
      })
    )
    .filter((section): section is ResolvedConfigSection => section !== null)
)

const sectionIds = computed(() => resolved.value.map((section) => section.id))
const { isOpen, toggle, open, expandAll, collapseAll, allOpen } = useAccordion(sectionIds)
const showToolbar = computed(() => resolved.value.length > 1)

/** Section a deep link or the jump nav pointed at, ringed until another one is chosen. */
const highlighted = ref<string | null>(null)

async function reveal(sectionId: string): Promise<void> {
  open(sectionId)
  highlighted.value = sectionId
  await nextTick()
  const panel = document.getElementById(`config-section-${sectionId}`)
  const field =
    typeof route.query.highlight === 'string'
      ? panel?.querySelector<HTMLElement>(
          `[data-config-field="${CSS.escape(route.query.highlight)}"]`
        )
      : null
  if (field) {
    field.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }
  panel?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function jumpTo(sectionId: string): void {
  if (route.query.section !== sectionId) {
    void router.replace({ query: { ...route.query, section: sectionId } })
  }
  void reveal(sectionId)
}

watch(
  () => [route.query.section, sectionIds.value.join('\0')] as const,
  ([wanted]) => {
    if (typeof wanted === 'string' && sectionIds.value.includes(wanted)) {
      void reveal(wanted)
    }
  },
  { immediate: true }
)
</script>

<template>
  <div
    v-if="config.loading.value && !config.schema.value"
    class="text-center py-8"
    :data-testid="`${testid}-loading`"
  >
    <Icon icon="mdi:loading" class="w-6 h-6 animate-spin mx-auto txt-secondary" />
  </div>
  <div v-else-if="resolved.length > 0" class="space-y-4">
    <div v-if="showToolbar" class="flex items-center justify-between gap-3 flex-wrap">
      <SectionJumpNav
        :items="resolved.map((section) => ({ id: section.id, label: section.label }))"
        :active-id="highlighted"
        :nav-label="$t('admin.config.accordion.jumpTo')"
        @select="jumpTo"
      />
      <button
        type="button"
        class="btn-secondary px-4 py-2 rounded-xl text-sm font-medium"
        :data-testid="`btn-${testid}-toggle-all`"
        @click="allOpen ? collapseAll() : expandAll()"
      >
        {{
          allOpen
            ? $t('admin.config.accordion.collapseAll')
            : $t('admin.config.accordion.expandAll')
        }}
      </button>
    </div>

    <AccordionStack :testid="testid">
      <AccordionSection
        v-for="section in resolved"
        :key="`${section.tab}.${section.id}`"
        :panel-id="`config-section-${section.id}`"
        :title="section.label"
        :open="isOpen(section.id)"
        :highlighted="highlighted === section.id"
        :header-testid="`btn-config-section-${section.id}`"
        @toggle="toggle(section.id)"
      >
        <template #leading>
          <Icon icon="mdi:folder-cog" class="w-5 h-5 txt-secondary flex-shrink-0" />
        </template>
        <template v-if="section.isLive" #badge>
          <span
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-[var(--status-success-muted)] text-[var(--status-success-text)]"
            :title="$t('admin.config.liveHint')"
          >
            <span class="w-1.5 h-1.5 rounded-full bg-[var(--status-success)] animate-pulse" />
            {{ $t('admin.config.liveBadge') }}
          </span>
        </template>
        <ConfigSectionBody :section="section" :config="config" />
      </AccordionSection>
    </AccordionStack>
  </div>
</template>
