<template>
  <div class="space-y-3" data-testid="section-visitor-signing">
    <WidgetUnsignedVisitorNotice :widget-id="widgetId" :unsigned-allowed="unsignedAllowed" />
    <pre
      class="surface-chip rounded-lg p-3 text-xs overflow-x-auto txt-primary font-mono border border-light-border/30 dark:border-dark-border/20"
      data-testid="text-visitor-embed-example"
      >{{ embedExample }}</pre>

    <div>
      <label class="block text-sm font-medium txt-primary mb-1" for="visitor-signing-key">
        {{ t('widgets.advancedConfig.userDataIntegration.signing.title') }}
      </label>
      <p class="text-xs txt-secondary mb-2">
        {{ t('widgets.advancedConfig.userDataIntegration.signing.help') }}
      </p>
      <input
        id="visitor-signing-key"
        :value="signingKey ?? ''"
        :type="revealed ? 'text' : 'password'"
        readonly
        autocomplete="off"
        placeholder="••••••••••••••••"
        :aria-label="t('widgets.advancedConfig.userDataIntegration.signing.keyLabel')"
        class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
        data-testid="input-visitor-signing-key"
      />
      <div class="flex flex-wrap gap-2 mt-2">
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="busy"
          data-testid="btn-visitor-key-toggle"
          @click="toggleReveal"
        >
          <Icon :icon="revealed ? 'heroicons:eye-slash' : 'heroicons:eye'" class="w-4 h-4" />
          {{
            revealed
              ? t('widgets.advancedConfig.userDataIntegration.signing.hide')
              : t('widgets.advancedConfig.userDataIntegration.signing.show')
          }}
        </button>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="busy"
          data-testid="btn-visitor-key-copy"
          @click="copyKey"
        >
          <Icon icon="heroicons:clipboard-document" class="w-4 h-4" />
          {{ t('widgets.advancedConfig.userDataIntegration.signing.copy') }}
        </button>
        <button
          type="button"
          class="btn-danger px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="busy"
          data-testid="btn-visitor-key-rotate"
          @click="rotateKey"
        >
          <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
          {{ t('widgets.advancedConfig.userDataIntegration.signing.rotate') }}
        </button>
      </div>
    </div>

    <details class="text-xs txt-secondary">
      <summary class="cursor-pointer text-sm font-medium txt-primary">
        {{ t('widgets.advancedConfig.userDataIntegration.signing.exampleTitle') }}
      </summary>
      <p class="mt-2 mb-2">
        {{
          t('widgets.advancedConfig.userDataIntegration.signing.exampleHelp', {
            hours: MAX_LIFETIME_HOURS,
          })
        }}
      </p>
      <pre
        v-for="example in serverExamples"
        :key="example.language"
        class="surface-chip rounded-lg p-3 mb-2 overflow-x-auto txt-primary font-mono border border-light-border/30 dark:border-dark-border/20"
        :data-testid="`text-visitor-signing-${example.language}`"
        >{{ example.code }}</pre>
      <p data-testid="text-visitor-signing-wordpress">
        {{ t('widgets.advancedConfig.userDataIntegration.signing.wordpress') }}
      </p>
    </details>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { getVisitorKey, rotateVisitorKey } from '@/services/api/widgetVisitorApi'
import WidgetUnsignedVisitorNotice from './WidgetUnsignedVisitorNotice.vue'

const MAX_LIFETIME_HOURS = 24

const props = defineProps<{ widgetId: string; unsignedAllowed?: boolean }>()
const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const signingKey = ref<string | null>(null)
const revealed = ref(false)
const busy = ref(false)

const embedExample = computed(
  () => `SynaplanWidget.init({
  widgetId: '${props.widgetId}',
  externalUserId: '<YOUR_USER_ID>',
  externalUserIdSignature: '<SIGNATURE_FROM_YOUR_SERVER>'
})`
)

const serverExamples = computed(() => [
  {
    language: 'php',
    code: `// PHP
$expiry = time() + 3600;
$mac = hash_hmac('sha256', "${props.widgetId}\\n{$userId}\\n{$expiry}", $signingKey);
$signature = $expiry . '.' . $mac;`,
  },
  {
    language: 'node',
    code: `// Node.js
import { createHmac } from 'node:crypto'

const expiry = Math.floor(Date.now() / 1000) + 3600
const mac = createHmac('sha256', signingKey)
  .update(\`${props.widgetId}\\n\${userId}\\n\${expiry}\`)
  .digest('hex')
const signature = \`\${expiry}.\${mac}\``,
  },
])

/** The key is fetched on demand, so it is not in the page until the owner asks for it. */
async function loadKey(): Promise<string | null> {
  if (signingKey.value) {
    return signingKey.value
  }
  busy.value = true
  try {
    signingKey.value = (await getVisitorKey(props.widgetId)).key
  } catch {
    error(t('widgets.advancedConfig.userDataIntegration.signing.loadFailed'))
  } finally {
    busy.value = false
  }
  return signingKey.value
}

async function toggleReveal(): Promise<void> {
  if (revealed.value) {
    revealed.value = false
    return
  }
  revealed.value = (await loadKey()) !== null
}

async function copyKey(): Promise<void> {
  const key = await loadKey()
  if (!key) {
    return
  }
  try {
    await navigator.clipboard.writeText(key)
    success(t('widgets.advancedConfig.userDataIntegration.signing.copied'))
  } catch {
    revealed.value = true
  }
}

async function rotateKey(): Promise<void> {
  const ok = await confirm({
    title: t('widgets.advancedConfig.userDataIntegration.signing.rotateTitle'),
    message: t('widgets.advancedConfig.userDataIntegration.signing.rotateConfirm'),
    confirmText: t('widgets.advancedConfig.userDataIntegration.signing.rotate'),
    danger: true,
  })
  if (!ok) {
    return
  }
  busy.value = true
  try {
    signingKey.value = (await rotateVisitorKey(props.widgetId)).key
    revealed.value = true
    success(t('widgets.advancedConfig.userDataIntegration.signing.rotated'))
  } catch {
    error(t('widgets.advancedConfig.userDataIntegration.signing.loadFailed'))
  } finally {
    busy.value = false
  }
}
</script>
