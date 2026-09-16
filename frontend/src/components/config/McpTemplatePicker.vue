<template>
  <div data-testid="mcp-template-picker">
    <p class="text-sm font-medium txt-primary mb-1">{{ $t('mcpServers.templatesLabel') }}</p>
    <p class="text-xs txt-secondary leading-relaxed mb-3">{{ $t('mcpServers.templatesHint') }}</p>

    <div
      class="grid grid-cols-1 sm:grid-cols-2 gap-2"
      role="radiogroup"
      :aria-label="$t('mcpServers.templatesLabel')"
    >
      <button
        v-for="template in templates"
        :key="template.key"
        type="button"
        role="radio"
        :aria-checked="modelValue === template.key"
        class="text-left rounded-xl px-3 py-3 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        :class="
          modelValue === template.key
            ? 'bg-[var(--brand-alpha-light)] ring-2 ring-[var(--brand)]'
            : 'surface-card ring-1 ring-black/[0.06] dark:ring-white/[0.1] hover:bg-black/[0.03] dark:hover:bg-white/[0.05]'
        "
        :data-testid="`btn-mcp-template-${template.key}`"
        @click="onSelect(template.key)"
      >
        <span class="flex items-start gap-3">
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-alpha-light)]"
          >
            <Icon :icon="template.icon" class="w-4 h-4 text-[var(--brand)]" aria-hidden="true" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
              <span class="text-sm font-medium txt-primary">
                {{ $t(`mcpServers.templates.${template.key}.name`) }}
              </span>
              <Icon
                v-if="modelValue === template.key"
                icon="heroicons:check-circle"
                class="w-4 h-4 text-[var(--brand)] shrink-0"
                aria-hidden="true"
              />
            </span>
            <span class="block text-xs txt-secondary mt-0.5 leading-relaxed">
              {{ $t(`mcpServers.templates.${template.key}.summary`) }}
            </span>
            <span
              v-if="modelValue === template.key && template.key !== MCP_CUSTOM_TEMPLATE"
              class="block text-xs txt-brand mt-1"
            >
              {{ $t('mcpServers.templatesClear') }}
            </span>
          </span>
        </span>
      </button>
    </div>

    <p
      v-if="hint"
      class="text-xs txt-secondary mt-3 leading-relaxed"
      data-testid="mcp-template-hint"
    >
      {{ hint }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  MCP_CUSTOM_TEMPLATE,
  nextMcpServerTemplate,
  visibleMcpServerTemplates,
} from '@/config/mcpServerTemplates'

const props = defineProps<{
  modelValue: string
  oauthEnabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [key: string]
}>()

const { t } = useI18n()

const templates = computed(() => visibleMcpServerTemplates(props.oauthEnabled === true))

const hint = computed(() => t(`mcpServers.templates.${props.modelValue}.hint`))

const onSelect = (key: string) => {
  emit('update:modelValue', nextMcpServerTemplate(props.modelValue, key))
}
</script>
