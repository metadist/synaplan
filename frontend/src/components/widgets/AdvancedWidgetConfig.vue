<template>
  <Teleport to="#app">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4"
      data-testid="modal-advanced-config"
      @click.self="handleClose"
    >
      <div
        class="surface-card rounded-xl sm:rounded-2xl w-full max-w-4xl max-h-[95vh] sm:max-h-[90vh] overflow-hidden shadow-2xl flex flex-col"
        data-testid="section-config-container"
      >
        <!-- Header -->
        <div
          class="px-4 sm:px-6 py-3 sm:py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between flex-shrink-0 gap-3"
        >
          <div class="min-w-0 flex-1">
            <h2 class="text-lg sm:text-xl font-semibold txt-primary flex items-center gap-2">
              <Icon
                :icon="promptOnly ? 'heroicons:sparkles' : 'heroicons:cog-6-tooth'"
                class="w-5 h-5 sm:w-6 sm:h-6 txt-brand flex-shrink-0"
              />
              <span class="truncate">
                {{
                  promptOnly
                    ? $t('widgets.advancedConfig.editPrompt')
                    : $t('widgets.advancedConfig.title')
                }}
              </span>
            </h2>
            <p class="text-sm txt-secondary mt-0.5 sm:mt-1 truncate">
              {{ widget.name }}
            </p>
          </div>
          <button
            class="w-9 h-9 sm:w-10 sm:h-10 rounded-lg hover-surface transition-colors flex items-center justify-center flex-shrink-0"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-5 h-5 sm:w-6 sm:h-6 txt-secondary" />
          </button>
        </div>

        <!-- Tabs (hidden in promptOnly mode) -->
        <div
          v-if="!promptOnly"
          class="px-2 sm:px-6 border-b border-light-border/30 dark:border-dark-border/20 flex-shrink-0 overflow-x-auto"
        >
          <div class="flex gap-0 sm:gap-1">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              :class="[
                'flex-1 sm:flex-none px-2 sm:px-4 py-3 font-medium text-xs sm:text-sm transition-colors relative whitespace-nowrap',
                activeTab === tab.id ? 'txt-primary' : 'txt-secondary hover:txt-primary',
              ]"
              data-testid="btn-tab"
              @click="activeTab = tab.id"
            >
              <span class="flex items-center justify-center sm:justify-start gap-1 sm:gap-2">
                <Icon :icon="tab.icon" class="w-4 h-4 flex-shrink-0" />
                <span class="truncate">{{ $t(tab.labelKey) }}</span>
              </span>
              <div
                v-if="activeTab === tab.id"
                class="absolute bottom-0 left-0 right-0 h-0.5 bg-[var(--brand)]"
              ></div>
            </button>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto scroll-thin p-4 sm:p-6">
          <!-- Branding Tab (hidden in promptOnly mode) -->
          <div
            v-if="!promptOnly && activeTab === 'branding'"
            class="space-y-6"
            data-testid="section-branding"
          >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.position') }}
                </label>
                <select
                  v-model="config.position"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
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
                  {{ $t('widgets.advancedConfig.theme') }}
                </label>
                <select
                  v-model="config.defaultTheme"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-theme"
                >
                  <option value="light">{{ $t('widgets.light') }}</option>
                  <option value="dark">{{ $t('widgets.dark') }}</option>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.primaryColor') }}
                </label>
                <div class="flex items-center gap-3">
                  <input
                    v-model="config.primaryColor"
                    type="color"
                    class="w-12 h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                    data-testid="input-primary-color"
                  />
                  <input
                    v-model="config.primaryColor"
                    type="text"
                    class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono text-sm"
                  />
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.iconColor') }}
                </label>
                <div class="flex items-center gap-3">
                  <input
                    v-model="config.iconColor"
                    type="color"
                    class="w-12 h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
                    data-testid="input-icon-color"
                  />
                  <input
                    v-model="config.iconColor"
                    type="text"
                    class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono text-sm"
                  />
                </div>
              </div>
            </div>

            <!-- Button Icon Selection -->
            <div class="mt-6">
              <label class="block text-sm font-medium txt-primary mb-3">
                {{ $t('widgets.buttonIcon') }}
              </label>

              <!-- Predefined Icons + Custom Icon Option -->
              <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 sm:gap-3 mb-4">
                <!-- Standard Icons -->
                <button
                  v-for="icon in predefinedIcons"
                  :key="icon.value"
                  type="button"
                  :class="[
                    'p-4 rounded-lg border-2 transition-all flex flex-col items-center gap-2',
                    config.buttonIcon === icon.value
                      ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                      : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
                  ]"
                  :title="icon.label"
                  data-testid="btn-icon"
                  @click="selectIcon(icon.value)"
                >
                  <div
                    class="w-12 h-12 rounded-full flex items-center justify-center"
                    :style="{ backgroundColor: config.primaryColor }"
                  >
                    <div v-html="getIconPreview(icon.value)"></div>
                  </div>
                  <span class="text-xs txt-secondary">{{ icon.label }}</span>
                </button>

                <!-- Custom Icon Option (only shown when a custom icon is uploaded) -->
                <button
                  v-if="config.buttonIconUrl"
                  type="button"
                  :class="[
                    'p-4 rounded-lg border-2 transition-all flex flex-col items-center gap-2',
                    config.buttonIcon === 'custom'
                      ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                      : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50',
                  ]"
                  :title="$t('widgets.customIcon')"
                  data-testid="btn-icon-custom"
                  @click="selectIcon('custom')"
                >
                  <div
                    class="w-12 h-12 rounded-full flex items-center justify-center overflow-hidden"
                    :style="{ backgroundColor: config.primaryColor }"
                  >
                    <img :src="config.buttonIconUrl" alt="Custom" class="w-8 h-8 object-contain" />
                  </div>
                  <span class="text-xs txt-secondary">Custom</span>
                </button>
              </div>

              <!-- Custom Icon Upload -->
              <div class="mt-4">
                <label class="block text-sm font-medium txt-secondary mb-2">
                  {{ $t('widgets.customIcon') }}
                </label>
                <div class="flex gap-3">
                  <input
                    ref="iconUploadInput"
                    type="file"
                    accept="image/svg+xml,image/png,image/jpeg,image/gif,image/webp"
                    class="hidden"
                    data-testid="input-icon-upload"
                    @change="handleIconUpload"
                  />
                  <button
                    type="button"
                    :disabled="uploadingIcon"
                    class="flex-1 px-4 py-2 border-2 border-dashed border-light-border/30 dark:border-dark-border/20 rounded-lg hover:border-[var(--brand)]/50 transition-colors txt-secondary hover:txt-primary flex items-center justify-center gap-2 disabled:opacity-50"
                    data-testid="btn-upload-icon"
                    @click="triggerIconUpload"
                  >
                    <Icon
                      v-if="uploadingIcon"
                      icon="heroicons:arrow-path"
                      class="w-5 h-5 animate-spin"
                    />
                    <Icon v-else icon="heroicons:arrow-up-tray" class="w-5 h-5" />
                    {{ config.buttonIconUrl ? $t('widgets.changeIcon') : $t('widgets.uploadIcon') }}
                  </button>
                  <button
                    v-if="config.buttonIconUrl"
                    type="button"
                    class="px-4 py-2 bg-red-500/10 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-500/20 transition-colors"
                    data-testid="btn-remove-icon"
                    @click="removeCustomIcon"
                  >
                    <Icon icon="heroicons:trash" class="w-5 h-5" />
                  </button>
                </div>
                <div
                  v-if="config.buttonIconUrl"
                  class="mt-3 p-3 rounded-lg flex items-center gap-3"
                  :class="config.buttonIcon === 'custom' ? 'bg-green-500/10' : 'bg-gray-500/10'"
                >
                  <div
                    class="w-10 h-10 rounded-full flex items-center justify-center overflow-hidden"
                    :style="{ backgroundColor: config.primaryColor }"
                  >
                    <img
                      :src="config.buttonIconUrl"
                      alt="Custom Icon"
                      class="w-6 h-6 object-contain"
                    />
                  </div>
                  <div class="flex-1">
                    <span
                      v-if="config.buttonIcon === 'custom'"
                      class="text-sm text-green-700 dark:text-green-400"
                    >
                      {{ $t('widgets.customIconActive') }}
                    </span>
                    <span v-else class="text-sm txt-secondary">
                      {{ $t('widgets.customIconAvailable') }}
                    </span>
                  </div>
                </div>
                <p class="text-xs txt-secondary mt-2">{{ $t('widgets.customIconHint') }}</p>
              </div>
            </div>
          </div>

          <!-- Behavior Tab (hidden in promptOnly mode) -->
          <div
            v-else-if="!promptOnly && activeTab === 'behavior'"
            class="space-y-6"
            data-testid="section-behavior"
          >
            <!-- Widget Active Status -->
            <label
              class="surface-chip p-4 rounded-lg flex items-center justify-between cursor-pointer hover:opacity-80 transition-opacity"
              :class="widgetStatus === 'inactive' ? 'border-2 border-yellow-500/50' : ''"
            >
              <div>
                <p class="font-medium txt-primary">
                  {{ $t('widgets.advancedConfig.widgetActive') }}
                </p>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.widgetActiveHelp') }}
                </p>
                <p
                  v-if="widgetStatus === 'inactive'"
                  class="text-xs text-yellow-600 dark:text-yellow-400 mt-2 font-medium"
                >
                  {{ $t('widgets.advancedConfig.widgetInactiveWarning') }}
                </p>
              </div>
              <div class="relative inline-flex items-center flex-shrink-0">
                <input
                  :checked="widgetStatus === 'active'"
                  type="checkbox"
                  class="sr-only peer"
                  @change="
                    widgetStatus = ($event.target as HTMLInputElement).checked
                      ? 'active'
                      : 'inactive'
                  "
                />
                <div
                  class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                ></div>
              </div>
            </label>

            <label
              class="surface-chip p-4 rounded-lg flex items-center justify-between cursor-pointer hover:opacity-80 transition-opacity"
            >
              <div>
                <p class="font-medium txt-primary">{{ $t('widgets.advancedConfig.autoOpen') }}</p>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.autoOpenHelp') }}
                </p>
              </div>
              <div class="relative inline-flex items-center flex-shrink-0">
                <input v-model="config.autoOpen" type="checkbox" class="sr-only peer" />
                <div
                  class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                ></div>
              </div>
            </label>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.advancedConfig.autoMessage') }}
              </label>
              <textarea
                v-model="config.autoMessage"
                rows="3"
                class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
                data-testid="input-auto-message"
              ></textarea>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.advancedConfig.messageLimit') }}
              </label>
              <input
                v-model.number="config.messageLimit"
                type="number"
                min="1"
                max="100"
                class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-message-limit"
              />
              <p class="text-xs txt-secondary mt-1">
                {{ $t('widgets.advancedConfig.messageLimitHelp') }}
              </p>
            </div>

            <div class="surface-chip rounded-lg">
              <label
                class="p-4 flex items-center justify-between cursor-pointer hover:opacity-80 transition-opacity"
              >
                <div>
                  <p class="font-medium txt-primary">
                    {{ $t('widgets.advancedConfig.allowFileUpload') }}
                  </p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.allowFileUploadHelp') }}
                  </p>
                </div>
                <div class="relative inline-flex items-center flex-shrink-0">
                  <input v-model="config.allowFileUpload" type="checkbox" class="sr-only peer" />
                  <div
                    class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                  ></div>
                </div>
              </label>

              <div v-if="config.allowFileUpload" class="px-4 pb-4">
                <!-- File Upload Limit & Max File Size - side by side -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium txt-primary mb-2">
                      {{ $t('widgets.advancedConfig.fileUploadLimit') }}
                    </label>
                    <input
                      v-model.number="config.fileUploadLimit"
                      type="number"
                      min="0"
                      max="20"
                      class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                      data-testid="input-file-limit"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium txt-primary mb-2">
                      {{ $t('widgets.advancedConfig.maxFileSize') }}
                    </label>
                    <input
                      :value="config.maxFileSize"
                      type="number"
                      min="1"
                      max="50"
                      class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                      data-testid="input-max-file-size"
                      @input="handleMaxFileSizeInput"
                    />
                    <p class="text-xs txt-secondary mt-1">
                      {{ $t('widgets.advancedConfig.maxFileSizeHelp') }}
                    </p>
                  </div>
                </div>
                <!-- Unlimited Warning -->
                <div
                  v-if="config.fileUploadLimit === 0"
                  class="mt-3 p-3 rounded-lg bg-yellow-500/10 border border-yellow-500/30"
                >
                  <div class="flex items-start gap-2">
                    <Icon
                      icon="heroicons:exclamation-triangle"
                      class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5"
                    />
                    <div>
                      <p class="text-sm font-medium text-yellow-700 dark:text-yellow-300">
                        {{ $t('widgets.advancedConfig.fileUploadUnlimitedTitle') }}
                      </p>
                      <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
                        {{ $t('widgets.advancedConfig.fileUploadUnlimitedDescription') }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Security Tab (hidden in promptOnly mode) -->
          <div
            v-else-if="!promptOnly && activeTab === 'security'"
            class="space-y-6"
            data-testid="section-security"
          >
            <div class="surface-chip p-4 rounded-lg space-y-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-medium txt-primary">
                    {{ $t('widgets.advancedConfig.allowedDomains') }}
                  </p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.allowedDomainsHelp') }}
                  </p>
                </div>
                <Icon icon="heroicons:shield-check" class="w-8 h-8 txt-secondary opacity-60" />
              </div>

              <div class="flex flex-col sm:flex-row gap-2">
                <input
                  v-model="newDomain"
                  type="text"
                  placeholder="example.com"
                  class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-domain"
                  @keydown.enter.prevent="addDomain"
                />
                <button
                  class="btn-primary px-4 py-2.5 rounded-lg font-medium flex items-center justify-center gap-2 w-full sm:w-auto"
                  data-testid="btn-add-domain"
                  @click="addDomain"
                >
                  <Icon icon="heroicons:plus" class="w-4 h-4" />
                  {{ $t('common.add') }}
                </button>
              </div>

              <div v-if="config.allowedDomains?.length" class="flex flex-wrap gap-2">
                <span
                  v-for="domain in config.allowedDomains"
                  :key="domain"
                  class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium bg-[var(--brand-alpha-light)] txt-primary border border-[var(--brand)]/20"
                >
                  {{ domain }}
                  <button
                    class="w-4 h-4 flex items-center justify-center rounded-full hover:bg-black/10 dark:hover:bg-white/10"
                    @click="removeDomain(domain)"
                  >
                    <Icon icon="heroicons:x-mark" class="w-3 h-3" />
                  </button>
                </span>
              </div>
              <p v-else class="text-xs txt-secondary">
                {{ $t('widgets.advancedConfig.noDomainsYet') }}
              </p>

              <!-- Localhost Warning -->
              <div
                v-if="hasLocalhostInDomains"
                class="mt-3 p-3 rounded-lg bg-yellow-500/10 border border-yellow-500/30"
              >
                <div class="flex items-start gap-2">
                  <Icon
                    icon="heroicons:exclamation-triangle"
                    class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5"
                  />
                  <div>
                    <p class="text-sm font-medium text-yellow-700 dark:text-yellow-300">
                      {{ $t('widgets.advancedConfig.localhostWarningTitle') }}
                    </p>
                    <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
                      {{ $t('widgets.advancedConfig.localhostWarningDescription') }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- AI Assistant Tab -->
          <div
            v-if="promptOnly || activeTab === 'assistant'"
            class="space-y-6"
            data-testid="section-assistant"
          >
            <!-- Not Configured State (hidden in promptOnly mode) -->
            <div
              v-if="
                !promptOnly && !hasCustomPrompt && !creatingManualPrompt && !manualPromptCreated
              "
              class="flex flex-col items-center justify-center py-12 text-center"
            >
              <div
                class="w-20 h-20 rounded-full bg-[var(--brand-alpha-light)] flex items-center justify-center mb-6"
              >
                <Icon icon="heroicons:sparkles" class="w-10 h-10 txt-brand" />
              </div>
              <h3 class="text-lg font-semibold txt-primary mb-2">
                {{ $t('widgets.advancedConfig.aiSetupRequired') }}
              </h3>
              <p class="text-sm txt-secondary max-w-md mb-6">
                {{ $t('widgets.advancedConfig.aiSetupRequiredDescription') }}
              </p>
              <div class="flex flex-col sm:flex-row gap-3">
                <button
                  type="button"
                  class="btn-primary px-6 py-3 rounded-lg font-medium flex items-center justify-center gap-2"
                  data-testid="btn-start-ai-setup"
                  @click="emit('startAiSetup')"
                >
                  <Icon icon="heroicons:sparkles" class="w-5 h-5" />
                  {{ $t('widgets.advancedConfig.startAiSetup') }}
                </button>
                <button
                  type="button"
                  class="px-6 py-3 rounded-lg font-medium flex items-center justify-center gap-2 border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                  data-testid="btn-manual-create"
                  @click="handleManualCreate"
                >
                  <Icon icon="heroicons:pencil-square" class="w-5 h-5" />
                  {{ $t('widgets.advancedConfig.manualCreate') }}
                </button>
              </div>
            </div>

            <!-- Creating Manual Prompt Loading State (hidden in promptOnly mode) -->
            <div
              v-else-if="!promptOnly && creatingManualPrompt"
              class="flex flex-col items-center justify-center py-12"
            >
              <Icon icon="heroicons:arrow-path" class="w-8 h-8 txt-secondary animate-spin mb-4" />
              <p class="text-sm txt-secondary">{{ $t('widgets.advancedConfig.creatingPrompt') }}</p>
            </div>

            <!-- Configured State -->
            <template v-else>
              <!-- Loading State -->
              <div v-if="promptLoading" class="flex items-center justify-center py-12">
                <Icon icon="heroicons:arrow-path" class="w-8 h-8 txt-secondary animate-spin" />
              </div>

              <!-- Error State -->
              <div
                v-else-if="promptError"
                class="p-4 rounded-lg bg-red-500/10 border border-red-500/30"
              >
                <p class="text-sm text-red-600 dark:text-red-400">{{ promptError }}</p>
              </div>

              <!-- Prompt Editor -->
              <template v-else>
                <!-- System Prompt Notice (legacy widgets using shared prompts, hidden in promptOnly mode) -->
                <div
                  v-if="!promptOnly && isSystemPrompt"
                  class="p-4 rounded-lg bg-amber-500/10 border border-amber-500/30"
                >
                  <div class="flex items-start gap-3">
                    <Icon
                      icon="heroicons:information-circle"
                      class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"
                    />
                    <div class="flex-1">
                      <p class="text-sm font-medium txt-primary">
                        {{ $t('widgets.advancedConfig.systemPromptTitle') }}
                      </p>
                      <p class="text-xs txt-secondary mt-1">
                        {{ $t('widgets.advancedConfig.systemPromptDescription') }}
                      </p>
                      <button
                        type="button"
                        class="mt-3 px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:bg-[var(--brand-hover)] transition-colors flex items-center gap-2"
                        data-testid="btn-customize-prompt"
                        @click="emit('startAiSetup')"
                      >
                        <Icon icon="heroicons:sparkles" class="w-4 h-4" />
                        {{ $t('widgets.advancedConfig.customizePrompt') }}
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Restart AI Setup Option (only show if not manually created and not system prompt, hidden in promptOnly mode) -->
                <div
                  v-else-if="!promptOnly && !manualPromptCreated"
                  class="p-4 rounded-lg bg-[var(--brand-alpha-light)] border border-[var(--brand)]/20"
                >
                  <div class="flex items-center justify-between">
                    <div class="flex items-start gap-3">
                      <Icon
                        icon="heroicons:sparkles"
                        class="w-5 h-5 txt-brand flex-shrink-0 mt-0.5"
                      />
                      <div>
                        <p class="text-sm font-medium txt-primary">
                          {{ $t('widgets.advancedConfig.restartAiSetupTitle') }}
                        </p>
                        <p class="text-xs txt-secondary mt-1">
                          {{ $t('widgets.advancedConfig.restartAiSetupDescription') }}
                        </p>
                      </div>
                    </div>
                    <button
                      type="button"
                      class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium hover:bg-[var(--brand-hover)] transition-colors flex items-center gap-2"
                      data-testid="btn-restart-ai-setup"
                      @click="emit('startAiSetup')"
                    >
                      <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
                      {{ $t('widgets.advancedConfig.restartAiSetup') }}
                    </button>
                  </div>
                </div>

                <!-- Widget Name -->
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                    <Icon icon="heroicons:tag" class="w-4 h-4" />
                    {{ $t('widgets.widgetName') }}
                  </label>
                  <input
                    v-model="widgetName"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="input-widget-name"
                    :placeholder="$t('widgets.widgetNamePlaceholder')"
                  />
                </div>

                <!-- AI Model Selection -->
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                    <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
                    {{ $t('widgets.advancedConfig.aiModel') }}
                  </label>
                  <select
                    v-model="promptData.aiModel"
                    :disabled="isSystemPrompt"
                    :class="[
                      'w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]',
                      isSystemPrompt ? 'bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : '',
                    ]"
                    data-testid="input-ai-model"
                  >
                    <option
                      value="AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]"
                    >
                      ✨ {{ $t('widgets.advancedConfig.automated') }}
                    </option>
                    <template v-if="!loadingModels && groupedModels.length > 0">
                      <optgroup
                        v-for="group in groupedModels"
                        :key="group.capability"
                        :label="group.label"
                      >
                        <option
                          v-for="model in group.models"
                          :key="model.id"
                          :value="`${model.name} (${model.service})`"
                        >
                          {{ model.name }} ({{ model.service }})
                          <template v-if="model.rating">⭐ {{ model.rating.toFixed(1) }}</template>
                        </option>
                      </optgroup>
                    </template>
                    <option v-if="loadingModels" disabled>Loading models...</option>
                  </select>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.aiModelHelp') }}
                  </p>
                </div>

                <!-- Prompt Language -->
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                    <Icon icon="heroicons:language" class="w-4 h-4" />
                    {{ $t('widgets.advancedConfig.promptLanguage') }}
                  </label>
                  <select
                    v-model="promptLanguage"
                    :disabled="isSystemPrompt"
                    :class="[
                      'w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]',
                      isSystemPrompt ? 'bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : '',
                    ]"
                    data-testid="input-prompt-language"
                  >
                    <option value="en">English</option>
                    <option value="de">Deutsch</option>
                    <option value="fr">Français</option>
                    <option value="es">Español</option>
                    <option value="it">Italiano</option>
                    <option value="nl">Nederlands</option>
                    <option value="pt">Português</option>
                    <option value="ru">Русский</option>
                    <option value="sv">Svenska</option>
                    <option value="tr">Türkçe</option>
                  </select>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.promptLanguageHelp') }}
                  </p>
                </div>

                <!-- Prompt Content -->
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                    <Icon icon="heroicons:document-text" class="w-4 h-4" />
                    {{ $t('widgets.advancedConfig.promptContent') }}
                  </label>
                  <textarea
                    v-model="promptData.content"
                    rows="12"
                    :readonly="isSystemPrompt"
                    :class="[
                      'w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y font-mono text-sm',
                      isSystemPrompt ? 'bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : '',
                    ]"
                    :placeholder="$t('widgets.advancedConfig.promptContentPlaceholder')"
                    data-testid="input-prompt-content"
                  ></textarea>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.promptContentHelp') }}
                  </p>
                </div>

                <!-- Knowledge Base / File Upload -->
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                    <Icon icon="heroicons:document-arrow-up" class="w-4 h-4" />
                    {{ $t('widgets.advancedConfig.knowledgeBase') }}
                  </label>
                  <p class="text-xs txt-secondary mb-4">
                    {{ $t('widgets.advancedConfig.knowledgeBaseDescription') }}
                  </p>

                  <!-- File Actions -->
                  <div class="flex gap-3 mb-4">
                    <!-- Upload File Button -->
                    <label
                      class="flex-1 flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed rounded-lg cursor-pointer border-light-border/50 dark:border-dark-border/30 hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5 transition-colors"
                    >
                      <Icon
                        v-if="uploadingFile"
                        icon="heroicons:arrow-path"
                        class="w-5 h-5 txt-brand animate-spin"
                      />
                      <Icon v-else icon="heroicons:cloud-arrow-up" class="w-5 h-5 txt-secondary" />
                      <span class="text-sm txt-secondary">
                        <span v-if="uploadingFile">{{
                          $t('widgets.advancedConfig.uploadingFile')
                        }}</span>
                        <span v-else class="font-medium txt-brand">{{
                          $t('widgets.advancedConfig.uploadFiles')
                        }}</span>
                      </span>
                      <input
                        ref="fileUploadInput"
                        type="file"
                        class="hidden"
                        accept=".pdf,.doc,.docx,.txt,.md,.csv,.json"
                        multiple
                        :disabled="uploadingFile"
                        @change="handleFileUpload"
                      />
                    </label>

                    <!-- Select from File Manager Button -->
                    <button
                      type="button"
                      class="flex-1 flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed rounded-lg border-light-border/50 dark:border-dark-border/30 hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5 transition-colors"
                      @click="showFilePicker = true"
                    >
                      <Icon icon="heroicons:folder-open" class="w-5 h-5 txt-secondary" />
                      <span class="text-sm font-medium txt-brand">
                        {{ $t('widgets.advancedConfig.selectFromFileManager') }}
                      </span>
                    </button>
                  </div>

                  <!-- Files List with Summaries -->
                  <div v-if="promptFiles.length > 0" class="space-y-3">
                    <div
                      v-for="file in promptFiles"
                      :key="file.id"
                      class="p-3 rounded-lg surface-chip"
                    >
                      <!-- File Header -->
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                          <Icon
                            icon="heroicons:document"
                            class="w-5 h-5 txt-secondary flex-shrink-0"
                          />
                          <div class="min-w-0">
                            <p class="text-sm txt-primary truncate">{{ file.fileName }}</p>
                            <p v-if="file.chunks > 0" class="text-xs txt-secondary">
                              {{ file.chunks }} chunks
                            </p>
                          </div>
                        </div>
                        <div class="flex items-center gap-2">
                          <!-- Generate Summary Button -->
                          <button
                            v-if="!fileSummaries.has(file.id) && !loadingSummary.has(file.id)"
                            type="button"
                            class="p-2 rounded-lg hover:bg-[var(--brand)]/10 transition-colors"
                            :title="$t('widgets.advancedConfig.generateSummary')"
                            @click="generateFileSummary(file.id)"
                          >
                            <Icon icon="heroicons:sparkles" class="w-4 h-4 txt-brand" />
                          </button>
                          <!-- Loading Summary -->
                          <div v-if="loadingSummary.has(file.id)" class="p-2">
                            <Icon
                              icon="heroicons:arrow-path"
                              class="w-4 h-4 txt-brand animate-spin"
                            />
                          </div>
                          <!-- Delete Button -->
                          <button
                            type="button"
                            class="p-2 rounded-lg hover:bg-red-500/10 transition-colors"
                            :title="$t('widgets.advancedConfig.deleteFile')"
                            :disabled="deletingFileId === file.id"
                            @click="handleDeleteFile(file.id)"
                          >
                            <Icon
                              v-if="deletingFileId === file.id"
                              icon="heroicons:arrow-path"
                              class="w-4 h-4 text-red-500 animate-spin"
                            />
                            <Icon v-else icon="heroicons:trash" class="w-4 h-4 text-red-500" />
                          </button>
                        </div>
                      </div>

                      <!-- Summary Display -->
                      <div
                        v-if="fileSummaries.has(file.id)"
                        class="mt-2 pt-2 border-t border-light-border/30 dark:border-dark-border/20"
                      >
                        <div class="flex items-start gap-2">
                          <Icon
                            icon="heroicons:sparkles"
                            class="w-4 h-4 txt-brand flex-shrink-0 mt-0.5"
                          />
                          <p class="text-xs txt-secondary italic">
                            {{ fileSummaries.get(file.id) }}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Empty State -->
                  <div v-else class="text-center py-6 surface-chip rounded-lg">
                    <Icon
                      icon="heroicons:document-text"
                      class="w-10 h-10 txt-secondary mx-auto mb-2 opacity-50"
                    />
                    <p class="text-sm txt-secondary">
                      {{ $t('widgets.advancedConfig.noFilesYet') }}
                    </p>
                  </div>
                </div>
              </template>
            </template>
          </div>

          <!-- Summary Prompt Tab -->
          <WidgetSummaryPromptTab
            v-if="activeTab === 'ai-prompts'"
            ref="summaryPromptTab"
            :widget-id="widget.widgetId"
            :models="allModels"
            :loading-models="loadingModels"
          />
        </div>

        <!-- Footer -->
        <div
          class="px-4 sm:px-6 py-3 sm:py-4 border-t border-light-border/30 dark:border-dark-border/20 flex items-center justify-end gap-2 sm:gap-3 flex-shrink-0"
        >
          <button
            class="px-4 sm:px-5 py-2 sm:py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium text-sm sm:text-base"
            data-testid="btn-cancel"
            @click="handleClose"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            :disabled="saving"
            class="btn-primary px-4 sm:px-6 py-2 sm:py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2 text-sm sm:text-base"
            data-testid="btn-save"
            @click="handleSave"
          >
            <Icon
              v-if="saving"
              icon="heroicons:arrow-path"
              class="w-4 h-4 sm:w-5 sm:h-5 animate-spin"
            />
            <Icon v-else icon="heroicons:check" class="w-4 h-4 sm:w-5 sm:h-5" />
            {{ saving ? $t('common.saving') : $t('common.save') }}
          </button>
        </div>
      </div>
    </div>

    <!-- File Picker Modal -->
    <FilePicker
      :is-open="showFilePicker"
      :exclude-message-ids="excludedFileIds"
      @close="showFilePicker = false"
      @select="handleFilePickerSelect"
    />
  </Teleport>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi, type AvailableFile } from '@/services/api/promptsApi'
import { configApi } from '@/services/api/configApi'
import type { AIModel, Capability } from '@/types/ai-models'
import FilePicker from './FilePicker.vue'
import WidgetSummaryPromptTab from './WidgetSummaryPromptTab.vue'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(
  defineProps<{
    widget: widgetsApi.Widget
    initialTab?: string
    promptOnly?: boolean
  }>(),
  {
    promptOnly: false,
  }
)

const emit = defineEmits<{
  close: []
  saved: []
  startAiSetup: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

// Check if widget has a custom/configured prompt (not the default)
// Backward compatible: ANY topic that is not 'tools:widget-default' is considered configured
// This includes:
// - Legacy topics from before rework: 'general', 'customer-support', etc.
// - Old widget prompts: 'widget-xxx' (manually created)
// - New AI-generated prompts: 'w_xxx' (from setup interview)
const hasCustomPrompt = computed(() => {
  const topic = props.widget.taskPromptTopic
  // Only 'tools:widget-default' or empty/missing = not configured
  // Everything else = configured (has a prompt)
  return !!topic && topic !== 'tools:widget-default'
})

// Check if localhost addresses are in allowed domains
const hasLocalhostInDomains = computed(() => {
  if (!config.allowedDomains?.length) return false
  return config.allowedDomains.some(
    (domain) =>
      domain === 'localhost' ||
      domain.startsWith('localhost:') ||
      domain === '127.0.0.1' ||
      domain.startsWith('127.0.0.1:')
  )
})

const tabs = computed(() => {
  return [
    {
      id: 'branding',
      icon: 'heroicons:paint-brush',
      labelKey: 'widgets.advancedConfig.tabs.branding',
    },
    {
      id: 'behavior',
      icon: 'heroicons:adjustments-horizontal',
      labelKey: 'widgets.advancedConfig.tabs.behavior',
    },
    {
      id: 'security',
      icon: 'heroicons:shield-check',
      labelKey: 'widgets.advancedConfig.tabs.security',
    },
    {
      id: 'assistant',
      icon: 'heroicons:sparkles',
      labelKey: 'widgets.advancedConfig.tabs.assistant',
    },
    {
      id: 'ai-prompts',
      icon: 'heroicons:cpu-chip',
      labelKey: 'widgets.advancedConfig.tabs.aiPrompts',
    },
  ]
})

const activeTab = ref(props.initialTab || 'branding')
const saving = ref(false)
const newDomain = ref('')
const summaryPromptTab = ref<InstanceType<typeof WidgetSummaryPromptTab> | null>(null)

// Widget config
const config = reactive<widgetsApi.WidgetConfig>({
  position: 'bottom-right',
  primaryColor: '#007bff',
  iconColor: '#ffffff',
  buttonIcon: 'chat',
  buttonIconUrl: null as string | null,
  defaultTheme: 'light',
  autoOpen: false,
  autoMessage: '',
  messageLimit: 50,
  maxFileSize: 10,
  allowFileUpload: false,
  fileUploadLimit: 3,
  allowedDomains: [],
})

// Icon selection
const iconUploadInput = ref<HTMLInputElement | null>(null)
const uploadingIcon = ref(false)

// Handle max file size input with automatic clamping to 50
const handleMaxFileSizeInput = (event: Event) => {
  const input = event.target as HTMLInputElement
  let value = parseInt(input.value, 10)

  if (isNaN(value) || value < 1) {
    value = 1
  } else if (value > 50) {
    value = 50
  }

  config.maxFileSize = value
  input.value = String(value)
}

const predefinedIcons = [
  { value: 'chat', label: t('widgets.icons.chat') },
  { value: 'headset', label: t('widgets.icons.headset') },
  { value: 'help', label: t('widgets.icons.help') },
  { value: 'robot', label: t('widgets.icons.robot') },
  { value: 'message', label: t('widgets.icons.message') },
  { value: 'support', label: t('widgets.icons.support') },
]

const getIconPreview = (iconType: string): string => {
  const iconColor = config.iconColor || '#ffffff'
  const icons: Record<string, string> = {
    chat: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
    </svg>`,
    headset: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
      <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
    </svg>`,
    help: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <circle cx="12" cy="12" r="10"></circle>
      <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
      <line x1="12" y1="17" x2="12.01" y2="17"></line>
    </svg>`,
    robot: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <rect x="3" y="11" width="18" height="10" rx="2"></rect>
      <circle cx="12" cy="5" r="2"></circle>
      <path d="M12 7v4"></path>
      <line x1="8" y1="16" x2="8" y2="16"></line>
      <line x1="16" y1="16" x2="16" y2="16"></line>
    </svg>`,
    message: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
    </svg>`,
    support: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2">
      <circle cx="12" cy="12" r="10"></circle>
      <circle cx="12" cy="12" r="4"></circle>
      <line x1="4.93" y1="4.93" x2="9.17" y2="9.17"></line>
      <line x1="14.83" y1="14.83" x2="19.07" y2="19.07"></line>
      <line x1="14.83" y1="9.17" x2="19.07" y2="4.93"></line>
      <line x1="4.93" y1="19.07" x2="9.17" y2="14.83"></line>
    </svg>`,
  }
  return icons[iconType] || icons.chat
}

const selectIcon = (iconValue: string) => {
  config.buttonIcon = iconValue
  // Don't clear buttonIconUrl here - only the delete button should remove the custom icon
}

const triggerIconUpload = () => {
  iconUploadInput.value?.click()
}

const handleIconUpload = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]

  if (!file) return

  // Validate file type
  const allowedTypes = ['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp']
  if (!allowedTypes.includes(file.type)) {
    showError(t('widgets.invalidIconType'))
    target.value = ''
    return
  }

  // Validate file size (max 500KB)
  if (file.size > 500 * 1024) {
    showError(t('widgets.iconTooLarge'))
    target.value = ''
    return
  }

  uploadingIcon.value = true

  try {
    const uploadResult = await widgetsApi.uploadWidgetIcon(props.widget.widgetId, file)
    if (uploadResult.iconUrl) {
      config.buttonIconUrl = uploadResult.iconUrl
      config.buttonIcon = 'custom'
      success(t('widgets.iconUploadSuccess'))
    }
  } catch (err) {
    showError(t('widgets.iconUploadFailed'))
  } finally {
    uploadingIcon.value = false
    target.value = ''
  }
}

const removeCustomIcon = () => {
  config.buttonIconUrl = null // Use null instead of undefined so it gets sent to backend
  config.buttonIcon = 'chat'
  if (iconUploadInput.value) {
    iconUploadInput.value.value = ''
  }
}

// Widget name (editable in AI Assistant tab)
const widgetName = ref('')

// Widget active status
const widgetStatus = ref<'active' | 'inactive'>('active')

// Prompt config for AI Assistant tab
const promptData = reactive({
  id: 0,
  topic: '',
  name: '',
  rules: '',
  aiModel: 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
  content: '',
  isDefault: false, // true = system prompt (read-only), false = user-specific (editable)
})
const promptLoading = ref(false)
const promptError = ref<string | null>(null)
const promptLanguage = ref('en')
const creatingManualPrompt = ref(false)
const manualPromptCreated = ref(false) // Flag to show form after manual creation

// Check if the current prompt is a system prompt (not editable by user)
// System prompts are shared across all users and cannot be modified
// Users can "customize" them by running the AI setup to create their own version
const isSystemPrompt = computed(() => promptData.isDefault && promptData.id > 0)

// File upload for Knowledge Base
const fileUploadInput = ref<HTMLInputElement | null>(null)
const promptFiles = ref<{ id: number; fileName: string; chunks: number }[]>([])
const uploadingFile = ref(false)
const deletingFileId = ref<number | null>(null)

// File summaries for Knowledge Base
const showFilePicker = ref(false)
const fileSummaries = ref<Map<number, string>>(new Map())
const loadingSummary = ref<Set<number>>(new Set())
const excludedFileIds = computed(() => promptFiles.value.map((f) => f.id))

// AI Models
const allModels = ref<Partial<Record<Capability, AIModel[]>>>({})
const loadingModels = ref(false)

// Group models by capability for dropdown
const groupedModels = computed(() => {
  const groups: { label: string; models: AIModel[]; capability: Capability }[] = []

  const capabilityLabels: Record<Capability, string> = {
    CHAT: 'Chat & General AI',
    SORT: 'Message Sorting',
    TEXT2PIC: 'Image Generation',
    TEXT2VID: 'Video Generation',
    TEXT2SOUND: 'Text-to-Speech',
    SOUND2TEXT: 'Speech-to-Text',
    PIC2TEXT: 'Vision (Image Analysis)',
    VECTORIZE: 'Embedding / RAG',
    ANALYZE: 'File Analysis',
  }

  const orderedCapabilities: Capability[] = [
    'CHAT',
    'TEXT2PIC',
    'TEXT2VID',
    'TEXT2SOUND',
    'SOUND2TEXT',
    'PIC2TEXT',
    'ANALYZE',
    'VECTORIZE',
    'SORT',
  ]

  orderedCapabilities.forEach((capability) => {
    if (allModels.value[capability] && allModels.value[capability].length > 0) {
      groups.push({
        label: capabilityLabels[capability] || capability,
        models: allModels.value[capability],
        capability,
      })
    }
  })

  return groups
})

const handleClose = () => {
  emit('close')
}

// Handle manual prompt creation
const handleManualCreate = async () => {
  creatingManualPrompt.value = true

  try {
    // Create a new empty prompt for the widget
    const defaultPromptContent = `You are a helpful AI assistant for ${props.widget.name || 'this website'}.

Your role is to assist visitors with their questions and provide helpful information.

Be friendly, professional, and concise in your responses.`

    const result = await widgetsApi.generateWidgetPrompt(
      props.widget.widgetId,
      defaultPromptContent,
      []
    )

    // Update local state to show the form
    const widgetName = props.widget.name || 'this'
    promptData.id = result.promptId
    promptData.name = `${widgetName} Assistant`
    promptData.rules = ''
    promptData.aiModel =
      'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
    promptData.content = defaultPromptContent

    // Set flag to show the form (without closing modal)
    manualPromptCreated.value = true

    // Load AI models for the dropdown
    await loadAIModels()
  } catch (err: any) {
    console.error('Failed to create manual prompt:', err)
    showError(err.message || t('widgets.advancedConfig.manualCreateError'))
  } finally {
    creatingManualPrompt.value = false
  }
}

const addDomain = () => {
  const domain = newDomain.value.trim().toLowerCase()
  if (!domain) return

  if (!config.allowedDomains) {
    config.allowedDomains = []
  }

  if (!config.allowedDomains.includes(domain)) {
    config.allowedDomains.push(domain)
  }

  newDomain.value = ''
}

const removeDomain = (domain: string) => {
  if (config.allowedDomains) {
    config.allowedDomains = config.allowedDomains.filter((d) => d !== domain)
  }
}

const handleSave = async () => {
  saving.value = true
  try {
    // Build update request with config, name, and status
    const updateRequest: { config: typeof config; name?: string; status?: 'active' | 'inactive' } =
      {
        config,
      }

    // Include widget name if it was changed
    if (widgetName.value && widgetName.value !== props.widget.name) {
      updateRequest.name = widgetName.value
    }

    // Include status if it was changed
    if (widgetStatus.value !== props.widget.status) {
      updateRequest.status = widgetStatus.value
    }

    // Save widget config (and name/status)
    await widgetsApi.updateWidget(props.widget.widgetId, updateRequest)

    // Save prompt if on assistant tab and has custom prompt
    if (activeTab.value === 'assistant' && hasCustomPrompt.value && promptData.id) {
      await savePromptData()
    }

    // Save summary prompt if the tab component is mounted
    if (summaryPromptTab.value) {
      await summaryPromptTab.value.save()
    }

    success(t('widgets.advancedConfig.saveSuccess'))
    emit('saved')
  } catch (err: any) {
    console.error('Failed to save config:', err)
    showError(err.message || t('widgets.advancedConfig.saveError'))
  } finally {
    saving.value = false
  }
}

const loadAIModels = async () => {
  loadingModels.value = true
  try {
    const response = await configApi.getModels()
    if (response.success) {
      allModels.value = response.models
    }
  } catch (err: any) {
    console.error('Failed to load AI models:', err)
  } finally {
    loadingModels.value = false
  }
}

const loadPromptData = async () => {
  if (!hasCustomPrompt.value) return

  promptLoading.value = true
  promptError.value = null

  try {
    // Load ALL prompts without language filter so widget prompts always appear
    // regardless of the current UI language
    const prompts = await promptsApi.getPrompts()
    const prompt = prompts.find((p) => p.topic === props.widget.taskPromptTopic)

    if (prompt) {
      const metadata = prompt.metadata || {}

      // Track the prompt's stored language independently of the UI locale
      promptLanguage.value = prompt.language || 'en'

      // Determine AI Model string from metadata.aiModel (ID)
      let aiModelString =
        'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
      if (metadata.aiModel && metadata.aiModel > 0) {
        for (const models of Object.values(allModels.value)) {
          if (models) {
            const foundModel = models.find((m: AIModel) => m.id === metadata.aiModel)
            if (foundModel) {
              aiModelString = `${foundModel.name} (${foundModel.service})`
              break
            }
          }
        }
      }

      Object.assign(promptData, {
        id: prompt.id,
        topic: prompt.topic,
        name: prompt.shortDescription || prompt.name,
        rules: prompt.selectionRules || '',
        aiModel: aiModelString,
        content: prompt.prompt,
        isDefault: prompt.isDefault ?? false,
      })

      // Load files for this prompt
      await loadPromptFiles()
    }
  } catch (err: any) {
    console.error('Failed to load prompt:', err)
    promptError.value = err.message || 'Failed to load prompt data'
  } finally {
    promptLoading.value = false
  }
}

const loadPromptFiles = async () => {
  if (!props.widget.taskPromptTopic) return

  try {
    const files = await promptsApi.getPromptFiles(props.widget.taskPromptTopic)
    promptFiles.value = files.map((f) => ({
      id: f.messageId,
      fileName: f.fileName,
      chunks: f.chunks,
    }))
  } catch (err) {
    console.error('Failed to load prompt files:', err)
  }

  // If the API returned no files but the prompt content has a Knowledge Base section,
  // extract file names from it so they can be displayed and removed by the user
  if (promptFiles.value.length === 0) {
    extractFilesFromPromptContent()
  }
}

/**
 * Extract file entries from the Knowledge Base section in prompt content.
 * Used as a fallback when the vector storage query returns empty.
 */
const extractFilesFromPromptContent = () => {
  const content = promptData.content || ''
  const kbMatch = content.match(
    /<!-- KNOWLEDGE_BASE_START -->([\s\S]*?)<!-- KNOWLEDGE_BASE_END -->/
  )
  if (!kbMatch) return

  const kbContent = kbMatch[1]
  const fileMatches = [...kbContent.matchAll(/### (.+)\n([\s\S]*?)(?=\n### |$)/g)]

  if (fileMatches.length === 0) return

  const extracted: { id: number; fileName: string; chunks: number }[] = []
  for (let i = 0; i < fileMatches.length; i++) {
    const fileName = fileMatches[i][1].trim()
    const summary = fileMatches[i][2].trim()
    // Use negative IDs as placeholder IDs for files extracted from prompt content
    const placeholderId = -(i + 1)
    extracted.push({ id: placeholderId, fileName, chunks: 0 })
    // Also restore the summary so it can be re-saved
    if (summary) {
      fileSummaries.value.set(placeholderId, summary)
    }
  }

  if (extracted.length > 0) {
    promptFiles.value = extracted
    fileSummaries.value = new Map(fileSummaries.value)
  }
}

const handleFileUpload = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const files = input.files
  if (!files || files.length === 0 || !props.widget.taskPromptTopic) return

  uploadingFile.value = true

  // Track existing file IDs before upload
  const existingFileIds = new Set(promptFiles.value.map((f) => f.id))

  try {
    for (const file of Array.from(files)) {
      await promptsApi.uploadPromptFile(props.widget.taskPromptTopic, file)
    }

    // Reload files list
    await loadPromptFiles()
    success(t('widgets.advancedConfig.fileUploadSuccess'))

    // Generate summaries for newly added files
    for (const file of promptFiles.value) {
      if (!existingFileIds.has(file.id)) {
        generateFileSummary(file.id)
      }
    }
  } catch (err: any) {
    console.error('Failed to upload file:', err)
    showError(err.message || t('widgets.advancedConfig.fileUploadError'))
  } finally {
    uploadingFile.value = false
    // Reset input
    if (input) {
      input.value = ''
    }
  }
}

const handleDeleteFile = async (fileId: number) => {
  if (!props.widget.taskPromptTopic) return

  deletingFileId.value = fileId

  try {
    // Files with negative IDs are extracted from the prompt content (not in vector storage)
    // Only call the backend delete API for real files
    if (fileId > 0) {
      await promptsApi.deletePromptFile(props.widget.taskPromptTopic, fileId)
    }
    // Remove from local list
    promptFiles.value = promptFiles.value.filter((f) => f.id !== fileId)
    // Also remove summary and refresh prompt content
    fileSummaries.value.delete(fileId)
    refreshPromptContent()
    success(t('widgets.advancedConfig.fileDeleteSuccess'))
  } catch (err: any) {
    console.error('Failed to delete file:', err)
    showError(err.message || t('widgets.advancedConfig.fileDeleteError'))
  } finally {
    deletingFileId.value = null
  }
}

// Generate AI summary for a file
const generateFileSummary = async (fileId: number) => {
  if (!props.widget.taskPromptTopic) return

  loadingSummary.value.add(fileId)
  loadingSummary.value = new Set(loadingSummary.value)

  try {
    const { summary } = await promptsApi.summarizeFile(props.widget.taskPromptTopic, fileId)
    fileSummaries.value.set(fileId, summary)
    fileSummaries.value = new Map(fileSummaries.value)
    refreshPromptContent()
  } catch (err: any) {
    console.error('Failed to generate summary:', err)
    showError(err.message || t('widgets.advancedConfig.summaryError'))
  } finally {
    loadingSummary.value.delete(fileId)
    loadingSummary.value = new Set(loadingSummary.value)
  }
}

// Handle file selection from FilePicker
const handleFilePickerSelect = async (files: AvailableFile[]) => {
  if (!props.widget.taskPromptTopic) return

  for (const file of files) {
    try {
      // Link file to prompt
      await promptsApi.linkFileToPrompt(props.widget.taskPromptTopic, file.messageId)

      // Add to local list
      promptFiles.value.push({
        id: file.messageId,
        fileName: file.fileName,
        chunks: file.chunks,
      })

      // Generate summary for the file
      generateFileSummary(file.messageId)
    } catch (err: any) {
      console.error('Failed to link file:', err)
      showError(err.message || t('widgets.advancedConfig.linkFileError'))
    }
  }

  if (files.length > 0) {
    success(t('widgets.advancedConfig.filesAddedSuccess', { count: files.length }))
  }
}

// Build Knowledge Base section from file summaries
// Uses explicit markers so only the generated block is replaced on save
const buildKnowledgeBaseSection = (): string => {
  const filesWithSummaries = promptFiles.value.filter((f) => fileSummaries.value.has(f.id))

  if (filesWithSummaries.length === 0) {
    return ''
  }

  let section =
    '\n\n<!-- KNOWLEDGE_BASE_START -->\n' +
    '## Knowledge Base\n' +
    'The following documents are available for reference:\n'

  for (const file of filesWithSummaries) {
    const summary = fileSummaries.value.get(file.id)
    section += `\n### ${file.fileName}\n${summary}\n`
  }

  section += '<!-- KNOWLEDGE_BASE_END -->\n'
  return section
}

// Update the visible prompt content with the latest Knowledge Base section
const refreshPromptContent = () => {
  const base = removeKnowledgeBaseSection(promptData.content)
  promptData.content = base + buildKnowledgeBaseSection()
}

// Remove existing Knowledge Base section from prompt content
const removeKnowledgeBaseSection = (content: string): string => {
  // First, remove any Knowledge Base block delimited by explicit markers
  let updated = content.replace(
    /\n?\s*<!-- KNOWLEDGE_BASE_START -->[\s\S]*?<!-- KNOWLEDGE_BASE_END -->\n?/,
    ''
  )

  // Backwards compatibility: also remove older Knowledge Base sections that were not wrapped
  // in markers. Limit removal to the next top-level heading or end of content.
  const legacyRegex = /\n\n## Knowledge Base\n[\s\S]*?(?=\n## |\n$|$)/
  updated = updated.replace(legacyRegex, '')

  return updated
}

const savePromptData = async () => {
  if (!promptData.id) return

  // Build metadata object
  const metadata: Record<string, any> = {}

  // Parse AI Model from dropdown string back to ID
  if (
    promptData.aiModel !==
    'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
  ) {
    for (const models of Object.values(allModels.value)) {
      if (models) {
        const foundModel = models.find(
          (m: AIModel) => `${m.name} (${m.service})` === promptData.aiModel
        )
        if (foundModel) {
          metadata.aiModel = foundModel.id
          break
        }
      }
    }
  } else {
    metadata.aiModel = -1
  }

  // Build final prompt content with Knowledge Base section
  let finalContent = removeKnowledgeBaseSection(promptData.content)
  finalContent += buildKnowledgeBaseSection()

  await promptsApi.updatePrompt(promptData.id, {
    shortDescription: promptData.name,
    prompt: finalContent,
    language: promptLanguage.value,
    selectionRules: promptData.rules || null,
    metadata,
  })

  // Update local state with final content
  promptData.content = finalContent
}

onMounted(async () => {
  // Set loading state immediately if we have a custom prompt to prevent flicker
  if (hasCustomPrompt.value) {
    promptLoading.value = true
  }

  // Load current widget name and status
  widgetName.value = props.widget.name || ''
  widgetStatus.value = props.widget.status || 'active'

  // Load current config from widget
  const widgetConfig = props.widget.config || {}
  Object.assign(config, {
    position: widgetConfig.position || 'bottom-right',
    primaryColor: widgetConfig.primaryColor || '#007bff',
    iconColor: widgetConfig.iconColor || '#ffffff',
    buttonIcon: widgetConfig.buttonIcon || 'chat',
    buttonIconUrl: widgetConfig.buttonIconUrl || null,
    defaultTheme: widgetConfig.defaultTheme || 'light',
    autoOpen: widgetConfig.autoOpen || false,
    autoMessage: widgetConfig.autoMessage || '',
    messageLimit: widgetConfig.messageLimit || 50,
    maxFileSize: widgetConfig.maxFileSize || 10,
    allowFileUpload: widgetConfig.allowFileUpload || false,
    fileUploadLimit: widgetConfig.fileUploadLimit ?? 3,
    allowedDomains: widgetConfig.allowedDomains || props.widget.allowedDomains || [],
  })

  // Load AI models and prompt data if has custom prompt
  if (hasCustomPrompt.value) {
    await loadAIModels()
    await loadPromptData()
  }
})
</script>
