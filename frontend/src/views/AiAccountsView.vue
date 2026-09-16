<template>
  <MainLayout data-testid="page-ai-accounts">
    <div class="container mx-auto px-6 py-8 max-w-5xl overflow-x-hidden">
      <PageHeader
        :title="$t('aiAccounts.title')"
        :subtitle="$t('aiAccounts.subtitle')"
        icon="heroicons:key"
      />

      <div class="space-y-6">
        <section
          v-if="showHiggsfield"
          id="section-higgsfield"
          class="surface-card p-6"
          data-testid="section-higgsfield"
        >
          <h2 class="text-lg font-semibold txt-primary mb-4">
            {{ $t('aiAccounts.sectionHiggsfield') }}
          </h2>
          <HiggsfieldConnection />
        </section>

        <section
          v-if="showAnthropic"
          id="section-anthropic"
          class="surface-card p-6"
          data-testid="section-anthropic"
        >
          <h2 class="text-lg font-semibold txt-primary mb-4">
            {{ $t('aiAccounts.sectionAnthropic') }}
          </h2>
          <AnthropicByokSection />
        </section>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import AnthropicByokSection from '@/components/config/AnthropicByokSection.vue'
import HiggsfieldConnection from '@/components/config/HiggsfieldConnection.vue'
import {
  isAnthropicAccountsEnabled,
  isHiggsfieldAccountsEnabled,
} from '@/composables/useAiAccounts'

const route = useRoute()
const showHiggsfield = computed(() => isHiggsfieldAccountsEnabled())
const showAnthropic = computed(() => isAnthropicAccountsEnabled())

function scrollToSection() {
  const section = route.query.section
  if (section !== 'higgsfield' && section !== 'anthropic') return
  void nextTick(() => {
    document.getElementById(`section-${section}`)?.scrollIntoView({ block: 'start' })
  })
}

watch(() => route.query.section, scrollToSection, { immediate: true })
</script>
