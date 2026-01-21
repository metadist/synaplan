<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-advanced-config"
      @click.self="handleClose"
    >
      <div
        class="surface-card rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col"
        data-testid="section-config-container"
      >
        <!-- Header -->
        <div
          class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between flex-shrink-0"
        >
          <div>
            <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:cog-6-tooth" class="w-6 h-6 txt-brand" />
              {{ $t('widgets.advancedConfig.title') }}
            </h2>
            <p class="text-sm txt-secondary mt-1">
              {{ widget.name }}
            </p>
          </div>
          <button
            class="w-10 h-10 rounded-lg hover-surface transition-colors flex items-center justify-center"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
          </button>
        </div>

        <!-- Tabs -->
        <div class="px-6 border-b border-light-border/30 dark:border-dark-border/20 flex-shrink-0">
          <div class="flex gap-1">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              :class="[
                'px-4 py-3 font-medium text-sm transition-colors relative',
                activeTab === tab.id ? 'txt-primary' : 'txt-secondary hover:txt-primary',
              ]"
              data-testid="btn-tab"
              @click="activeTab = tab.id"
            >
              <span class="flex items-center gap-2">
                <Icon :icon="tab.icon" class="w-4 h-4" />
                {{ $t(tab.labelKey) }}
              </span>
              <div
                v-if="activeTab === tab.id"
                class="absolute bottom-0 left-0 right-0 h-0.5 bg-[var(--brand)]"
              ></div>
            </button>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto scroll-thin p-6">
          <!-- Branding Tab -->
          <div v-if="activeTab === 'branding'" class="space-y-6" data-testid="section-branding">
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
              <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-4">
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
                    <img
                      :src="config.buttonIconUrl"
                      alt="Custom"
                      class="w-8 h-8 object-contain"
                    />
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
                    {{
                      config.buttonIconUrl
                        ? $t('widgets.changeIcon')
                        : $t('widgets.uploadIcon')
                    }}
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

          <!-- Behavior Tab -->
          <div
            v-else-if="activeTab === 'behavior'"
            class="space-y-6"
            data-testid="section-behavior"
          >
            <div class="surface-chip p-4 rounded-lg flex items-center justify-between">
              <div>
                <p class="font-medium txt-primary">{{ $t('widgets.advancedConfig.autoOpen') }}</p>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.autoOpenHelp') }}
                </p>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="config.autoOpen" type="checkbox" class="sr-only peer" />
                <div
                  class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                ></div>
              </label>
            </div>

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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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

              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  {{ $t('widgets.advancedConfig.maxFileSize') }}
                </label>
                <input
                  v-model.number="config.maxFileSize"
                  type="number"
                  min="1"
                  max="50"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-max-file-size"
                />
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.maxFileSizeHelp') }}
                </p>
              </div>
            </div>

            <div class="surface-chip p-4 rounded-lg space-y-4">
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium txt-primary">
                    {{ $t('widgets.advancedConfig.allowFileUpload') }}
                  </p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widgets.advancedConfig.allowFileUploadHelp') }}
                  </p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input v-model="config.allowFileUpload" type="checkbox" class="sr-only peer" />
                  <div
                    class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--brand)]"
                  ></div>
                </label>
              </div>

              <div v-if="config.allowFileUpload">
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
            </div>
          </div>

          <!-- Security Tab -->
          <div
            v-else-if="activeTab === 'security'"
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

              <div class="flex gap-2">
                <input
                  v-model="newDomain"
                  type="text"
                  placeholder="example.com"
                  class="flex-1 px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-domain"
                  @keydown.enter.prevent="addDomain"
                />
                <button
                  class="btn-primary px-4 py-2.5 rounded-lg font-medium flex items-center gap-2"
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
            </div>
          </div>

          <!-- AI Assistant Tab -->
          <div
            v-else-if="activeTab === 'assistant'"
            class="space-y-6"
            data-testid="section-assistant"
          >
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
              <!-- Prompt Name -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:tag" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.promptName') }}
                </label>
                <input
                  v-model="promptData.name"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-prompt-name"
                />
              </div>

              <!-- Selection Rules -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:funnel" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.selectionRules') }}
                </label>
                <textarea
                  v-model="promptData.rules"
                  rows="2"
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
                  :placeholder="$t('widgets.advancedConfig.selectionRulesPlaceholder')"
                  data-testid="input-selection-rules"
                ></textarea>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.selectionRulesHelp') }}
                </p>
              </div>

              <!-- AI Model Selection -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.aiModel') }}
                </label>
                <select
                  v-model="promptData.aiModel"
                  class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
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

              <!-- Available Tools -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-3 flex items-center gap-2">
                  <Icon icon="heroicons:wrench-screwdriver" class="w-4 h-4" />
                  {{ $t('widgets.advancedConfig.availableTools') }}
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <label
                    v-for="tool in availableTools"
                    :key="tool.value"
                    class="flex items-center gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:bg-[var(--brand)]/5 transition-colors"
                    data-testid="item-tool"
                  >
                    <input
                      v-model="promptData.availableTools"
                      type="checkbox"
                      :value="tool.value"
                      class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                    />
                    <Icon :icon="tool.icon" class="w-5 h-5 txt-secondary" />
                    <span class="text-sm txt-primary">{{ tool.label }}</span>
                  </label>
                </div>
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
                  class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y font-mono text-sm"
                  :placeholder="$t('widgets.advancedConfig.promptContentPlaceholder')"
                  data-testid="input-prompt-content"
                ></textarea>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('widgets.advancedConfig.promptContentHelp') }}
                </p>
              </div>
            </template>
          </div>
        </div>

        <!-- Footer -->
        <div
          class="px-6 py-4 border-t border-light-border/30 dark:border-dark-border/20 flex items-center justify-end gap-3 flex-shrink-0"
        >
          <button
            class="px-5 py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium"
            data-testid="btn-cancel"
            @click="handleClose"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            :disabled="saving"
            class="btn-primary px-6 py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            data-testid="btn-save"
            @click="handleSave"
          >
            <Icon v-if="saving" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
            <Icon v-else icon="heroicons:check" class="w-5 h-5" />
            {{ saving ? $t('common.saving') : $t('common.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi } from '@/services/api/promptsApi'
import { configApi } from '@/services/api/configApi'
import type { AIModel, Capability } from '@/types/ai-models'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{
  widget: widgetsApi.Widget
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

// Check if widget has a custom prompt (not the default)
const hasCustomPrompt = computed(() => {
  const topic = props.widget.taskPromptTopic
  return topic && topic !== 'widget-default' && topic.startsWith('widget-')
})

const tabs = computed(() => {
  const baseTabs = [
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
  ]

  // Only show AI Assistant tab if widget has a custom prompt
  if (hasCustomPrompt.value) {
    baseTabs.push({
      id: 'assistant',
      icon: 'heroicons:sparkles',
      labelKey: 'widgets.advancedConfig.tabs.assistant',
    })
  }

  return baseTabs
})

const activeTab = ref('branding')
const saving = ref(false)
const newDomain = ref('')

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

// Prompt config for AI Assistant tab
const promptData = reactive({
  id: 0,
  topic: '',
  name: '',
  rules: '',
  aiModel: 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
  availableTools: [] as string[],
  content: '',
})
const promptLoading = ref(false)
const promptError = ref<string | null>(null)

// AI Models
const allModels = ref<Partial<Record<Capability, AIModel[]>>>({})
const loadingModels = ref(false)

// Available tools
const availableTools = [
  { value: 'internet-search', label: 'Internet Search', icon: 'heroicons:magnifying-glass' },
  { value: 'files-search', label: 'Files Search', icon: 'heroicons:document-magnifying-glass' },
  { value: 'url-screenshot', label: 'URL Screenshot', icon: 'heroicons:camera' },
]

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
    // Save widget config
    await widgetsApi.updateWidget(props.widget.widgetId, { config })

    // Save prompt if on assistant tab and has custom prompt
    if (activeTab.value === 'assistant' && hasCustomPrompt.value && promptData.id) {
      await savePromptData()
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
    const prompts = await promptsApi.getPrompts('en')
    const prompt = prompts.find((p) => p.topic === props.widget.taskPromptTopic)

    if (prompt) {
      const metadata = prompt.metadata || {}

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

      // Parse available tools from metadata
      const tools: string[] = []
      if (metadata.tool_internet_search) tools.push('internet-search')
      if (metadata.tool_files_search) tools.push('files-search')
      if (metadata.tool_url_screenshot) tools.push('url-screenshot')

      Object.assign(promptData, {
        id: prompt.id,
        topic: prompt.topic,
        name: prompt.shortDescription || prompt.name,
        rules: prompt.selectionRules || '',
        aiModel: aiModelString,
        availableTools: tools,
        content: prompt.prompt,
      })
    }
  } catch (err: any) {
    console.error('Failed to load prompt:', err)
    promptError.value = err.message || 'Failed to load prompt data'
  } finally {
    promptLoading.value = false
  }
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

  // Set tool flags
  metadata.tool_internet_search = promptData.availableTools.includes('internet-search')
  metadata.tool_files_search = promptData.availableTools.includes('files-search')
  metadata.tool_url_screenshot = promptData.availableTools.includes('url-screenshot')

  await promptsApi.updatePrompt(promptData.id, {
    shortDescription: promptData.name,
    prompt: promptData.content,
    selectionRules: promptData.rules || null,
    metadata,
  })
}

onMounted(async () => {
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
    fileUploadLimit: widgetConfig.fileUploadLimit || 3,
    allowedDomains: widgetConfig.allowedDomains || props.widget.allowedDomains || [],
  })

  // Load AI models and prompt data if has custom prompt
  if (hasCustomPrompt.value) {
    await loadAIModels()
    await loadPromptData()
  }
})
</script>
