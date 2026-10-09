<template>
  <div class="space-y-6" data-testid="panel-app-claude-code">
    <MessagesGatewayConfiguration />
    <section id="anthropic-key" class="surface-card p-6" data-testid="section-anthropic">
      <h2 class="text-lg font-semibold txt-primary mb-1">{{ $t('apps.claudeCode.keyTitle') }}</h2>
      <p class="text-sm txt-secondary mb-4">{{ $t('apps.claudeCode.keyHint') }}</p>
      <AnthropicByokSection />
    </section>
  </div>
</template>

<script setup lang="ts">
import MessagesGatewayConfiguration from '@/components/config/MessagesGatewayConfiguration.vue'
import AnthropicByokSection from '@/components/config/AnthropicByokSection.vue'
</script>
