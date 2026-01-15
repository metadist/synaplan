<template>
  <!-- Fullscreen Wizard -->
  <div
    class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-2 lg:p-4"
    data-testid="modal-widget-creation"
  >
    <div
      class="surface-card rounded-xl lg:rounded-2xl w-full max-w-6xl max-h-[95vh] lg:max-h-[90vh] overflow-hidden flex flex-col shadow-2xl"
      data-testid="section-wizard-shell"
    >
      <!-- Header -->
      <div
        class="px-4 lg:px-6 py-3 lg:py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between"
        data-testid="section-header"
      >
        <div>
          <h2 class="text-lg lg:text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:sparkles" class="w-5 h-5 lg:w-6 lg:h-6 txt-brand" />
            {{ $t('widgets.createNewWidget') }}
          </h2>
          <p class="text-xs lg:text-sm txt-secondary mt-1 hidden sm:block">
            {{ $t('widgets.wizardSubtitle') }}
          </p>
        </div>
        <button
          class="w-9 h-9 lg:w-10 lg:h-10 rounded-lg hover-surface transition-colors flex items-center justify-center flex-shrink-0"
          :aria-label="$t('common.close')"
          data-testid="btn-close"
          @click="handleClose"
        >
          <Icon icon="heroicons:x-mark" class="w-5 h-5 lg:w-6 lg:h-6 txt-secondary" />
        </button>
      </div>

      <!-- Progress Steps -->
      <div
        class="px-3 lg:px-6 py-3 lg:py-4 border-b border-light-border/30 dark:border-dark-border/20"
        data-testid="section-progress"
      >
        <div class="hidden sm:flex items-center justify-between max-w-3xl mx-auto w-full gap-4">
          <div v-for="(step, index) in steps" :key="index" class="flex items-center flex-1">
            <!-- Step Circle -->
            <div class="flex flex-col items-center">
              <div
                :class="[
                  'w-8 h-8 lg:w-10 lg:h-10 rounded-full flex items-center justify-center font-semibold transition-all text-sm lg:text-base',
                  currentStep > index
                    ? 'bg-[var(--brand)] text-white'
                    : currentStep === index
                      ? 'bg-[var(--brand-alpha-light)] txt-brand ring-2 ring-[var(--brand)]'
                      : 'surface-chip txt-secondary',
                ]"
              >
                <Icon
                  v-if="currentStep > index"
                  icon="heroicons:check"
                  class="w-4 h-4 lg:w-5 lg:h-5"
                />
                <span v-else>{{ index + 1 }}</span>
              </div>
              <p
                :class="[
                  'text-xs mt-2 font-medium whitespace-nowrap',
                  currentStep >= index ? 'txt-primary' : 'txt-secondary',
                ]"
              >
                {{ step.label }}
              </p>
            </div>

            <!-- Connector Line -->
            <div
              v-if="index < steps.length - 1"
              :class="[
                'flex-1 h-0.5 mx-2 lg:mx-4',
                currentStep > index ? 'bg-[var(--brand)]' : 'bg-[var(--border-light)]',
              ]"
            ></div>
          </div>
        </div>

        <div class="sm:hidden grid grid-cols-2 gap-2 mt-3">
          <div
            v-for="(step, index) in steps"
            :key="`mobile-${index}`"
            :class="[
              'surface-chip rounded-lg p-3 flex flex-col gap-1 border transition-all',
              currentStep > index
                ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
                : currentStep === index
                  ? 'border-[var(--brand)]/60 bg-[var(--brand-alpha-faint)]'
                  : 'border-light-border/30 dark:border-dark-border/20',
            ]"
          >
            <div class="flex items-center gap-2">
              <div
                :class="[
                  'w-7 h-7 rounded-full flex items-center justify-center text-sm font-semibold',
                  currentStep > index
                    ? 'bg-[var(--brand)] text-white'
                    : currentStep === index
                      ? 'bg-[var(--brand-alpha-light)] txt-brand'
                      : 'surface-card txt-secondary',
                ]"
              >
                <Icon v-if="currentStep > index" icon="heroicons:check" class="w-4 h-4" />
                <span v-else>{{ index + 1 }}</span>
              </div>
              <span
                :class="[
                  'text-sm font-medium',
                  currentStep >= index ? 'txt-primary' : 'txt-secondary',
                ]"
              >
                {{ step.label }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Content Area -->
      <div class="flex-1 overflow-y-auto scroll-thin">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 p-4 lg:p-6">
          <!-- Left: Configuration -->
          <div class="space-y-4 lg:space-y-6">
            <!-- Step 1: Basic Info -->
            <div v-if="currentStep === 0" class="space-y-4" data-testid="section-step-basic">
              <h3 class="font-semibold txt-primary flex items-center gap-2">
                <Icon icon="heroicons:information-circle" class="w-5 h-5" />
                {{ $t('widgets.step1Title') }}
              </h3>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.widgetName') }} *
                </label>
                <input
                  v-model="formData.name"
                  type="text"
                  :placeholder="$t('widgets.widgetNamePlaceholder')"
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-widget-name"
                />
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.taskPrompt') }} *
                </label>

                <!-- No Custom Prompts Available -->
                <div
                  v-if="customTaskPrompts.length === 0"
                  class="surface-card p-4 rounded-lg border-2 border-dashed border-light-border/50 dark:border-dark-border/30 text-center"
                >
                  <Icon
                    icon="heroicons:exclamation-triangle"
                    class="w-8 h-8 txt-secondary opacity-50 mx-auto mb-2"
                  />
                  <p class="txt-secondary text-sm mb-3">{{ $t('widgets.noCustomPrompts') }}</p>
                  <a
                    href="/config/task-prompts"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand-hover)] transition-colors text-sm font-medium"
                  >
                    <Icon icon="heroicons:plus" class="w-4 h-4" />
                    {{ $t('widgets.createTaskPrompt') }}
                  </a>
                </div>

                <!-- Custom Prompts Available -->
                <div v-else>
                  <select
                    v-model="formData.taskPromptTopic"
                    class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="input-task-prompt"
                  >
                    <option value="">{{ $t('widgets.selectTaskPrompt') }}</option>
                    <option
                      v-for="prompt in customTaskPrompts"
                      :key="prompt.topic"
                      :value="prompt.topic"
                    >
                      {{ prompt.name }}
                    </option>
                  </select>
                  <p class="text-xs txt-secondary mt-1.5 flex items-start gap-1">
                    <Icon
                      icon="heroicons:information-circle"
                      class="w-4 h-4 flex-shrink-0 mt-0.5"
                    />
                    <span>{{ $t('widgets.taskPromptHelp') }}</span>
                  </p>
                </div>
              </div>
            </div>

            <!-- Step 2: Appearance -->
            <div
              v-else-if="currentStep === 1"
              class="space-y-4"
              data-testid="section-step-appearance"
            >
              <h3 class="font-semibold txt-primary flex items-center gap-2">
                <Icon icon="heroicons:paint-brush" class="w-5 h-5" />
                {{ $t('widgets.step2Title') }}
              </h3>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('widgets.position') }}
                  </label>
                  <select
                    v-model="formData.config.position"
                    class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="input-position"
                  >
                    <option value="bottom-right">{{ $t('widgets.bottomRight') }}</option>
                    <option value="bottom-left">{{ $t('widgets.bottomLeft') }}</option>
                    <option value="top-right">{{ $t('widgets.topRight') }}</option>
                    <option value="top-left">{{ $t('widgets.topLeft') }}</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('widgets.defaultTheme') }}
                  </label>
                  <select
                    v-model="formData.config.defaultTheme"
                    class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="input-theme"
                  >
                    <option value="light">{{ $t('widgets.light') }}</option>
                    <option value="dark">{{ $t('widgets.dark') }}</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('widgets.primaryColor') }}
                  </label>
                  <input
                    v-model="formData.config.primaryColor"
                    type="color"
                    class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                    data-testid="input-primary-color"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('widgets.iconColor') }}
                  </label>
                  <input
                    v-model="formData.config.iconColor"
                    type="color"
                    class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                    data-testid="input-icon-color"
                  />
                </div>
              </div>
            </div>

            <!-- Step 3: Behavior -->
            <div
              v-else-if="currentStep === 2"
              class="space-y-4"
              data-testid="section-step-behavior"
            >
              <h3 class="font-semibold txt-primary flex items-center gap-2">
                <Icon icon="heroicons:adjustments-horizontal" class="w-5 h-5" />
                {{ $t('widgets.step3Title') }}
              </h3>

              <div class="flex items-center justify-between p-4 surface-chip rounded-lg">
                <div>
                  <p class="font-medium txt-primary">{{ $t('widgets.autoOpen') }}</p>
                  <p class="text-xs txt-secondary mt-1">{{ $t('widgets.autoOpenHelp') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="formData.config.autoOpen" type="checkbox" class="sr-only peer" />
                  <div
                    class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"
                  ></div>
                </label>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.autoMessage') }}
                </label>
                <textarea
                  v-model="formData.config.autoMessage"
                  rows="2"
                  :placeholder="$t('widgets.autoMessagePlaceholder')"
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
                  data-testid="input-auto-message"
                />
              </div>

              <div class="surface-chip rounded-lg p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <div>
                    <p class="font-medium txt-primary">{{ $t('widgets.allowFileUpload') }}</p>
                    <p class="text-xs txt-secondary mt-1">
                      {{ $t('widgets.allowFileUploadHelp') }}
                    </p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input
                      v-model="formData.config.allowFileUpload"
                      type="checkbox"
                      class="sr-only peer"
                      data-testid="input-allow-upload"
                    />
                    <div
                      class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"
                    ></div>
                  </label>
                </div>
                <div>
                  <label class="block text-sm font-medium txt-primary mb-1">
                    {{ $t('widgets.fileUploadLimit') }}
                  </label>
                  <input
                    v-model.number="formData.config.fileUploadLimit"
                    type="number"
                    min="0"
                    max="20"
                    :disabled="!formData.config.allowFileUpload"
                    class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
                    data-testid="input-file-limit"
                  />
                  <p class="text-xs txt-secondary mt-1.5">
                    {{ $t('widgets.fileUploadLimitHelp') }}
                  </p>
                  <p
                    v-if="formData.config.fileUploadLimit === 0 && formData.config.allowFileUpload"
                    class="text-xs text-amber-600 dark:text-amber-400 mt-1 flex items-center gap-1"
                  >
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                      <path
                        fill-rule="evenodd"
                        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                        clip-rule="evenodd"
                      />
                    </svg>
                    {{ $t('widgets.fileUploadUnlimitedWarning') }}
                  </p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('widgets.messageLimit') }}
                  </label>
                  <input
                    v-model.number="formData.config.messageLimit"
                    type="number"
                    min="1"
                    max="100"
                    class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="input-message-limit"
                  />
                  <p class="text-xs txt-secondary mt-1.5">{{ $t('widgets.messageLimitHelp') }}</p>
                </div>

                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    {{ $t('widgets.maxFileSize') }} (MB)
                  </label>
                  <input
                    v-model.number="formData.config.maxFileSize"
                    type="number"
                    min="1"
                    max="50"
                    class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="input-max-file-size"
                  />
                </div>
              </div>
            </div>

            <!-- Step 4: Review & Create -->
            <div
              v-else-if="currentStep === 3"
              class="space-y-4"
              data-testid="section-step-security"
            >
              <h3 class="font-semibold txt-primary flex items-center gap-2">
                <Icon icon="heroicons:check-circle" class="w-5 h-5" />
                {{ $t('widgets.step4Title') }}
              </h3>

              <div class="surface-chip p-4 rounded-lg space-y-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-medium txt-primary">{{ $t('widgets.allowedDomainsTitle') }}</p>
                    <p class="text-xs txt-secondary mt-1">
                      {{ $t('widgets.allowedDomainsHelp') }}
                    </p>
                  </div>
                  <Icon
                    icon="heroicons:shield-check"
                    class="w-8 h-8 txt-secondary opacity-60 hidden lg:block"
                  />
                </div>

                <div class="flex flex-col sm:flex-row gap-2">
                  <input
                    v-model="newAllowedDomain"
                    type="text"
                    :placeholder="$t('widgets.allowedDomainsPlaceholder')"
                    class="flex-1 px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    autocomplete="off"
                    data-testid="input-domain"
                    @keydown.enter.prevent="addAllowedDomain"
                  />
                  <button
                    class="btn-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"
                    data-testid="btn-add-domain"
                    @click="addAllowedDomain"
                  >
                    <Icon icon="heroicons:plus" class="w-4 h-4" />
                    {{ $t('widgets.allowedDomainsAdd') }}
                  </button>
                </div>

                <div class="flex flex-wrap gap-2 text-xs">
                  <span class="txt-secondary">{{ $t('widgets.allowedDomainsQuickAdd') }}</span>
                  <button
                    v-for="domain in LOCAL_TEST_DOMAINS"
                    :key="domain"
                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full border border-light-border/40 dark:border-dark-border/30 hover:bg-[var(--brand-alpha-light)] hover:txt-brand transition-colors"
                    data-testid="btn-quick-domain"
                    @click.prevent="addPredefinedDomain(domain)"
                  >
                    <Icon icon="heroicons:plus" class="w-3.5 h-3.5" />
                    {{ domain }}
                  </button>
                </div>

                <p v-if="allowedDomainError" class="text-xs text-red-500 dark:text-red-400">
                  {{ allowedDomainError }}
                </p>

                <div v-if="allowedDomainsList.length > 0" class="flex flex-wrap gap-2">
                  <span
                    v-for="domain in allowedDomainsList"
                    :key="domain"
                    :class="[
                      'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium border transition-colors',
                      isLocalTestingDomain(domain)
                        ? 'bg-red-500/10 text-red-600 dark:text-red-300 border-red-500/40'
                        : 'bg-[var(--brand-alpha-light)] txt-primary border-[var(--brand)]/20',
                    ]"
                    :title="
                      isLocalTestingDomain(domain) ? $t('widgets.localhostTooltip') : undefined
                    "
                  >
                    <Icon
                      v-if="isLocalTestingDomain(domain)"
                      icon="heroicons:exclamation-triangle"
                      class="w-3.5 h-3.5 text-red-500 dark:text-red-300"
                    />
                    {{ domain }}
                    <button
                      class="w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                      :aria-label="$t('widgets.removeDomain', { domain })"
                      data-testid="btn-remove-domain"
                      @click="removeAllowedDomain(domain)"
                    >
                      <Icon icon="heroicons:x-mark" class="w-3 h-3" />
                    </button>
                  </span>
                </div>
                <p v-else class="text-xs txt-secondary">
                  {{ $t('widgets.allowedDomainsEmpty') }}
                </p>

                <div
                  v-if="hasLocalTestingDomain"
                  class="mt-3 p-3 rounded-lg border border-red-500/30 bg-red-500/10 flex items-start gap-2 text-red-600 dark:text-red-300"
                >
                  <Icon icon="heroicons:shield-exclamation" class="w-5 h-5 flex-shrink-0 mt-0.5" />
                  <div>
                    <p class="text-sm font-semibold">
                      {{ $t('widgets.localhostWarningTitle') }}
                    </p>
                    <p class="text-xs mt-1">
                      {{ $t('widgets.localhostWarningDescription') }}
                    </p>
                  </div>
                </div>
              </div>

              <div class="surface-chip p-4 rounded-lg space-y-3">
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgets.widgetName') }}</p>
                  <p class="font-medium txt-primary">{{ formData.name || '-' }}</p>
                </div>
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgets.taskPrompt') }}</p>
                  <p class="font-medium txt-primary">{{ formData.taskPromptTopic || '-' }}</p>
                </div>
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgets.appearance') }}</p>
                  <p class="font-medium txt-primary">
                    {{ formData.config.position }} · {{ formData.config.defaultTheme }}
                  </p>
                </div>
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgets.colors') }}</p>
                  <div class="flex items-center gap-2 mt-1">
                    <div
                      class="w-8 h-8 rounded border border-light-border/30 dark:border-dark-border/20"
                      :style="{ backgroundColor: formData.config.primaryColor }"
                    ></div>
                    <div
                      class="w-8 h-8 rounded border border-light-border/30 dark:border-dark-border/20"
                      :style="{ backgroundColor: formData.config.iconColor }"
                    ></div>
                  </div>
                </div>
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgets.allowedDomainsSummary') }}</p>
                  <p class="font-medium txt-primary">
                    {{
                      allowedDomainsList.length > 0
                        ? allowedDomainsList.join(', ')
                        : $t('widgets.allowedDomainsEmpty')
                    }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Right: Live Preview -->
          <div class="space-y-4">
            <h3 class="font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:eye" class="w-5 h-5" />
              {{ $t('widgets.livePreview') }}
            </h3>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.websitePreviewLabel') }}
              </label>
              <input
                v-model="previewWebsite"
                type="text"
                :placeholder="$t('widgets.websitePreviewPlaceholder')"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              />
              <p class="text-xs txt-secondary mt-1.5 flex items-start gap-1">
                <Icon icon="heroicons:information-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>{{ $t('widgets.websitePreviewHelp') }}</span>
              </p>
            </div>

            <div
              class="relative overflow-hidden rounded-xl bg-white dark:bg-slate-900 border border-transparent sm:border-light-border/30 sm:dark:border-dark-border/20 min-h-[520px] sm:min-h-[600px] lg:min-h-[720px]"
            >
              <div class="absolute inset-0 bg-white dark:bg-slate-900">
                <iframe
                  v-if="sanitizedPreviewUrl"
                  :src="sanitizedPreviewUrl"
                  class="w-full h-full border-0 sm:scale-[0.9] sm:origin-top lg:scale-100 lg:origin-center"
                  sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
                ></iframe>
                <div
                  v-else
                  class="w-full h-full flex items-center justify-center text-sm txt-secondary px-6 text-center"
                >
                  <div>
                    <Icon icon="heroicons:globe-alt" class="w-8 h-8 mx-auto mb-2 opacity-60" />
                    <p>{{ $t('widgets.websitePreviewEmpty') }}</p>
                  </div>
                </div>
              </div>

              <div
                class="absolute inset-0 pointer-events-none flex items-end justify-end p-2 sm:p-4"
              >
                <div class="pointer-events-auto w-full max-w-none sm:w-[90%] sm:max-w-[420px]">
                  <ChatWidget
                    v-if="previewWidget"
                    :widget-id="previewWidget.widgetId"
                    :api-url="config.apiBaseUrl"
                    :primary-color="formData.config.primaryColor"
                    :icon-color="formData.config.iconColor"
                    :position="formData.config.position"
                    :auto-open="formData.config.autoOpen"
                    :auto-message="formData.config.autoMessage"
                    :message-limit="formData.config.messageLimit"
                    :max-file-size="formData.config.maxFileSize"
                    :default-theme="formData.config.defaultTheme"
                    :is-preview="true"
                    :allow-file-upload="formData.config.allowFileUpload"
                    :file-upload-limit="formData.config.fileUploadLimit"
                  />
                </div>
              </div>

              <div
                v-if="!previewWidget"
                class="absolute inset-0 flex items-center justify-center bg-black/10 dark:bg-black/30 backdrop-blur-sm"
              >
                <div class="text-center txt-secondary">
                  <Icon icon="heroicons:arrow-path" class="w-8 h-8 animate-spin mx-auto mb-2" />
                  <p class="text-sm">{{ $t('widgets.loadingPreview') }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div
        class="px-4 lg:px-6 py-3 lg:py-4 border-t border-light-border/30 dark:border-dark-border/20 flex items-center justify-between gap-3"
        data-testid="section-footer"
      >
        <button
          v-if="currentStep > 0"
          class="px-4 lg:px-6 py-2 lg:py-2.5 rounded-lg hover-surface transition-colors txt-primary font-medium flex items-center gap-2 text-sm lg:text-base"
          data-testid="btn-prev"
          @click="prevStep"
        >
          <Icon icon="heroicons:arrow-left" class="w-4 h-4 lg:w-5 lg:h-5" />
          <span class="hidden sm:inline">{{ $t('common.back') }}</span>
        </button>
        <div v-else></div>

        <div class="flex items-center gap-2 lg:gap-3">
          <button
            class="px-4 lg:px-6 py-2 lg:py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium text-sm lg:text-base"
            data-testid="btn-cancel"
            @click="handleClose"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            v-if="currentStep < steps.length - 1"
            :disabled="!canProceed"
            class="btn-primary px-4 lg:px-6 py-2 lg:py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2 text-sm lg:text-base"
            data-testid="btn-next"
            @click="nextStep"
          >
            <span class="hidden sm:inline">{{ $t('common.next') }}</span>
            <span class="sm:hidden">Next</span>
            <Icon icon="heroicons:arrow-right" class="w-4 h-4 lg:w-5 lg:h-5" />
          </button>
          <button
            v-else
            :disabled="!canCreate || creating"
            class="btn-primary px-4 lg:px-6 py-2 lg:py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2 text-sm lg:text-base"
            data-testid="btn-create"
            @click="createWidget"
          >
            <Icon
              v-if="creating"
              icon="heroicons:arrow-path"
              class="w-4 h-4 lg:w-5 lg:h-5 animate-spin"
            />
            <Icon v-else icon="heroicons:check" class="w-4 h-4 lg:w-5 lg:h-5" />
            {{ creating ? $t('common.creating') : $t('common.create') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'
import ChatWidget from '@/components/widgets/ChatWidget.vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi } from '@/services/api/promptsApi'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'

const emit = defineEmits<{
  close: []
  created: []
}>()

const config = useConfigStore()
const { error: showError } = useNotification()
const { t } = useI18n()

const currentStep = ref(0)
const creating = ref(false)
const taskPrompts = ref<any[]>([])
const previewWidget = ref<widgetsApi.Widget | null>(null)
const isCreatingPreview = ref(false)
const previewWebsite = ref('')
const newAllowedDomain = ref('')
const allowedDomainError = ref<string | null>(null)
const sanitizedPreviewUrl = computed(() => {
  const url = previewWebsite.value.trim()
  if (!url) return ''
  return /^https?:\/\//i.test(url) ? url : `https://${url}`
})

// Filter out system prompts - only show custom prompts for widgets
const customTaskPrompts = computed(() => {
  return taskPrompts.value.filter((prompt) => !prompt.isDefault)
})

const steps = [
  { label: 'Basics', icon: 'heroicons:information-circle' },
  { label: 'Appearance', icon: 'heroicons:paint-brush' },
  { label: 'Behavior', icon: 'heroicons:adjustments-horizontal' },
  { label: 'Review', icon: 'heroicons:check-circle' },
]

type WidgetConfig = Required<NonNullable<widgetsApi.CreateWidgetRequest['config']>>
interface WidgetFormData {
  name: string
  taskPromptTopic: string
  config: WidgetConfig
}

const formData = ref<WidgetFormData>({
  name: '',
  taskPromptTopic: '',
  config: {
    position: 'bottom-right',
    primaryColor: '#007bff',
    iconColor: '#ffffff',
    buttonIcon: 'chat',
    buttonIconUrl: '',
    defaultTheme: 'light',
    autoOpen: false,
    autoMessage: 'Hello! How can I help you today?',
    messageLimit: 50,
    maxFileSize: 10,
    allowFileUpload: false,
    fileUploadLimit: 3,
    allowedDomains: [],
  },
})

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return formData.value.name.trim() !== '' && formData.value.taskPromptTopic !== ''
  }
  return true
})

const canCreate = computed(() => {
  return canProceed.value && !creating.value
})

const MAX_ALLOWED_DOMAINS = 20
const LOCAL_TEST_DOMAINS = ['localhost', '127.0.0.1', 'localhost:5173']

const sanitizeDomainList = (domains: unknown): string[] => {
  if (!Array.isArray(domains)) return []
  const sanitized: string[] = []
  domains.forEach((value) => {
    if (typeof value !== 'string') return
    const normalized = sanitizeDomainInput(value)
    if (normalized && !sanitized.includes(normalized)) {
      sanitized.push(normalized)
    }
  })
  return sanitized
}

const arraysEqual = (a: string[], b: string[]): boolean => {
  if (a.length !== b.length) return false
  return a.every((value, index) => value === b[index])
}

const sanitizeDomainInput = (value: string): string | null => {
  if (!value) return null
  let normalized = value.trim().toLowerCase()
  if (!normalized) return null
  normalized = normalized.replace(/^https?:\/\//, '')
  normalized = normalized.replace(/^\/\//, '')
  normalized = normalized.split(/[\/?#]/)[0]
  if (!normalized) return null
  const domainPattern = /^(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::\d+)?$/
  if (!domainPattern.test(normalized)) {
    return null
  }
  return normalized
}

const isLocalTestingDomain = (domain: string): boolean => {
  const value = domain.toLowerCase()
  return LOCAL_TEST_DOMAINS.some((pattern) => value === pattern || value.startsWith(`${pattern}:`))
}

const ensureSanitizedAllowedDomains = (): string[] => {
  const sanitized = sanitizeDomainList(formData.value.config.allowedDomains)
  if (!arraysEqual(sanitized, formData.value.config.allowedDomains)) {
    formData.value.config.allowedDomains = sanitized
  }
  return sanitized
}

const pushAllowedDomain = (value: string) => {
  const sanitized = sanitizeDomainInput(value)
  if (!sanitized) return
  if (!formData.value.config.allowedDomains.includes(sanitized)) {
    formData.value.config.allowedDomains.push(sanitized)
  }
}

const addAllowedDomain = () => {
  allowedDomainError.value = null

  if (formData.value.config.allowedDomains.length >= MAX_ALLOWED_DOMAINS) {
    allowedDomainError.value = t('widgets.allowedDomainsLimit', { max: MAX_ALLOWED_DOMAINS })
    return
  }

  const sanitized = sanitizeDomainInput(newAllowedDomain.value)
  if (!sanitized) {
    allowedDomainError.value = t('widgets.invalidDomain')
    return
  }

  if (formData.value.config.allowedDomains.includes(sanitized)) {
    allowedDomainError.value = t('widgets.domainAlreadyAdded')
    return
  }

  formData.value.config.allowedDomains.push(sanitized)
  newAllowedDomain.value = ''
}

const removeAllowedDomain = (domain: string) => {
  formData.value.config.allowedDomains = formData.value.config.allowedDomains.filter(
    (item) => item !== domain
  )
}

const addPredefinedDomain = (domain: string) => {
  pushAllowedDomain(domain)
}

const allowedDomainsList = computed(() => formData.value.config.allowedDomains ?? [])

const hasLocalTestingDomain = computed(() =>
  allowedDomainsList.value.some((domain) => isLocalTestingDomain(domain))
)

watch(newAllowedDomain, () => {
  if (allowedDomainError.value) {
    allowedDomainError.value = null
  }
})

/**
 * Create a temporary preview widget when user provides basic info
 */
const createPreviewWidget = async () => {
  if (!canProceed.value || isCreatingPreview.value || previewWidget.value) {
    return
  }

  isCreatingPreview.value = true
  try {
    const tempWidget = await widgetsApi.createWidget({
      name: `[PREVIEW] ${formData.value.name}`,
      taskPromptTopic: formData.value.taskPromptTopic,
      config: formData.value.config,
    })
    previewWidget.value = tempWidget
    console.log('✅ Preview widget created:', tempWidget.widgetId)
  } catch (error: any) {
    console.error('Failed to create preview widget:', error)
    // Don't show error to user - preview is optional
  } finally {
    isCreatingPreview.value = false
  }
}

/**
 * Update preview widget configuration when form changes
 */
watch(
  () => formData.value.config,
  async (newConfig) => {
    if (previewWidget.value) {
      try {
        const sanitizedDomains = ensureSanitizedAllowedDomains()
        await widgetsApi.updateWidget(previewWidget.value.widgetId, {
          config: { ...newConfig, allowedDomains: sanitizedDomains },
        })
      } catch (error) {
        console.error('Failed to update preview widget:', error)
      }
    }
  },
  { deep: true }
)

/**
 * Delete preview widget if user cancels
 */
const cleanupPreview = async () => {
  if (previewWidget.value) {
    try {
      await widgetsApi.deleteWidget(previewWidget.value.widgetId)
      console.log('🗑️ Preview widget deleted')
    } catch (error) {
      console.error('Failed to delete preview widget:', error)
    }
  }
}

const nextStep = async () => {
  if (currentStep.value < steps.length - 1 && canProceed.value) {
    currentStep.value++

    // Create preview widget when moving from step 0 to step 1
    if (currentStep.value === 1 && !previewWidget.value) {
      await createPreviewWidget()
    }
  }
}

const prevStep = () => {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}

const createWidget = async () => {
  if (!canCreate.value) return

  creating.value = true
  try {
    console.log('Creating widget with data:', JSON.stringify(formData.value, null, 2))

    const sanitizedDomains = ensureSanitizedAllowedDomains()
    const payloadConfig = {
      ...formData.value.config,
      allowedDomains: sanitizedDomains,
    }

    // If preview widget exists, update it to be the real widget
    if (previewWidget.value) {
      await widgetsApi.updateWidget(previewWidget.value.widgetId, {
        name: formData.value.name, // Remove [PREVIEW] prefix
        config: payloadConfig,
        status: 'active',
      })
      previewWidget.value = null // Prevent cleanup on close
    } else {
      // No preview widget, create new one
      await widgetsApi.createWidget({
        name: formData.value.name,
        taskPromptTopic: formData.value.taskPromptTopic,
        config: payloadConfig,
      })
    }

    emit('created')
  } catch (error: any) {
    console.error('Widget creation failed:', error)
    showError(error.message || 'Failed to create widget')
  } finally {
    creating.value = false
  }
}

const handleClose = async () => {
  await cleanupPreview()
  emit('close')
}

const loadTaskPrompts = async () => {
  try {
    taskPrompts.value = await promptsApi.listPrompts()
  } catch (error) {
    console.error('Failed to load task prompts:', error)
  }
}

onMounted(() => {
  loadTaskPrompts()
})

onBeforeUnmount(() => {
  cleanupPreview()
})
</script>
