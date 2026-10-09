<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      :data-testid="`page-app-${appId}`"
    >
      <div v-if="app" class="max-w-[64rem] mx-auto">
        <router-link
          to="/apps"
          class="inline-flex items-center gap-1.5 text-sm txt-secondary hover:txt-primary mb-4"
          data-testid="link-app-back"
        >
          <ArrowLeftIcon class="w-4 h-4" aria-hidden="true" />
          {{ $t('apps.backToAll') }}
        </router-link>

        <PageHeader :title="name" :subtitle="tagline" :tour-id="tourId">
          <template #icon>
            <Icon :icon="app.icon" class="w-5 h-5" />
          </template>
        </PageHeader>

        <dl
          class="surface-card rounded-2xl p-4 sm:p-5 mb-6 grid gap-4 sm:grid-cols-3 text-sm"
          data-testid="section-app-facts"
        >
          <div>
            <dt class="font-medium txt-primary">{{ $t('apps.factWhat') }}</dt>
            <dd class="txt-secondary mt-1">{{ $t(`apps.items.${messageKey}.about`) }}</dd>
          </div>
          <div>
            <dt class="font-medium txt-primary">{{ $t('apps.factWho') }}</dt>
            <dd class="txt-secondary mt-1">{{ $t('apps.ownerOnly') }}</dd>
          </div>
          <div>
            <dt class="font-medium txt-primary">{{ $t('apps.factStop') }}</dt>
            <dd class="txt-secondary mt-1">{{ $t(`apps.items.${messageKey}.stop`) }}</dd>
          </div>
        </dl>

        <AppPanelHost v-if="app.panel" :loader="app.panel" :panel-props="app.panelProps" />
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import AppPanelHost from '@/components/apps/AppPanelHost.vue'
import { appMessageKey, findApp } from '@/apps/catalog'
import { getTour } from '@/tours'

const { t } = useI18n()
const route = useRoute()

const appId = computed(() => String(route.params.appId ?? ''))
const app = computed(() => findApp(appId.value))
const messageKey = computed(() => appMessageKey(appId.value))
const name = computed(() => t(`apps.items.${messageKey.value}.name`))
const tagline = computed(() => t(`apps.items.${messageKey.value}.tagline`))
const tourId = computed(() => (getTour(`app.${appId.value}`) ? `app.${appId.value}` : undefined))
</script>
