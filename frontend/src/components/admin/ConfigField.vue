<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import ProviderHelpHint from '@/components/admin/ProviderHelpHint.vue'
import type { ConfigFieldSchema, ConfigValue } from '@/services/api/adminConfigApi'
import { providerHelpByEnvVar } from '@/utils/providerHelp'

const { t } = useI18n()

interface Props {
  fieldKey: string
  schema: ConfigFieldSchema
  value: ConfigValue
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
})

const emit = defineEmits<{
  (e: 'update', key: string, value: string): void
}>()

// Local state
const localValue = ref(props.value.isMasked ? '' : props.value.value)
const showPassword = ref(false)
const isDirty = ref(false)

// Watch for external value changes
watch(
  () => props.value,
  (newVal) => {
    if (!isDirty.value) {
      localValue.value = newVal.isMasked ? '' : newVal.value
    }
  }
)

// Input type based on schema
const inputType = computed(() => {
  if (props.schema.type === 'password' && !showPassword.value) {
    return 'password'
  }
  if (props.schema.type === 'number') {
    return 'number'
  }
  if (props.schema.type === 'email') {
    return 'email'
  }
  if (props.schema.type === 'url') {
    return 'url'
  }
  return 'text'
})

/**
 * An example value beats repeating the description inside the input: the
 * description is already shown above the field, and "what does a valid value
 * look like?" is the question an admin actually has. Fields without an example
 * keep the previous behaviour.
 */
const placeholder = computed(() => {
  if (props.value.isMasked && !isDirty.value && props.value.isSet) {
    return '••••••••'
  }
  return props.schema.placeholder || props.schema.description
})

// Handle input change
function handleInput(event: Event) {
  const target = event.target as HTMLInputElement | HTMLSelectElement
  localValue.value = target.value
  isDirty.value = true
}

// Handle boolean toggle
function handleToggle() {
  const newValue = localValue.value === 'true' ? 'false' : 'true'
  localValue.value = newValue
  emit('update', props.fieldKey, newValue)
}

// Save changes
function saveChanges() {
  if (isDirty.value && localValue.value !== '') {
    emit('update', props.fieldKey, localValue.value)
    isDirty.value = false
  }
}

// Reset to original
function resetValue() {
  localValue.value = props.value.isMasked ? '' : props.value.value
  isDirty.value = false
}

// Status indicator
const statusIcon = computed(() => {
  if (props.value.isSet) {
    return props.schema.sensitive ? 'mdi:lock-check' : 'mdi:check-circle'
  }
  return 'mdi:circle-outline'
})

const statusColor = computed(() => {
  if (props.value.isSet) {
    return 'text-[var(--status-success)]'
  }
  return 'txt-secondary'
})

/** Cloud provider keys (source=database) — DB value overrides any matching .env entry. */
const showDbOverrideHint = computed(
  () =>
    props.schema.source === 'database' &&
    props.schema.type === 'password' &&
    props.value.isSet &&
    !isDirty.value
)

/**
 * The reverse case of showDbOverrideHint: a stored value that an environment
 * variable pins. Without this the toggle moves and nothing happens.
 */
const isPinnedByEnv = computed(() => props.value.envOverride === true)

/**
 * What the instance actually does, which is what the toggle has to show while an
 * environment variable pins the field. The stored row usually still holds the
 * shipped default, so rendering `localValue` here would put an "Enabled" switch
 * directly above a hint reading "Currently: Disabled".
 */
const displayedValue = computed(() =>
  isPinnedByEnv.value ? (props.value.effectiveValue ?? localValue.value) : localValue.value
)

const effectiveValueLabel = computed(() =>
  props.value.effectiveValue === 'true' ? t('common.enabled') : t('common.disabled')
)

const helpMeta = computed(() => providerHelpByEnvVar(props.fieldKey))
</script>

<template>
  <div class="config-field">
    <div class="flex items-center justify-between mb-1.5">
      <div class="flex items-center gap-1.5 min-w-0">
        <label :for="fieldKey" class="flex items-center gap-2 text-sm font-medium txt-primary">
          <code class="text-xs bg-black/5 dark:bg-white/5 px-1.5 py-0.5 rounded">{{
            fieldKey
          }}</code>
          <Icon :icon="statusIcon" :class="['w-4 h-4', statusColor]" />
        </label>
        <ProviderHelpHint
          v-if="helpMeta"
          :help-id="helpMeta.id"
          :url="helpMeta.url"
          :is-download="helpMeta.isDownload"
        />
      </div>
      <span v-if="isDirty" class="text-xs text-[var(--status-warning)]">
        {{ $t('admin.config.unsaved') }}
      </span>
    </div>

    <p class="text-xs txt-secondary mb-2">{{ schema.description }}</p>

    <!-- Boolean Toggle -->
    <div v-if="schema.type === 'boolean'" class="flex items-center gap-3">
      <button
        type="button"
        :disabled="disabled || isPinnedByEnv"
        :class="[
          'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--brand)] focus:ring-offset-2',
          displayedValue === 'true' ? 'bg-[var(--brand)]' : 'bg-gray-300 dark:bg-gray-600',
          (disabled || isPinnedByEnv) && 'opacity-50 cursor-not-allowed',
        ]"
        role="switch"
        :aria-checked="displayedValue === 'true'"
        @click="handleToggle"
      >
        <span
          :class="[
            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
            displayedValue === 'true' ? 'translate-x-5' : 'translate-x-0',
          ]"
        />
      </button>
      <span class="text-sm txt-secondary">
        {{ displayedValue === 'true' ? $t('common.enabled') : $t('common.disabled') }}
      </span>
    </div>

    <!-- Select Dropdown -->
    <div v-else-if="schema.type === 'select' && schema.options" class="flex gap-2">
      <select
        :id="fieldKey"
        :value="localValue"
        :disabled="disabled"
        class="flex-1 px-3 py-2 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
        @change="handleInput"
      >
        <option v-for="opt in schema.options" :key="opt" :value="opt">
          {{ opt }}
        </option>
      </select>
      <button
        v-if="isDirty"
        type="button"
        class="btn-primary px-4 py-2 rounded-lg"
        @click="saveChanges"
      >
        {{ $t('common.save') }}
      </button>
    </div>

    <!-- Text/Password/URL/Email/Number Input -->
    <div v-else class="flex gap-2">
      <div class="relative flex-1">
        <input
          :id="fieldKey"
          :type="inputType"
          :value="localValue"
          :placeholder="placeholder"
          :disabled="disabled"
          :class="[
            'w-full px-3 py-2 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none',
            schema.type === 'password' && 'pr-10',
            disabled && 'opacity-50 cursor-not-allowed',
          ]"
          @input="handleInput"
          @keyup.enter="saveChanges"
        />
        <!-- Password toggle -->
        <button
          v-if="schema.type === 'password'"
          type="button"
          class="absolute right-2 top-1/2 -translate-y-1/2 p-1 txt-secondary hover:txt-primary"
          @click="showPassword = !showPassword"
        >
          <Icon :icon="showPassword ? 'mdi:eye-off' : 'mdi:eye'" class="w-5 h-5" />
        </button>
      </div>

      <!-- Action buttons -->
      <button
        v-if="isDirty"
        type="button"
        class="btn-secondary px-3 py-2 rounded-lg"
        :title="$t('common.reset')"
        @click="resetValue"
      >
        <Icon icon="mdi:undo" class="w-5 h-5" />
      </button>
      <button
        v-if="isDirty"
        type="button"
        class="btn-primary px-4 py-2 rounded-lg"
        @click="saveChanges"
      >
        {{ $t('common.save') }}
      </button>
    </div>

    <p
      v-if="showDbOverrideHint"
      class="text-xs txt-secondary mt-1.5"
      data-testid="config-field-db-override-hint"
    >
      {{ $t('admin.config.dbOverridesEnv') }}
    </p>

    <p
      v-if="isPinnedByEnv"
      class="text-xs text-[var(--status-warning-text)] mt-1.5"
      data-testid="config-field-env-override-hint"
    >
      {{
        $t('admin.config.envOverridesDb', {
          key: fieldKey,
          value: effectiveValueLabel,
        })
      }}
    </p>
  </div>
</template>

<style scoped>
.config-field {
  padding: 1rem;
  background: var(--bg-chat);
  border-radius: 0.5rem;
  border: 1px solid var(--light-border-30, rgba(0, 0, 0, 0.1));
}

:root.dark .config-field {
  border-color: var(--dark-border-20, rgba(255, 255, 255, 0.06));
}
</style>
