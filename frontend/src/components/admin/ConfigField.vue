<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Icon } from '@iconify/vue'
import type { ConfigFieldSchema, ConfigValue } from '@/services/api/adminConfigApi'

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

// Placeholder for masked fields
const placeholder = computed(() => {
  if (props.value.isMasked && !isDirty.value) {
    return props.value.isSet ? '••••••••' : props.schema.description
  }
  return props.schema.description
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
  isDirty.value = true
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
    return 'text-green-500'
  }
  return 'txt-secondary'
})
</script>

<template>
  <div class="config-field">
    <div class="flex items-center justify-between mb-1.5">
      <label :for="fieldKey" class="flex items-center gap-2 text-sm font-medium txt-primary">
        <code class="text-xs bg-black/5 dark:bg-white/5 px-1.5 py-0.5 rounded">{{ fieldKey }}</code>
        <Icon :icon="statusIcon" :class="['w-4 h-4', statusColor]" />
      </label>
      <span v-if="isDirty" class="text-xs text-yellow-600 dark:text-yellow-400">
        {{ $t('admin.config.unsaved') }}
      </span>
    </div>

    <p class="text-xs txt-secondary mb-2">{{ schema.description }}</p>

    <!-- Boolean Toggle -->
    <div v-if="schema.type === 'boolean'" class="flex items-center gap-3">
      <button
        type="button"
        :disabled="disabled"
        :class="[
          'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--brand)] focus:ring-offset-2',
          localValue === 'true' ? 'bg-[var(--brand)]' : 'bg-gray-300 dark:bg-gray-600',
          disabled && 'opacity-50 cursor-not-allowed',
        ]"
        role="switch"
        :aria-checked="localValue === 'true'"
        @click="handleToggle"
      >
        <span
          :class="[
            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
            localValue === 'true' ? 'translate-x-5' : 'translate-x-0',
          ]"
        />
      </button>
      <span class="text-sm txt-secondary">
        {{ localValue === 'true' ? $t('common.enabled') : $t('common.disabled') }}
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
  border-color: var(--dark-border-20, rgba(255, 255, 255, 0.1));
}
</style>
