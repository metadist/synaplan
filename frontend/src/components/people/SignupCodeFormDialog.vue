<template>
  <Teleport :to="teleportTarget">
    <div
      v-if="open"
      class="modal-overlay fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
      @click.self="onBackdrop"
    >
      <div
        ref="panel"
        class="modal-panel surface-card rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto scroll-thin p-4 sm:p-6 space-y-4"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        data-testid="modal-signup-code"
      >
        <h3 class="text-base sm:text-lg font-semibold txt-primary">{{ title }}</h3>

        <SignupCodeCreated v-if="createdCode" :code="createdCode" @done="close" />

        <form v-else class="space-y-4" novalidate @submit.prevent="submit">
          <div>
            <label :for="`${uid}-label`" class="block text-sm txt-primary">
              {{ $t('admin.signupCodes.form.label') }}
            </label>
            <input
              :id="`${uid}-label`"
              ref="labelInput"
              v-model="label"
              type="text"
              maxlength="120"
              :placeholder="$t('admin.signupCodes.form.labelPlaceholder')"
              :aria-describedby="`${uid}-label-hint`"
              class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-signup-code-label"
            />
            <p :id="`${uid}-label-hint`" class="text-xs txt-secondary mt-1">
              {{ $t('admin.signupCodes.form.labelHint') }}
            </p>
          </div>

          <template v-if="!code">
            <div>
              <label :for="`${uid}-level`" class="block text-sm txt-primary">
                {{ $t('admin.signupCodes.form.level') }}
              </label>
              <select
                :id="`${uid}-level`"
                v-model="level"
                class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="select-signup-code-level"
              >
                <option v-for="option in LEVELS" :key="option" :value="option">
                  {{ $t(`admin.signupCodes.levels.${option}`) }}
                </option>
              </select>
            </div>
            <div>
              <label :for="`${uid}-validity`" class="block text-sm txt-primary">
                {{ $t('admin.signupCodes.form.validity') }}
              </label>
              <select
                :id="`${uid}-validity`"
                v-model="validity"
                class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="select-signup-code-validity"
              >
                <option v-for="option in VALIDITIES" :key="option" :value="option">
                  {{ $t(`admin.signupCodes.validity.${option}`) }}
                </option>
              </select>
            </div>
          </template>

          <fieldset v-else class="space-y-2">
            <legend class="text-sm txt-primary">
              {{ $t('admin.signupCodes.form.validUntil') }}
            </legend>
            <input
              v-model="validUntil"
              type="date"
              :aria-label="$t('admin.signupCodes.form.validUntil')"
              :disabled="noEnd"
              class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="input-signup-code-valid-until"
            />
            <label class="flex items-center gap-2 text-sm txt-secondary">
              <input v-model="noEnd" type="checkbox" data-testid="checkbox-signup-code-no-end" />
              {{ $t('admin.signupCodes.form.noEnd') }}
            </label>
          </fieldset>

          <fieldset class="space-y-2">
            <legend class="text-sm txt-primary">{{ $t('admin.signupCodes.form.maxUses') }}</legend>
            <input
              v-model.number="maxUses"
              type="number"
              :aria-label="$t('admin.signupCodes.form.maxUses')"
              min="1"
              step="1"
              :disabled="unlimited"
              class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="input-signup-code-max-uses"
            />
            <label class="flex items-center gap-2 text-sm txt-secondary">
              <input
                v-model="unlimited"
                type="checkbox"
                data-testid="checkbox-signup-code-unlimited"
              />
              {{ $t('admin.signupCodes.form.unlimited') }}
            </label>
          </fieldset>

          <p
            v-if="error"
            role="alert"
            class="text-sm text-red-600 dark:text-red-400"
            data-testid="text-signup-code-form-error"
          >
            {{ error }}
          </p>

          <div class="flex flex-wrap justify-end gap-2">
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 text-sm font-medium"
              @click="close"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="saving"
              data-testid="btn-signup-code-submit"
            >
              {{ code ? $t('common.save') : $t('admin.signupCodes.create') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, toRef, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SignupCodeCreated from '@/components/people/SignupCodeCreated.vue'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import { useModalFocus } from '@/composables/useModalFocus'
import { useNotification } from '@/composables/useNotification'
import {
  signupCodesApi,
  type SignupCode,
  type SignupCodeCreate,
  type SignupCodeLevel,
} from '@/services/api/signupCodesApi'
import { dateInputToUnixEndOfDay, unixToDateInput } from '@/utils/signupCodeDates'

const LEVELS: SignupCodeLevel[] = ['NEW', 'PRO', 'TEAM', 'BUSINESS']
const VALIDITIES: SignupCodeCreate['validity'][] = ['forever', 'week', 'fiveMonths']

const props = defineProps<{ open: boolean; code: SignupCode | null }>()
const emit = defineEmits<{ close: []; created: [code: SignupCode]; saved: [code: SignupCode] }>()

const { t } = useI18n()
const { success } = useNotification()
const { teleportTarget } = useFullscreenTeleportTarget()
const uid = useId()

const label = ref('')
const level = ref<SignupCodeLevel>('NEW')
const validity = ref<SignupCodeCreate['validity']>('forever')
const validUntil = ref('')
const noEnd = ref(true)
const maxUses = ref<number | ''>(1)
const unlimited = ref(false)
const saving = ref(false)
const error = ref('')
const createdCode = ref('')
const labelInput = ref<HTMLInputElement | null>(null)
const panel = ref<HTMLElement | null>(null)
/** The expiry as the dialog opened, so a label-only edit leaves an exact expiry alone. */
const openedExpiry = ref({ noEnd: true, validUntil: '' })

useModalFocus(panel, toRef(props, 'open'), close)

const title = computed(() => {
  if (createdCode.value) return t('admin.signupCodes.form.titleCreated')
  return props.code
    ? t('admin.signupCodes.form.titleEdit')
    : t('admin.signupCodes.form.titleCreate')
})

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    const code = props.code
    label.value = code?.label ?? ''
    level.value = 'NEW'
    validity.value = 'forever'
    noEnd.value = code?.expiresAt == null
    validUntil.value = code?.expiresAt != null ? unixToDateInput(code.expiresAt) : ''
    openedExpiry.value = { noEnd: noEnd.value, validUntil: validUntil.value }
    unlimited.value = code ? code.maxUses === null : false
    maxUses.value = code?.maxUses ?? 1
    error.value = ''
    createdCode.value = ''
    await nextTick()
    labelInput.value?.focus()
  }
)

function validationError(): string {
  if (!label.value.trim()) return t('admin.signupCodes.form.labelRequired')
  if (
    !unlimited.value &&
    (typeof maxUses.value !== 'number' || maxUses.value < 1 || !Number.isInteger(maxUses.value))
  ) {
    return t('admin.signupCodes.form.maxUsesInvalid')
  }
  if (props.code && !noEnd.value && !validUntil.value)
    return t('admin.signupCodes.form.dateRequired')
  return ''
}

async function submit() {
  error.value = validationError()
  if (error.value) return
  const limit = unlimited.value ? null : (maxUses.value as number)
  saving.value = true
  try {
    if (props.code) {
      const expiryChanged =
        noEnd.value !== openedExpiry.value.noEnd ||
        validUntil.value !== openedExpiry.value.validUntil
      const saved = await signupCodesApi.update(props.code.id, {
        label: label.value.trim(),
        maxUses: limit,
        ...(expiryChanged
          ? { expiresAt: noEnd.value ? null : dateInputToUnixEndOfDay(validUntil.value) }
          : {}),
      })
      emit('saved', saved)
      success(t('admin.signupCodes.form.saved'))
      emit('close')
    } else {
      const created = await signupCodesApi.create({
        label: label.value.trim(),
        level: level.value,
        validity: validity.value,
        maxUses: limit,
      })
      createdCode.value = created.code
      emit('created', created.signupCode)
    }
  } catch {
    error.value = props.code
      ? t('admin.signupCodes.form.saveFailed')
      : t('admin.signupCodes.form.createFailed')
  } finally {
    saving.value = false
  }
}

/** A pending request keeps the dialog open: a code created behind a closed dialog would be lost. */
function close() {
  if (!saving.value) emit('close')
}

/** The new code is shown once; a stray click beside the panel must not lose it. */
function onBackdrop() {
  if (!createdCode.value) close()
}
</script>
