<template>
  <div class="px-3 pt-3 pb-1">
    <PastedTextCard :content="content" readonly @open="open = true" />
    <PastedTextModal :visible="open" :content="content" readonly @close="open = false" />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import PastedTextCard from '@/components/chat/PastedTextCard.vue'
import PastedTextModal from '@/components/chat/PastedTextModal.vue'

defineProps<{
  content: string
}>()

const open = ref(false)
</script>
