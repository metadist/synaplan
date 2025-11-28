<template>
  <div class="space-y-6" data-testid="comp-mail-handler-config">
    <div class="flex items-center justify-between mb-8" data-testid="section-header">
      <div>
        <h1 class="text-2xl font-semibold txt-primary mb-2">
          {{ handlerId ? $t('mail.editHandler') : $t('mail.createHandler') }}
        </h1>
        <p class="txt-secondary">{{ $t('mail.wizardLead') }}</p>
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
                'w-10 h-10 rounded-full flex items-center justify-center font-semibold transition-colors',
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

    <!-- Step 1: Incoming Mail -->
    <div v-if="currentStep === 0" class="surface-card p-6" data-testid="section-step-incoming">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
            <EnvelopeIcon class="w-5 h-5" />
            {{ $t('mail.incomingTitle') }}
          </h3>
          <p class="txt-secondary text-sm">{{ $t('mail.incomingDesc') }}</p>
        </div>
        <span class="text-xs pill pill--active">{{ $t('mail.required') }}</span>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="md:col-span-2">
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

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.incomingServer') }}
          </label>
          <input
            v-model="config.mailServer"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.mailServerPlaceholder')"
            data-testid="input-mail-server"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.incomingServerHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.loginEmail') }}
          </label>
          <input
            v-model="config.username"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.usernamePlaceholder')"
            data-testid="input-username"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.loginEmailHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.appPassword') }}
          </label>
          <input
            v-model="config.password"
            type="password"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.passwordPlaceholder')"
            data-testid="input-password"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.appPasswordHelp') }}
          </p>
        </div>
      </div>

      <div class="mt-6">
        <button
          type="button"
          @click="toggleIncomingAdvanced"
          class="flex items-center gap-2 text-sm txt-primary hover:txt-brand transition-colors"
          :aria-expanded="showIncomingAdvanced"
        >
          <span class="font-medium">{{ showIncomingAdvanced ? $t('mail.hideAdvanced') : $t('mail.showAdvanced') }}</span>
        </button>
        <Transition
          enter-active-class="transition-all duration-200"
          enter-from-class="opacity-0 -translate-y-1"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-150"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-1"
        >
          <div v-show="showIncomingAdvanced" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 bg-black/5 dark:bg-white/5 p-4 rounded-xl border border-light-border/30 dark:border-dark-border/20">
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

            <div class="md:col-span-2 p-4 rounded-lg border border-orange-500/30 bg-orange-500/10">
              <label class="flex items-center gap-2 cursor-pointer">
                <input
                  v-model="config.deleteAfter"
                  type="checkbox"
                  class="w-5 h-5 rounded border-orange-500 text-orange-600 focus:ring-2 focus:ring-orange-500"
                  data-testid="input-delete-after"
                />
                <span class="text-sm font-medium text-orange-700 dark:text-orange-300">{{ $t('mail.deleteAfterLabel') }}</span>
              </label>
              <p class="text-xs text-orange-700/80 dark:text-orange-200 mt-1">
                {{ $t('mail.deleteAfterRisk') }}
              </p>
            </div>
          </div>
        </Transition>
      </div>
    </div>

    <!-- Step 2: Outgoing Mail -->
    <div v-else-if="currentStep === 1" class="surface-card p-6" data-testid="section-step-outgoing">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
            <PaperAirplaneIcon class="w-5 h-5" />
            {{ $t('mail.outgoingTitle') }}
          </h3>
          <p class="txt-secondary text-sm">{{ $t('mail.outgoingDesc') }}</p>
        </div>
        <span class="text-xs pill pill--active">{{ $t('mail.required') }}</span>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.outgoingServer') }}
          </label>
          <input
            v-model="smtpConfig.smtpServer"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.smtpServerPlaceholder')"
            data-testid="input-smtp-server"
            @input="smtpServerTouched = true"
            autocomplete="off"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.outgoingServerHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.smtpUsernameLabel') }}
          </label>
          <input
            v-model="smtpConfig.smtpUsername"
            type="text"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.smtpUsernamePlaceholderSimple')"
            data-testid="input-smtp-username"
            @input="smtpUsernameTouched = true"
            autocomplete="off"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.smtpUsernameHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('mail.smtpPassword') }}
          </label>
          <input
            v-model="smtpConfig.smtpPassword"
            type="password"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('mail.smtpPasswordPlaceholder')"
            data-testid="input-smtp-password"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('mail.smtpPasswordHelp') }}
          </p>
        </div>
      </div>

      <div class="mt-6">
        <button
          type="button"
          @click="toggleOutgoingAdvanced"
          class="flex items-center gap-2 text-sm txt-primary hover:txt-brand transition-colors"
          :aria-expanded="showOutgoingAdvanced"
        >
          <span class="font-medium">{{ showOutgoingAdvanced ? $t('mail.hideAdvanced') : $t('mail.showAdvanced') }}</span>
        </button>
        <Transition
          enter-active-class="transition-all duration-200"
          enter-from-class="opacity-0 -translate-y-1"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-150"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-1"
        >
          <div v-show="showOutgoingAdvanced" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 bg-black/5 dark:bg-white/5 p-4 rounded-xl border border-light-border/30 dark:border-dark-border/20">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('mail.smtpPort') }}
              </label>
              <input
                v-model.number="smtpConfig.smtpPort"
                type="number"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('mail.smtpPortPlaceholder')"
                data-testid="input-smtp-port"
              />
              <p class="text-xs txt-secondary mt-1">
                {{ $t('mail.smtpPortHelp') }}
              </p>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('mail.smtpSecurity') }}
              </label>
              <select
                v-model="smtpConfig.smtpSecurity"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-smtp-security"
              >
                <option value="STARTTLS">STARTTLS (Port 587)</option>
                <option value="SSL/TLS">SSL/TLS (Port 465)</option>
                <option value="None">None (Port 25)</option>
              </select>
              <p class="text-xs txt-secondary mt-1">
                {{ $t('mail.smtpSecurityHelp') }}
              </p>
            </div>
          </div>
        </Transition>
      </div>
    </div>

    <!-- Step 3: Routing -->
    <div v-else-if="currentStep === 2" class="surface-card p-6" data-testid="section-step-routing">
      <div class="flex items-start justify-between mb-3">
        <div>
          <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
            <FunnelIcon class="w-5 h-5" />
            {{ $t('mail.routingTitle') }}
          </h3>
          <p class="txt-secondary text-sm">{{ $t('mail.routingDesc') }}</p>
        </div>
      </div>

      <div class="flex items-center justify-between mb-4">
        <div>
          <h4 class="font-semibold txt-primary">{{ $t('mail.departments') }}</h4>
          <p class="txt-secondary text-xs mt-1">{{ $t('mail.departmentsHelper') }}</p>
        </div>
        <button
          @click="addDepartment"
          :disabled="departments.length >= 10"
          class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="btn-add"
        >
          <PlusIcon class="w-4 h-4" />
          {{ $t('mail.addDepartment') }}
        </button>
      </div>

      <div class="space-y-3" data-testid="section-departments-list">
        <div
          v-for="dept in departments"
          :key="dept.id"
          class="p-5 bg-black/[0.02] dark:bg-white/[0.02] rounded-xl border border-light-border/20 dark:border-dark-border/10 hover:border-light-border/40 dark:hover-border-dark-border/20 transition-colors"
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
              <span v-if="dept.isDefault" class="text-[11px] txt-secondary">{{ $t('mail.defaultHint') }}</span>
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
              <p class="text-xs txt-secondary mt-1">
                {{ $t('mail.rulesHelp') }}
              </p>
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

      <div class="mt-6 space-y-2">
        <button
          type="button"
          @click="showFilterOptions = !showFilterOptions"
          class="w-full text-sm txt-secondary hover:txt-primary inline-flex items-center justify-between"
          :aria-expanded="showFilterOptions"
        >
          <span>{{ $t('mail.processingToggle') }}</span>
          <ChevronDownIcon
            class="w-4 h-4 transition-transform"
            :class="showFilterOptions ? 'rotate-180' : ''"
          />
        </button>
        <Transition
          enter-active-class="transition-all duration-200"
          enter-from-class="opacity-0 -translate-y-1"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-150"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-1"
        >
          <div
            v-show="showFilterOptions"
            class="rounded-lg border border-gray-700/40 dark:border-gray-200/30 bg-black/2 dark:bg-white/2 px-4 py-3 space-y-3"
          >
            <p class="text-xs font-medium txt-primary">{{ $t('mail.processingTitle') }}</p>

            <label class="flex items-start gap-3 cursor-pointer">
              <input
                type="radio"
                v-model="emailFilter.mode"
                value="new"
                class="mt-1 w-4 h-4 text-[var(--brand)] focus:ring-[var(--brand)]"
                data-testid="input-filter-new"
              />
              <div class="flex-1">
                <div class="text-sm txt-primary">{{ $t('mail.processingNewTitle') }}</div>
                <p class="text-xs txt-secondary">{{ $t('mail.processingNewDesc') }}</p>
              </div>
            </label>

            <label
              class="flex items-start gap-3 cursor-pointer"
              :class="!isPro ? 'opacity-60' : ''"
            >
              <input
                type="radio"
                v-model="emailFilter.mode"
                value="historical"
                :disabled="!isPro"
                class="mt-1 w-4 h-4 text-[var(--brand)] focus:ring-[var(--brand)]"
                data-testid="input-filter-historical"
              />
              <div class="flex-1">
                <div class="text-sm txt-primary">{{ $t('mail.processingHistoricalTitle') }}</div>
                <p class="text-xs txt-secondary">{{ $t('mail.processingHistoricalDesc') }}</p>
              </div>
            </label>
          </div>
        </Transition>
      </div>

      <div class="flex gap-3 mt-6">
        <button
          @click="resetToDefault"
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
          data-testid="btn-reset"
        >
          {{ $t('mail.resetToDefault') }}
        </button>
      </div>
    </div>

    <!-- Step 4: Connection Test -->
    <div v-else-if="currentStep === 3" class="surface-card p-6" data-testid="section-step-test">
      <h3 class="text-lg font-semibold txt-primary mb-2 flex items-center gap-2">
        <BoltIcon class="w-5 h-5" />
        {{ $t('mail.testConnection') }}
      </h3>
      <p class="txt-secondary text-sm mb-6">
        {{ $t('mail.testConnectionDescription') }}
      </p>

      <div class="space-y-4">
        <div class="bg-light-border/10 dark:bg-dark-border/10 rounded-lg p-4 border border-light-border/30 dark:border-dark-border/20">
          <h4 class="font-medium txt-primary mb-2">{{ $t('mail.connectionSummary') }}</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
            <div class="txt-secondary">{{ $t('mail.incomingServer') }}:</div>
            <div class="txt-primary font-mono">{{ config.mailServer || '-' }}</div>
            <div class="txt-secondary">{{ $t('mail.outgoingServer') }}:</div>
            <div class="txt-primary font-mono">{{ smtpConfig.smtpServer || '-' }}</div>
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

    <!-- Step 5: Summary -->
    <div v-else-if="currentStep === 4" class="surface-card p-6" data-testid="section-step-summary">
      <h3 class="text-lg font-semibold txt-primary mb-2 flex items-center gap-2">
        <CheckCircleIcon class="w-5 h-5" />
        {{ $t('mail.summaryTitle') }}
      </h3>
      <p class="txt-secondary text-sm mb-6">
        {{ $t('mail.summaryDesc') }}
      </p>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="p-4 rounded-xl border border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5">
          <div class="flex items-center justify-between mb-2">
            <span class="font-semibold txt-primary">{{ $t('mail.incomingTitle') }}</span>
            <span class="text-xs pill">{{ config.protocol }}</span>
          </div>
          <ul class="space-y-1 text-sm">
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.incomingServer') }}</span><span class="txt-primary font-mono">{{ config.mailServer || '-' }}</span></li>
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.port') }}</span><span class="txt-primary font-mono">{{ config.port || '-' }}</span></li>
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.security') }}</span><span class="txt-primary">{{ config.security }}</span></li>
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.checkInterval') }}</span><span class="txt-primary">{{ config.checkInterval }} {{ $t('mail.minutes') }}</span></li>
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.deleteAfterLabel') }}</span><span :class="config.deleteAfter ? 'text-orange-600 font-medium' : 'txt-primary'">{{ config.deleteAfter ? $t('mail.enabled') : $t('mail.disabled') }}</span></li>
          </ul>
        </div>

        <div class="p-4 rounded-xl border border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5">
          <div class="flex items-center justify-between mb-2">
            <span class="font-semibold txt-primary">{{ $t('mail.outgoingTitle') }}</span>
            <span class="text-xs pill">{{ smtpConfig.smtpSecurity }}</span>
          </div>
          <ul class="space-y-1 text-sm">
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.outgoingServer') }}</span><span class="txt-primary font-mono">{{ smtpConfig.smtpServer || '-' }}</span></li>
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.smtpPort') }}</span><span class="txt-primary font-mono">{{ smtpConfig.smtpPort || '-' }}</span></li>
            <li class="flex justify-between"><span class="txt-secondary">{{ $t('mail.smtpUsernameLabel') }}</span><span class="txt-primary font-mono">{{ smtpConfig.smtpUsername || '-' }}</span></li>
          </ul>
        </div>

        <div class="p-4 rounded-xl border border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5">
          <div class="flex items-center justify-between mb-2">
            <span class="font-semibold txt-primary">{{ $t('mail.routingTitle') }}</span>
            <span class="text-xs pill">{{ departments.length }} {{ $t('mail.configured') }}</span>
          </div>
          <p class="text-sm txt-secondary mb-2">
            {{ emailFilter.mode === 'new' ? $t('mail.filterNewOnly') : $t('mail.filterHistorical') }}
            <span v-if="emailFilter.fromDate || emailFilter.toDate" class="block text-xs">{{ emailFilter.fromDate || '—' }} → {{ emailFilter.toDate || '—' }}</span>
          </p>
          <ul class="space-y-1 text-sm">
            <li
              v-for="dept in departments"
              :key="dept.id"
              class="flex justify-between"
            >
              <span class="txt-secondary">{{ dept.rules || $t('mail.rulesPlaceholder') }}</span>
              <span class="txt-primary font-mono">{{ dept.email || '-' }}<span v-if="dept.isDefault" class="ml-2 text-[var(--brand)] text-xs font-semibold">{{ $t('mail.default') }}</span></span>
            </li>
          </ul>
        </div>

        <div class="p-4 rounded-xl border border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5">
          <div class="flex items-center justify-between mb-2">
            <span class="font-semibold txt-primary">{{ $t('mail.testConnection') }}</span>
            <span class="text-xs pill" :class="testResult?.success ? 'pill--active' : ''">{{ testResult ? (testResult.success ? $t('mail.testSuccess') : $t('mail.testFailed')) : $t('mail.notTested') }}</span>
          </div>
          <p class="text-sm txt-secondary">{{ testResult?.message || $t('mail.summaryTestHint') }}</p>
        </div>
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
          :disabled="!canProceed"
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
  TrashIcon,
  PlusIcon,
  CheckIcon,
  XMarkIcon,
  CheckCircleIcon,
  XCircleIcon,
  PaperAirplaneIcon,
  FunnelIcon,
  InformationCircleIcon,
  ChevronDownIcon
} from '@heroicons/vue/24/outline'
import type { MailConfig, Department, SavedMailHandler } from '@/mocks/mail'
import {
  defaultMailConfig,
  protocolOptions,
  securityOptions,
  checkIntervalOptions
} from '@/mocks/mail'
import { inboundEmailHandlersApi } from '@/services/api/inboundEmailHandlersApi'
import { useAuth } from '@/composables/useAuth'

const { isPro } = useAuth()

interface Props {
  handler?: SavedMailHandler
  handlerId?: string
}

const props = defineProps<Props>()

interface SmtpConfig {
  smtpServer: string
  smtpPort: number
  smtpSecurity: 'STARTTLS' | 'SSL/TLS' | 'None'
  smtpUsername: string
  smtpPassword: string
  toString?: () => string
}

interface EmailFilter {
  mode: 'new' | 'historical'
  fromDate?: string
  toDate?: string
}

const emit = defineEmits<{
  save: [name: string, config: MailConfig, departments: Department[], smtpConfig: SmtpConfig, emailFilter: EmailFilter]
  cancel: []
}>()

const steps = ['incoming', 'outgoing', 'routing', 'test', 'summary']
const currentStep = ref(0)
const handlerName = ref('')
const config = ref<MailConfig>({ ...defaultMailConfig })
const departments = ref<Department[]>([])
const isTestingConnection = ref(false)
const testResult = ref<{ success: boolean; message: string } | null>(null)
const showIncomingAdvanced = ref(false)
const showOutgoingAdvanced = ref(false)
const showFilterOptions = ref(false)

// SMTP Configuration (Required)
const smtpConfig = ref<SmtpConfig>({
  smtpServer: '',
  smtpPort: 587,
  smtpSecurity: 'STARTTLS',
  smtpUsername: '',
  smtpPassword: ''
})
const smtpServerTouched = ref(false)
const smtpUsernameTouched = ref(false)

// Email Filter Configuration
const emailFilter = ref<EmailFilter>({
  mode: 'new',
  fromDate: undefined,
  toDate: undefined
})

// Watch for mode change - reset dates if switching to 'new'
watch(() => emailFilter.value.mode, (newMode) => {
  if (newMode === 'new') {
    emailFilter.value.fromDate = undefined
    emailFilter.value.toDate = undefined
  }
})

// Initialize if editing
watch(() => props.handler, (handler) => {
  if (handler) {
    handlerName.value = handler.name
    config.value = { ...handler.config }
    departments.value = [...handler.departments]
    if (!smtpConfig.value.smtpServer && config.value.mailServer) {
      smtpConfig.value.smtpServer = config.value.mailServer
    }
    if (!smtpConfig.value.smtpUsername && config.value.username) {
      smtpConfig.value.smtpUsername = config.value.username
    }
  }
}, { immediate: true })

// Keep SMTP defaults aligned with incoming when empty (common IMAP/SMTP same host/login)
watch(() => config.value.mailServer, (newServer) => {
  if (newServer && !smtpConfig.value.smtpServer && !smtpServerTouched.value) {
    smtpConfig.value.smtpServer = newServer
  }
})

watch(() => config.value.username, (newUser) => {
  if (newUser && !smtpConfig.value.smtpUsername && !smtpUsernameTouched.value) {
    smtpConfig.value.smtpUsername = newUser
  }
})

// Validation for each step
const isIncomingStepValid = computed(() => {
  return !!(handlerName.value.trim() && config.value.mailServer && config.value.username && config.value.password)
})

const isOutgoingStepValid = computed(() => {
  return !!(smtpConfig.value.smtpServer && smtpConfig.value.smtpPort && smtpConfig.value.smtpUsername && smtpConfig.value.smtpPassword)
})

const isRoutingStepValid = computed(() => {
  return departments.value.length > 0 && departments.value.every(d => d.email && d.rules)
})

const canProceed = computed(() => {
  switch (currentStep.value) {
    case 0:
      return isIncomingStepValid.value
    case 1:
      return isOutgoingStepValid.value
    case 2:
      return isRoutingStepValid.value
    default:
      return true
  }
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

watch(currentStep, (step) => {
  if (step === 2 && departments.value.length === 0) {
    addDepartment()
  }
})

const testConnection = async () => {
  if (!props.handlerId) {
    testResult.value = {
      success: false,
      message: 'Please save the handler first before testing the connection.'
    }
    return
  }

  isTestingConnection.value = true
  testResult.value = null

  try {
    const result = await inboundEmailHandlersApi.testConnection(props.handlerId)
    testResult.value = {
      success: result.success,
      message: result.message
    }
  } catch (error: any) {
    testResult.value = {
      success: false,
      message: error.message || 'Failed to test connection'
    }
  } finally {
    isTestingConnection.value = false
  }
}

const saveConfiguration = () => {
  if (!handlerName.value.trim()) {
    handlerName.value = `Mail Handler ${Date.now()}`
  }

  emit('save', handlerName.value, config.value, departments.value, smtpConfig.value, emailFilter.value)
}

const toggleIncomingAdvanced = () => {
  showIncomingAdvanced.value = !showIncomingAdvanced.value
}

const toggleOutgoingAdvanced = () => {
  showOutgoingAdvanced.value = !showOutgoingAdvanced.value
}
</script>
