<template>
  <section id="chat" class="space-y-6 scroll-mt-24">
    <WebSearchProviderSetting />
    <ProfileMemoriesSection v-show="!profileLoadFailed" />
  </section>
</template>

<script setup lang="ts">
import WebSearchProviderSetting from '@/components/settings/WebSearchProviderSetting.vue'
import ProfileMemoriesSection from '@/components/settings/ProfileMemoriesSection.vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const { profileLoadFailed } = injectProfileSettings()
</script>
