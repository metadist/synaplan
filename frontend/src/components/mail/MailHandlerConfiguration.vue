<template>
  <div class="space-y-6" data-testid="comp-mail-handler-config">
    <div class="flex items-center justify-between mb-8" data-testid="section-header">
      <div>
        <h1 class="text-2xl font-semibold txt-primary mb-2">
          {{ handlerId ? $t('mail.editHandler') : $t('mail.createHandler') }}
        </h1>
      </div>
      <button
        @click="$emit('cancel')"
        class="p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
        :aria-label="$t('widget.closeEditor')"
        data-testid="btn-close"
      >
        <XMarkIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Handler Name -->
    <div class="surface-card p-6" data-testid="section-handler-name">
      <label class="block text-sm font-medium txt-primary mb-2">
        {{ $t('mail.handlerName') }}
      </label>
      <input
        v-model="handlerName"
        type="text"
        class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('mail.handlerNamePlaceholder')"
        data-testid="input-handler-name"
      />
      <p class="text-xs txt-secondary mt-1">
        {{ $t('mail.handlerNameHelp') }}
      </p>
    </div>

    <!-- Step Indicator -->
    <div class="surface-card p-6" data-testid="section-stepper">
      <div class="flex items-center justify-between">
        <div
          v-for="(step, index) in steps"
          :key="index"
          class="flex items-center flex-1"
        >
          <div class="flex items-center gap-3">
            <div
              :class="[
                'w-10 h-10 rounded-full flex items-center justify-center font-semibold',
                currentStep > index ? 'bg-green-500 text-white' :
                currentStep === index ? 'bg-[var(--brand)] text-white' :
                'bg-light-border/30 dark:bg-dark-border/20 txt-secondary'
              ]"
            >
              <CheckIcon v-if="currentStep > index" class="w-5 h-5" />
              <span v-else>{{ index + 1 }}</span>
            </div>
            <div class="hidden md:block">
              <p class="font-medium txt-primary text-sm">{{ $t(`mail.steps.${step}.title`) }}</p>
              <p class="text-xs txt-secondary">{{ $t(`mail.steps.${step}.desc`) }}</p>
            </div>
          </div>
          <div v-if="index < steps.length - 1" class="flex-1 h-0.5 mx-4 bg-light-border/30 dark:bg-dark-border/20"></div>
        </div>
      </div>
    </div>

    <!-- Step 1: Connection -->
    <div v-if="currentStep === 0" class="surface-card p-6">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <EnvelopeIcon class="w-5 h-5" />
        {{ $t('mail.pickUpConfig') }}
      </h3>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.mailServer') }}
          </label>
          <input
            v-model="config.mailServer"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.mailServerPlaceholder')"
            data-testid="input-mail-server"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.mailServerHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.port') }}
          </label>
          <input
            v-model.number="config.port"
            type="number"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.portPlaceholder')"
            data-testid="input-port"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.portHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.protocol') }}
          </label>
          <select
            v-model="config.protocol"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-protocol"
          >
            <option
              v-for="option in protocolOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.protocolHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.security') }}
          </label>
          <select
            v-model="config.security"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-security"
          >
            <option
              v-for="option in securityOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.securityHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.username') }}
          </label>
          <input
            v-model="config.username"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            placeholder="user@example.com or account123"
            data-testid="input-username"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.usernameHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.password') }}
          </label>
          <input
            v-model="config.password"
            type="password"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.passwordPlaceholder')"
            data-testid="input-password"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.passwordHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.checkInterval') }}
          </label>
          <select
            v-model.number="config.checkInterval"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-check-interval"
          >
            <option
              v-for="option in checkIntervalOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.checkIntervalHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.deleteAfter') }}
          </label>
          <label class="flex items-center gap-2 cursor-pointer mt-2">
            <input
              v-model="config.deleteAfter"
              type="checkbox"
              class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-delete-after"
            />
            <span class="text-sm txt-primary">{{ $t('mail.deleteAfterLabel') }}</span>
          </label>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.deleteAfterHelp') }}
          </p>
        </div>
      </div>

      <div class="flex gap-3 mt-6">
        <button
          @click="testConnection"
          class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors flex items-center gap-2"
          data-testid="btn-test"
        >
          <BoltIcon class="w-4 h-4" />
          {{ $t('mail.testConnection') }}
        </button>
        <button
          @click="showHelp"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center gap-2"
          data-testid="btn-help"
        >
          <QuestionMarkCircleIcon class="w-4 h-4" />
          {{ $t('mail.connectionHelp') }}
        </button>
      </div>
    </div>

    <!-- Step 2: Departments -->
    <div v-else-if="currentStep === 1" class="surface-card p-6" data-testid="section-step-departments">
      <h3 class="text-lg font-semibold txt-primary mb-2 flex items-center gap-2">
        <UserGroupIcon class="w-5 h-5" />
        {{ $t('mail.departments') }}
      </h3>
      <p class="txt-secondary text-sm mb-4">
        {{ $t('mail.departmentsDescription') }}
      </p>

      <div class="bg-cyan-500/10 border border-cyan-500/30 rounded-lg p-4 mb-6">
        <div class="flex gap-2">
          <InformationCircleIcon class="w-5 h-5 text-cyan-500 flex-shrink-0 mt-0.5" />
          <p class="text-sm txt-primary">
            {{ $t('mail.departmentNote') }}
          </p>
        </div>
      </div>

      <div class="space-y-3" data-testid="section-departments-list">
        <div
          v-for="dept in departments"
          :key="dept.id"
          class="p-5 bg-black/[0.02] dark:bg-white/[0.02] rounded-xl border border-light-border/20 dark:border-dark-border/10 hover:border-light-border/40 dark:hover:border-dark-border/20 transition-colors"
          data-testid="item-department"
        >
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium txt-secondary">{{ $t('mail.department') }} {{ departments.indexOf(dept) + 1 }}</span>
              <span
                v-if="dept.isDefault"
                class="px-2 py-0.5 bg-[var(--brand)]/10 text-[var(--brand)] text-xs font-medium rounded-full"
              >
                {{ $t('mail.default') }}
              </span>
            </div>
            <button
              @click="removeDepartment(dept.id)"
              class="icon-ghost icon-ghost--danger"
              :aria-label="$t('mail.removeDepartment')"
              data-testid="btn-remove"
            >
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('mail.emailAddress') }}
              </label>
             <input
               v-model="dept.email"
               type="email"
               class="w-full px-4 py-2.5 rounded-lg bg-transparent border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
               :placeholder="$t('mail.emailAddressPlaceholder')"
               data-testid="input-dept-email"
             />
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('mail.rulesForwarding') }}
              </label>
             <input
               v-model="dept.rules"
               type="text"
               class="w-full px-4 py-2.5 rounded-lg bg-transparent border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
               :placeholder="$t('mail.rulesPlaceholder')"
               data-testid="input-dept-rules"
             />
            </div>
          </div>

          <div class="mt-4 pt-4 border-t border-light-border/20 dark:border-dark-border/10">
            <label class="flex items-center gap-2 cursor-pointer group">
             <input
               :checked="dept.isDefault"
               @change="setDefault(dept.id)"
               type="radio"
               name="default-dept"
               class="w-4 h-4 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
               data-testid="input-dept-default"
             />
              <span class="text-sm txt-secondary group-hover:txt-primary transition-colors">{{ $t('mail.setAsDefault') }}</span>
            </label>
          </div>
        </div>
      </div>

      <div class="flex gap-3 mt-6">
        <button
          @click="addDepartment"
          :disabled="departments.length >= 10"
          class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="btn-add"
        >
          <PlusIcon class="w-4 h-4" />
          {{ $t('mail.addDepartment') }}
        </button>
        <button
          @click="resetToDefault"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
          data-testid="btn-reset"
        >
          {{ $t('mail.resetToDefault') }}
        </button>
      </div>
    </div>

    <!-- Step 3: Test -->
    <div v-else-if="currentStep === 2" class="surface-card p-6" data-testid="section-step-test">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <BoltIcon class="w-5 h-5" />
        {{ $t('mail.testConnection') }}
      </h3>
      <p class="txt-secondary text-sm mb-6">
        {{ $t('mail.testConnectionDescription') }}
      </p>

      <div class="space-y-4">
        <div class="bg-light-border/10 dark:bg-dark-border/10 rounded-lg p-4 border border-light-border/30 dark:border-dark-border/20">
          <h4 class="font-medium txt-primary mb-2">{{ $t('mail.connectionSummary') }}</h4>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div class="txt-secondary">{{ $t('mail.mailServer') }}:</div>
            <div class="txt-primary font-mono">{{ config.mailServer || '-' }}</div>
            <div class="txt-secondary">{{ $t('mail.port') }}:</div>
            <div class="txt-primary font-mono">{{ config.port || '-' }}</div>
            <div class="txt-secondary">{{ $t('mail.username') }}:</div>
            <div class="txt-primary font-mono">{{ config.username || '-' }}</div>
            <div class="txt-secondary">{{ $t('mail.departments') }}:</div>
            <div class="txt-primary">{{ departments.length }} {{ $t('mail.configured') }}</div>
          </div>
        </div>

        <button
          @click="testConnection"
          :disabled="isTestingConnection"
          class="w-full px-6 py-3 rounded-lg bg-green-500 text-white hover:bg-green-600 transition-colors flex items-center justify-center gap-2 font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="btn-run-test"
        >
          <svg
            v-if="isTestingConnection"
            class="w-5 h-5 animate-spin"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <BoltIcon v-else class="w-5 h-5" />
          {{ isTestingConnection ? $t('mail.testing') : $t('mail.runTest') }}
        </button>

        <!-- Test Result -->
        <Transition
          enter-active-class="transition-all duration-300"
          enter-from-class="opacity-0 transform -translate-y-2"
          enter-to-class="opacity-100 transform translate-y-0"
          leave-active-class="transition-all duration-200"
          leave-from-class="opacity-100 transform translate-y-0"
          leave-to-class="opacity-0 transform -translate-y-2"
        >
          <div
            v-if="testResult"
            :class="[
              'p-4 rounded-lg border flex items-start gap-3',
              testResult.success
                ? 'bg-green-500/10 border-green-500/30'
                : 'bg-red-500/10 border-red-500/30'
            ]"
          >
            <CheckCircleIcon v-if="testResult.success" class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
            <XCircleIcon v-else class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" />
            <div>
              <p
                :class="[
                  'font-medium text-sm',
                  testResult.success ? 'text-green-500' : 'text-red-500'
                ]"
              >
                {{ testResult.success ? $t('mail.testSuccess') : $t('mail.testFailed') }}
              </p>
              <p class="text-xs txt-secondary mt-1">{{ testResult.message }}</p>
            </div>
          </div>
        </Transition>
      </div>
    </div>

    <!-- Navigation -->
    <div class="flex gap-3 justify-between" data-testid="section-navigation">
      <button
        v-if="currentStep > 0"
        @click="prevStep"
        class="px-6 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
        data-testid="btn-prev"
      >
        {{ $t('mail.previous') }}
      </button>
      <div v-else></div>

      <div class="flex gap-3">
        <button
          v-if="currentStep < steps.length - 1"
        @click="nextStep"
        :disabled="(currentStep === 0 && !isStep1Valid) || (currentStep === 1 && !isStep2Valid)"
        class="btn-primary px-6 py-2 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-next"
      >
          {{ $t('mail.next') }}
        </button>
        <button
          v-else
        @click="saveConfiguration"
        class="btn-primary px-6 py-2 rounded-lg flex items-center gap-2"
        data-testid="btn-save"
      >
          <CheckIcon class="w-4 h-4" />
          {{ $t('mail.saveConfiguration') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  EnvelopeIcon,
  BoltIcon,
  QuestionMarkCircleIcon,
  InformationCircleIcon,
  TrashIcon,
  PlusIcon,
  CheckIcon,
  UserGroupIcon,
  XMarkIcon,
  CheckCircleIcon,
  XCircleIcon
} from '@heroicons/vue/24/outline'
import type { MailConfig, Department, SavedMailHandler } from '@/mocks/mail'
import {
  defaultMailConfig,
  protocolOptions,
  securityOptions,
  checkIntervalOptions
} from '@/mocks/mail'

interface Props {
  handler?: SavedMailHandler
  handlerId?: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  save: [name: string, config: MailConfig, departments: Department[]]
  cancel: []
}>()

const steps = ['connection', 'departments', 'test']
const currentStep = ref(0)
const handlerName = ref('')
const config = ref<MailConfig>({ ...defaultMailConfig })
const departments = ref<Department[]>([])
const isTestingConnection = ref(false)
const testResult = ref<{ success: boolean; message: string } | null>(null)

// Initialize if editing
watch(() => props.handler, (handler) => {
  if (handler) {
    handlerName.value = handler.name
    config.value = { ...handler.config }
    departments.value = [...handler.departments]
  }
}, { immediate: true })

// Validation for each step
const isStep1Valid = computed(() => {
  return !!(config.value.mailServer && config.value.port && config.value.username && config.value.password)
})

const isStep2Valid = computed(() => {
  return departments.value.length > 0 && departments.value.every(d => d.email && d.rules)
})

const nextStep = () => {
  if (currentStep.value < steps.length - 1) {
    currentStep.value++
  }
}

const prevStep = () => {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}

const addDepartment = () => {
  if (departments.value.length < 10) {
    departments.value.push({
      id: Date.now().toString(),
      email: '',
      rules: '',
      isDefault: departments.value.length === 0
    })
  }
}

const removeDepartment = (id: string) => {
  const index = departments.value.findIndex(d => d.id === id)
  if (index !== -1) {
    const wasDefault = departments.value[index].isDefault
    departments.value.splice(index, 1)
    if (wasDefault && departments.value.length > 0) {
      departments.value[0].isDefault = true
    }
  }
}

const setDefault = (id: string) => {
  departments.value.forEach(d => {
    d.isDefault = d.id === id
  })
}

const resetToDefault = () => {
  departments.value = []
}

const testConnection = async () => {
  isTestingConnection.value = true
  testResult.value = null
  
  // Simulate API call
  await new Promise(resolve => setTimeout(resolve, 2000))
  
  // Mock success/fail (90% success rate)
  const success = Math.random() > 0.1
  testResult.value = {
    success,
    message: success
      ? 'Successfully connected to mail server. All settings are working correctly.'
      : 'Failed to connect. Please check your credentials and server settings.'
  }
  
  isTestingConnection.value = false
}

const showHelp = () => {
  console.log('Show connection help')
}

const saveConfiguration = () => {
  if (!handlerName.value.trim()) {
    handlerName.value = `Mail Handler ${Date.now()}`
  }
  emit('save', handlerName.value, config.value, departments.value)
}
</script>

