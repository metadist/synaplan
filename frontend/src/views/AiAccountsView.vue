<template>
  <MainLayout data-testid="page-ai-accounts">
    <div class="container mx-auto px-6 py-8 max-w-5xl overflow-x-hidden">
      <PageHeader
        :title="$t('aiAccounts.title')"
        :subtitle="$t('aiAccounts.subtitle')"
        icon="heroicons:key"
      />

      <div
        v-if="accountSectionItems.length > 1"
        class="flex items-center justify-between gap-3 flex-wrap mb-4"
      >
        <SectionJumpNav
          :items="accountSectionItems"
          :nav-label="$t('admin.config.accordion.jumpTo')"
          @select="jumpToAccountSection"
        />
        <button
          type="button"
          class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium"
          data-testid="btn-ai-accounts-accordion-toggle-all"
          @click="
            allAccountSectionsOpen ? collapseAllAccountSections() : expandAllAccountSections()
          "
        >
          {{
            allAccountSectionsOpen
              ? $t('admin.config.accordion.collapseAll')
              : $t('admin.config.accordion.expandAll')
          }}
        </button>
      </div>

      <AccordionStack v-if="accountSectionIds.length > 0" testid="ai-accounts-accordion">
        <AccordionSection
          v-if="showHiggsfield"
          panel-id="section-higgsfield"
          testid="section-higgsfield"
          :title="$t('aiAccounts.sectionHiggsfield')"
          :open="isAccountSectionOpen('higgsfield')"
          header-testid="btn-ai-accounts-higgsfield"
          @toggle="toggleAccountSection('higgsfield')"
        >
          <HiggsfieldConnection />
        </AccordionSection>

        <AccordionSection
          v-if="showAnthropic"
          panel-id="section-anthropic"
          testid="section-anthropic"
          :title="$t('aiAccounts.sectionAnthropic')"
          :open="isAccountSectionOpen('anthropic')"
          header-testid="btn-ai-accounts-anthropic"
          @toggle="toggleAccountSection('anthropic')"
        >
          <AnthropicByokSection />
        </AccordionSection>
      </AccordionStack>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import SectionJumpNav from '@/components/SectionJumpNav.vue'
import AnthropicByokSection from '@/components/config/AnthropicByokSection.vue'
import HiggsfieldConnection from '@/components/config/HiggsfieldConnection.vue'
import { useAccordion } from '@/composables/useAccordion'
import {
  isAnthropicAccountsEnabled,
  isHiggsfieldAccountsEnabled,
} from '@/composables/useAiAccounts'

const { t } = useI18n()
const route = useRoute()
const showHiggsfield = computed(() => isHiggsfieldAccountsEnabled())
const showAnthropic = computed(() => isAnthropicAccountsEnabled())
const accountSectionIds = computed(() => {
  const ids: string[] = []
  if (showHiggsfield.value) ids.push('higgsfield')
  if (showAnthropic.value) ids.push('anthropic')
  return ids
})
const accountSectionItems = computed(() =>
  accountSectionIds.value.map((id) => ({
    id,
    label:
      id === 'higgsfield' ? t('aiAccounts.sectionHiggsfield') : t('aiAccounts.sectionAnthropic'),
  }))
)
const {
  isOpen: isAccountSectionOpen,
  toggle: toggleAccountSection,
  open: openAccountSection,
  expandAll: expandAllAccountSections,
  collapseAll: collapseAllAccountSections,
  allOpen: allAccountSectionsOpen,
} = useAccordion(accountSectionIds)

async function jumpToAccountSection(id: string) {
  openAccountSection(id)
  await nextTick()
  document.getElementById(`section-${id}`)?.scrollIntoView({
    behavior: 'smooth',
    block: 'start',
  })
}

function scrollToSection() {
  const section = route.query.section
  if (section !== 'higgsfield' && section !== 'anthropic') return
  openAccountSection(section)
  void nextTick(() => {
    document.getElementById(`section-${section}`)?.scrollIntoView({ block: 'start' })
  })
}

// Re-open after the section list settles. A fresh list starts closed, and
// the flags that build it can arrive after the first ?section= read.
watch(
  [() => route.query.section, () => accountSectionIds.value.join('\0')],
  () => scrollToSection(),
  { immediate: true }
)
</script>
