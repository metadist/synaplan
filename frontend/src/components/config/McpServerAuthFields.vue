<template>
  <fieldset class="space-y-3" data-testid="mcp-auth-fields">
    <legend class="text-sm font-medium txt-primary mb-1">
      {{ $t('mcpServers.authMethodLabel') }}
    </legend>

    <p v-if="locked" class="text-sm txt-secondary leading-relaxed" data-testid="mcp-auth-locked">
      <span class="txt-primary font-medium">{{ $t('mcpServers.authMethod.oauth.label') }}</span>
      — {{ $t('mcpServers.authMethodOffByAdmin') }}
    </p>

    <template v-else>
      <label v-for="option in options" :key="option" class="flex items-start gap-2 cursor-pointer">
        <input
          v-model="mode"
          type="radio"
          :value="option"
          class="mt-1 accent-[var(--brand)]"
          :data-testid="`radio-mcp-auth-${option}`"
        />
        <span class="min-w-0">
          <span class="block text-sm txt-primary">
            {{ $t(`mcpServers.authMethod.${option}.label`) }}
          </span>
          <span class="block text-xs txt-secondary leading-relaxed">
            {{ $t(`mcpServers.authMethod.${option}.hint`) }}
          </span>
        </span>
      </label>
    </template>

    <div v-if="mode === 'bearer' && !locked" class="grid sm:grid-cols-2 gap-4 pt-1">
      <label class="block">
        <span class="text-sm font-medium txt-primary">{{ $t('mcpServers.authHeaderLabel') }}</span>
        <input
          v-model="authHeader"
          type="text"
          autocomplete="off"
          placeholder="Authorization"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
          data-testid="input-mcp-auth-header"
        />
      </label>
      <label class="block">
        <span class="text-sm font-medium txt-primary">{{ $t('mcpServers.authTokenLabel') }}</span>
        <input
          v-model="authToken"
          type="password"
          autocomplete="new-password"
          :placeholder="hasSavedToken ? '••••••••' : $t('mcpServers.authTokenPlaceholder')"
          class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
          data-testid="input-mcp-auth-token"
        />
      </label>
    </div>
  </fieldset>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { McpAuthMode } from '@/config/mcpServerTemplates'

const props = defineProps<{
  oauthEnabled: boolean
  /** An existing sign-in row while sign-in connectors are switched off. */
  locked: boolean
  hasSavedToken: boolean
}>()

const mode = defineModel<McpAuthMode>('mode', { required: true })
const authHeader = defineModel<string>('authHeader', { required: true })
const authToken = defineModel<string>('authToken', { required: true })

const options = computed<McpAuthMode[]>(() =>
  props.oauthEnabled ? ['none', 'bearer', 'oauth'] : ['none', 'bearer']
)
</script>
