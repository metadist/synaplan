<template>
  <div class="rounded-lg overflow-hidden border border-light-border/30 dark:border-dark-border/20" data-testid="comp-message-code">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-2 bg-black/5 dark:bg-white/5 border-b border-light-border/30 dark:border-dark-border/20" data-testid="section-header">
      <span class="txt-tertiary text-xs font-medium font-mono">
        {{ language || 'code' }}
      </span>
      
      <button
        @click="copyCode"
        class="flex items-center gap-1.5 px-2 py-1 rounded txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-all text-xs"
        data-testid="btn-copy"
      >
        <CheckIcon v-if="copied" class="w-3.5 h-3.5 text-green-500" />
        <ClipboardDocumentIcon v-else class="w-3.5 h-3.5" />
        <span>{{ copied ? 'Copied!' : 'Copy' }}</span>
      </button>
    </div>
    
    <!-- Code Content -->
    <div class="relative">
      <pre class="p-4 overflow-x-auto text-sm font-mono txt-primary bg-black/[0.02] dark:bg-white/[0.02]"><code>{{ code }}</code></pre>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { ClipboardDocumentIcon, CheckIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  code: string
  language?: string
}>()

const copied = ref(false)

const copyCode = async () => {
  try {
    await navigator.clipboard.writeText(props.code)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy code:', err)
  }
}
</script>
