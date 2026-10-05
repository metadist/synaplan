<template>
  <div data-testid="section-files-header">
    <PageHeader :title="$t('nav.files')" icon="heroicons:folder-open">
      <!-- §4.8 #1: one vocabulary — say what the chat input already implies. -->
      <template #subtitle>{{ $t('files.intro') }} {{ $t('files.introCta') }}</template>
    </PageHeader>
  </div>
</template>

<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
</script>
