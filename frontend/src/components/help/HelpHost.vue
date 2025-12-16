<template>
  <div v-if="isEnabled" data-testid="comp-help-host">
    <HelpButton v-if="currentHelp" @click="openHelp" />
    <HelpTour :show="isOpen" :steps="currentHelp?.steps || []" @close="closeHelp" />
  </div>
</template>

<script setup lang="ts">
import HelpButton from './HelpButton.vue'
import HelpTour from './HelpTour.vue'
import { useHelp } from '@/composables/useHelp'

const { isEnabled, isOpen, currentHelp, openHelp, closeHelp } = useHelp()
</script>
