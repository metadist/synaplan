<template>
  <div class="space-y-6" data-testid="page-config-task-prompts">
    <!-- Header Card with Dropdown -->
    <div class="surface-card p-6" data-testid="section-selector">
      <div class="flex items-start justify-between mb-6">
        <div>
          <h2 class="text-2xl font-semibold txt-primary flex items-center gap-3">
            <Icon icon="heroicons:document-text" class="w-7 h-7 text-[var(--brand)]" />
            {{ $t('config.taskPrompts.title') }}
          </h2>
          <p class="txt-secondary text-sm mt-1">{{ $t('config.taskPrompts.subtitle') }}</p>
        </div>
      </div>

      <!-- Prompt Selector with New Button -->
      <div class="flex items-start gap-3">
        <div class="flex-1">
          <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
            <Icon icon="heroicons:list-bullet" class="w-4 h-4" />
            {{ $t('config.taskPrompts.selectPrompt') }}
          </label>
          <select
            v-model="selectedPromptId"
            @change="onPromptSelect"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
            data-testid="input-prompt-select"
          >
            <option :value="null" disabled>Select a task prompt...</option>
            <option
              v-for="prompt in prompts"
              :key="prompt.id"
              :value="prompt.id"
            >
              {{ prompt.name }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
            <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
            {{ $t('config.taskPrompts.selectPromptHelp') }}
          </p>
        </div>
        
        <!-- New Prompt Button -->
        <div class="pt-7">
          <button
            @click="showCreateModal = true"
            class="px-5 py-3 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium text-sm flex items-center gap-2 whitespace-nowrap"
            data-testid="btn-create-prompt"
          >
            <Icon icon="heroicons:plus-circle" class="w-5 h-5" />
            {{ $t('config.taskPrompts.createNew') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Prompt Details (only shown when a prompt is selected) -->
    <template v-if="currentPrompt">
      <!-- Prompt Details Card -->
      <div class="surface-card p-6" data-testid="section-prompt-details">
        <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
          <Icon icon="heroicons:cog-6-tooth" class="w-5 h-5 text-[var(--brand)]" />
          {{ $t('config.taskPrompts.promptDetails') }}
        </h3>

        <div class="space-y-5">
          <!-- System Prompt Badge (if default) -->
          <div v-if="currentPrompt.isDefault" class="p-3 bg-blue-500/10 border border-blue-500/30 rounded-lg">
            <div class="flex items-center gap-2">
              <Icon icon="heroicons:lock-closed" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
              <div>
                <p class="text-sm font-medium text-blue-600 dark:text-blue-400">System Prompt (Read-Only)</p>
                <p class="text-xs text-blue-600/70 dark:text-blue-400/70">This is a default system prompt and cannot be edited. Create a custom prompt to override it.</p>
              </div>
            </div>
          </div>

          <!-- Rules / Description -->
          <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:clipboard-document-list" class="w-4 h-4" />
              {{ $t('config.taskPrompts.rulesForSelection') }}
            </label>
            <textarea
              v-model="formData.rules"
              :disabled="currentPrompt.isDefault"
              rows="3"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none disabled:opacity-50 disabled:cursor-not-allowed"
              :placeholder="$t('config.taskPrompts.rulesHelp')"
              data-testid="input-rules"
            />
          </div>

          <!-- AI Model Selection -->
        <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
            {{ $t('config.taskPrompts.aiModel') }}
          </label>
          <select
              v-model="formData.aiModel"
              :disabled="currentPrompt.isDefault"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="input-ai-model"
          >
            <option value="AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]">
                ✨ {{ $t('config.taskPrompts.automated') }}
            </option>
              
              <!-- Grouped Models by Capability -->
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
              
              <!-- Loading state -->
              <option v-if="loadingModels" disabled>Loading models...</option>
          </select>
            <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
              <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
            {{ $t('config.taskPrompts.aiModelHelp') }}
          </p>
        </div>

          <!-- Available Tools -->
        <div>
            <label class="block text-sm font-semibold txt-primary mb-3 flex items-center gap-2">
              <Icon icon="heroicons:wrench-screwdriver" class="w-4 h-4" />
            {{ $t('config.taskPrompts.availableTools') }}
          </label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label
                v-for="tool in availableTools"
              :key="tool.value"
                class="flex items-center gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:bg-[var(--brand)]/5 transition-colors"
                data-testid="item-tool"
            >
              <input
                  v-model="formData.availableTools"
                type="checkbox"
                :value="tool.value"
                :disabled="currentPrompt.isDefault"
                class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
              />
                <Icon :icon="tool.icon" class="w-5 h-5 txt-secondary" />
              <span class="text-sm txt-primary">{{ tool.label }}</span>
            </label>
          </div>
        </div>
      </div>
    </div>

      <!-- Prompt Content Card -->
    <div class="surface-card p-6" data-testid="section-prompt-content">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:code-bracket" class="w-5 h-5 text-[var(--brand)]" />
        {{ $t('config.taskPrompts.promptContent') }}
      </h3>

          <!-- Markdown Toolbar -->
          <div v-if="!currentPrompt.isDefault" class="flex items-center gap-1 p-1 surface-chip rounded-lg">
          <button
              v-for="tool in markdownTools"
              :key="tool.label"
              @click="insertMarkdown(tool.before, tool.after)"
              class="p-2 rounded hover:bg-[var(--brand)]/10 txt-secondary hover:txt-primary transition-colors"
              :title="tool.label"
              data-testid="btn-markdown-tool"
            >
              <Icon :icon="tool.icon" class="w-4 h-4" />
          </button>
          </div>
        </div>

        <textarea
          ref="contentTextarea"
          v-model="formData.content"
          :disabled="currentPrompt.isDefault"
          rows="16"
          class="w-full px-4 py-3 surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none font-mono disabled:opacity-50 disabled:cursor-not-allowed"
          :placeholder="$t('config.taskPrompts.contentPlaceholder')"
          data-testid="input-content"
        />
        
        <p class="text-xs txt-secondary mt-2 flex items-center gap-1">
          <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
          {{ $t('config.taskPrompts.contentHelp') }}
        </p>
      </div>

      <!-- Knowledge Base Files Card -->
      <div v-if="!currentPrompt.isDefault" class="surface-card p-6" data-testid="section-knowledge-base">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:document-text" class="w-5 h-5 text-[var(--brand)]" />
              Knowledge Base Files
            </h3>
            <p class="text-xs txt-secondary mt-1">
              Upload files or link existing files that provide context for this task prompt
            </p>
          </div>
        </div>

        <!-- Upload Files Button (redirect to File Manager) -->
        <div class="mb-4">
          <router-link
            to="/files"
            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-sm font-medium"
            data-testid="link-upload-files"
          >
            <Icon icon="heroicons:cloud-arrow-up" class="w-5 h-5" />
            Upload Files in File Manager
            <Icon icon="heroicons:arrow-right" class="w-4 h-4" />
          </router-link>
        </div>

        <!-- Linked Files Section -->
        <div class="mb-6">
          <div class="flex items-center justify-between mb-3">
            <h4 class="text-sm font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:link" class="w-4 h-4" />
              Linked Files ({{ promptFiles.length }})
            </h4>
          </div>

          <!-- Linked Files List -->
          <div v-if="promptFiles.length > 0" class="space-y-2 p-3 surface-chip rounded-lg max-h-[250px] overflow-y-auto" data-testid="section-linked-files">
            <div
              v-for="file in promptFiles"
              :key="file.messageId"
              class="flex items-center justify-between p-2.5 bg-green-500/5 border border-green-500/20 rounded-lg group hover:bg-green-500/10 transition-colors"
              data-testid="item-linked-file"
            >
              <div class="flex items-center gap-2.5 flex-1 min-w-0">
                <Icon icon="heroicons:check-circle" class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium txt-primary truncate">{{ file.fileName }}</p>
                  <p class="text-xs text-green-600/70 dark:text-green-400/70">
                    {{ file.chunks }} chunks • 
                    {{ file.uploadedAt ? formatDate(file.uploadedAt) : 'Unknown date' }}
                  </p>
                </div>
              </div>
              <button
                @click="handleDeleteFile(file.messageId)"
                :disabled="loading"
                class="w-7 h-7 rounded-lg hover:bg-red-500/10 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
                title="Unlink file from this prompt"
                data-testid="btn-unlink"
              >
                <Icon icon="heroicons:x-mark" class="w-4 h-4 text-red-500" />
              </button>
            </div>
          </div>
          
          <div v-else class="text-center py-6 surface-chip rounded-lg border-2 border-dashed border-light-border/30 dark:border-dark-border/20" data-testid="section-linked-empty">
            <Icon icon="heroicons:folder-open" class="w-10 h-10 mx-auto mb-2 txt-secondary opacity-30" />
            <p class="text-sm txt-secondary">No files linked yet</p>
            <p class="text-xs txt-secondary mt-1">Link files below to add them to this prompt's knowledge base</p>
          </div>
        </div>

        <!-- Link Existing Files Section -->
        <div class="space-y-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
          <h4 class="text-sm font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:magnifying-glass" class="w-4 h-4" />
            Link Existing Files
          </h4>

          <!-- Search Filter -->
          <div class="relative">
            <Icon icon="heroicons:magnifying-glass" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary" />
            <input
              v-model="availableFilesSearch"
              @input="loadAvailableFiles"
              type="text"
              placeholder="Search files by name..."
              class="w-full pl-10 pr-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-file-search"
            />
          </div>

          <!-- Loading -->
          <div v-if="loadingAvailableFiles" class="text-center py-8" data-testid="section-files-loading">
            <Icon icon="heroicons:arrow-path" class="w-8 h-8 mx-auto mb-2 txt-secondary animate-spin" />
            <p class="text-sm txt-secondary">Loading files...</p>
          </div>

          <!-- Available Files List -->
          <div v-else-if="availableFiles.length > 0" class="space-y-2 max-h-[300px] overflow-y-auto" data-testid="section-available-files">
            <div
              v-for="file in availableFiles"
              :key="file.messageId"
              class="flex items-center justify-between p-3 surface-chip rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
              data-testid="item-available-file"
            >
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <Icon icon="heroicons:document-text" class="w-5 h-5 txt-secondary flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium txt-primary truncate">{{ file.fileName }}</p>
                  <p class="text-xs txt-secondary">
                    {{ file.chunks }} chunks
                    <template v-if="file.currentGroupKey !== 'DEFAULT'">
                      • Currently linked to: <span class="font-mono">{{ file.currentGroupKey }}</span>
                    </template>
                  </p>
                </div>
              </div>
              <button
                @click="handleLinkFile(file.messageId)"
                :disabled="loading || isFileLinked(file.messageId)"
                :class="[
                  'px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2',
                  isFileLinked(file.messageId)
                    ? 'bg-green-500/10 text-green-600 dark:text-green-400 cursor-default'
                    : 'bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20'
                ]"
                data-testid="btn-link-file"
              >
                <Icon 
                  :icon="isFileLinked(file.messageId) ? 'heroicons:check-circle' : 'heroicons:link'" 
                  class="w-4 h-4" 
                />
                {{ isFileLinked(file.messageId) ? 'Linked' : 'Link' }}
              </button>
            </div>
          </div>

          <!-- Empty State -->
          <div v-else class="text-center py-8" data-testid="section-files-empty">
            <Icon icon="heroicons:document-magnifying-glass" class="w-12 h-12 mx-auto mb-2 txt-secondary opacity-30" />
            <p class="text-sm txt-secondary">
              {{ availableFilesSearch ? 'No files found matching your search' : 'No vectorized files available. Upload files in the Files page first.' }}
            </p>
          </div>
        </div>
      </div>

      <!-- Delete Prompt (only for custom prompts) -->
      <div v-if="!currentPrompt.isDefault" class="surface-card p-6 border-2 border-red-500/20" data-testid="section-danger">
        <h3 class="text-lg font-semibold text-red-600 dark:text-red-400 mb-2 flex items-center gap-2">
          <Icon icon="heroicons:trash" class="w-5 h-5" />
          {{ $t('config.taskPrompts.dangerZone') }}
        </h3>
        <p class="text-sm txt-secondary mb-4">{{ $t('config.taskPrompts.deleteWarning') }}</p>
        <button
          @click="handleDelete"
          :disabled="loading"
          class="btn-secondary px-6 py-2.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-500/10 border-red-500/30 font-medium flex items-center gap-2"
          data-testid="btn-delete"
        >
          <Icon icon="heroicons:trash" class="w-5 h-5" />
          {{ $t('config.taskPrompts.deletePrompt') }}
        </button>
      </div>
    </template>
    <template v-else>
      <div class="surface-card p-10 text-center rounded-lg" data-testid="section-no-prompt-selected">
        <Icon icon="heroicons:cursor-arrow-ripple" class="w-12 h-12 mx-auto mb-4 txt-secondary opacity-50" />
        <h3 class="text-lg font-semibold txt-primary mb-2">
          {{ $t('config.taskPrompts.selectPromptTitle') }}
        </h3>
        <p class="text-sm txt-secondary max-w-xl mx-auto">
          {{ $t('config.taskPrompts.selectPromptDescription') }}
        </p>
      </div>
    </template>

    <!-- Create New Prompt Modal -->
    <div
      v-if="showCreateModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
      data-testid="modal-task-prompt-create"
      @click.self="showCreateModal = false"
    >
      <div class="surface-card p-6 rounded-lg max-w-4xl w-full max-h-[90vh] overflow-y-auto" data-testid="section-create-modal">
        <div class="flex items-center justify-between mb-6">
          <h3 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:plus-circle" class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('config.taskPrompts.createNew') }}
          </h3>
          <button
            @click="showCreateModal = false"
            class="p-2 rounded-lg hover:bg-light-border/10 dark:hover:bg-dark-border/10 transition-colors"
            title="Close"
            data-testid="btn-close"
          >
            <Icon icon="heroicons:x-mark" class="w-5 h-5 txt-secondary" />
          </button>
        </div>

        <div class="space-y-4">
          <!-- Load Template Button -->
          <div v-if="newPromptContent === '' && newPromptRules === ''" class="flex justify-end">
            <button
              @click="loadTemplates"
              class="text-xs px-3 py-1.5 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors flex items-center gap-1.5"
              title="Load template text"
              data-testid="btn-load-template"
            >
              <Icon icon="heroicons:document-duplicate" class="w-3.5 h-3.5" />
              Load Template
            </button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:tag" class="w-4 h-4" />
                {{ $t('config.taskPrompts.topic') }}
              </label>
              <input
                v-model="newPromptTopic"
                type="text"
                class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('config.taskPrompts.topicPlaceholder')"
                data-testid="input-new-topic"
              />
            </div>
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:pencil" class="w-4 h-4" />
                {{ $t('config.taskPrompts.name') }}
              </label>
              <input
                v-model="newPromptName"
                type="text"
                class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('config.taskPrompts.namePlaceholder')"
                data-testid="input-new-name"
              />
            </div>
          </div>

          <!-- Selection Rules Textarea for New Prompt -->
          <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:funnel" class="w-4 h-4" />
              {{ $t('config.taskPrompts.rulesForSelection') }}
            </label>
            <textarea
              v-model="newPromptRules"
              rows="3"
              class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y"
              :placeholder="SELECTION_RULES_TEMPLATE"
              data-testid="input-new-rules"
            ></textarea>
            <p class="text-xs txt-secondary mt-1.5">
              {{ $t('config.taskPrompts.rulesHelp') }}
            </p>
          </div>

          <!-- Prompt Content Textarea for New Prompt -->
          <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:document-text" class="w-4 h-4" />
              {{ $t('config.taskPrompts.promptContent') }}
            </label>
            <textarea
              v-model="newPromptContent"
              rows="8"
              class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono resize-y"
              :placeholder="PROMPT_CONTENT_TEMPLATE"
              data-testid="input-new-content"
            ></textarea>
            <p v-if="hasTemplateText" class="text-xs text-amber-600 dark:text-amber-400 mt-1.5 flex items-center gap-1">
              <Icon icon="heroicons:exclamation-triangle" class="w-3.5 h-3.5" />
              Please customize the template text (remove all [PLACEHOLDER] values) before creating the prompt.
            </p>
          </div>

          <!-- Optional: Link Files to New Prompt -->
          <div class="border-t border-light-border/30 dark:border-dark-border/20 pt-4" data-testid="section-new-files">
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:document-plus" class="w-4 h-4" />
              Knowledge Base Files (Optional)
            </label>
            <p class="text-xs txt-secondary mb-3">
              Link vectorized files to provide context for this prompt. Files can be linked to multiple prompts.
            </p>

            <!-- Search for files -->
            <div class="mb-3">
              <input
                v-model="newPromptFilesSearch"
                type="text"
                placeholder="Search files by name or keyword..."
                class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:outline-none focus:ring-1 focus:ring-[var(--brand)]"
                data-testid="input-new-file-search"
              />
            </div>

            <!-- Selected files for new prompt -->
            <div v-if="newPromptSelectedFiles.length > 0" class="mb-3 space-y-1.5" data-testid="section-new-selected-files">
              <p class="text-xs font-medium txt-primary">Selected ({{ newPromptSelectedFiles.length }}):</p>
              <div class="space-y-1">
                <div
                  v-for="fileId in newPromptSelectedFiles"
                  :key="fileId"
                  class="flex items-center justify-between p-2 bg-green-500/5 border border-green-500/20 rounded text-xs"
                  data-testid="item-new-selected-file"
                >
                  <span class="txt-primary flex-1 min-w-0 truncate">
                    {{ availableFiles.find(f => f.messageId === fileId)?.fileName || 'Unknown' }}
                  </span>
                  <button
                    @click="removeFileFromNewPrompt(fileId)"
                    class="ml-2 text-red-500 hover:text-red-600"
                    title="Remove"
                    data-testid="btn-remove-selected-file"
                  >
                    <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>

            <!-- Available files list -->
            <div class="max-h-[200px] overflow-y-auto space-y-1" data-testid="section-new-available-files">
              <div
                v-for="file in filteredNewPromptFiles"
                :key="file.messageId"
                @click="toggleFileForNewPrompt(file.messageId)"
                class="flex items-center justify-between p-2 surface-chip rounded hover:bg-light-border/10 dark:hover:bg-dark-border/10 cursor-pointer transition-colors text-xs"
                :class="{ 'bg-[var(--brand)]/10': newPromptSelectedFiles.includes(file.messageId) }"
                data-testid="item-new-file"
              >
                <div class="flex-1 min-w-0">
                  <p class="txt-primary font-medium truncate">{{ file.fileName }}</p>
                  <p class="txt-secondary text-[10px]">
                    {{ file.chunks }} chunks
                    <span v-if="file.currentGroupKey" class="ml-1 text-amber-600 dark:text-amber-400">
                      (Used in: {{ file.currentGroupKey }})
                    </span>
                  </p>
                </div>
                <Icon
                  v-if="newPromptSelectedFiles.includes(file.messageId)"
                  icon="heroicons:check-circle"
                  class="w-4 h-4 text-[var(--brand)] flex-shrink-0 ml-2"
                />
              </div>
              <div v-if="filteredNewPromptFiles.length === 0" class="text-center py-4 txt-secondary text-xs" data-testid="section-new-files-empty">
                {{ newPromptFilesSearch ? 'No files found' : 'No vectorized files available' }}
              </div>
            </div>
          </div>

          <!-- Modal Actions -->
          <div class="flex items-center gap-3 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
            <button
              @click="showCreateModal = false"
              class="flex-1 px-6 py-3 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-light-border/10 dark:hover:bg-dark-border/10 transition-colors font-medium"
              data-testid="btn-cancel-create"
            >
              Cancel
            </button>
            <button
              @click="handleCreateNew"
              :disabled="!canCreatePrompt"
              class="flex-1 px-6 py-3 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
              :title="hasTemplateText ? 'Please customize the template text before creating' : 'Create new prompt'"
              data-testid="btn-confirm-create"
            >
              <Icon icon="heroicons:plus-circle" class="w-5 h-5" />
              {{ $t('config.taskPrompts.createButton') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Unsaved Changes Bar -->
    <UnsavedChangesBar
      :show="hasUnsavedChanges"
      @save="handleSave"
      @discard="handleDiscard"
      data-testid="comp-unsaved-bar"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import { promptsApi, type TaskPrompt as ApiTaskPrompt, type PromptFile, type AvailableFile } from '@/services/api/promptsApi'
import { configApi, type ModelInfo } from '@/services/api/configApi'
import { useNotification } from '@/composables/useNotification'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { useDialog } from '@/composables/useDialog'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'

// Template texts for new prompt creation
const SELECTION_RULES_TEMPLATE = 'When the user mentions [TOPIC_NAME] or asks about [SPECIFIC_KEYWORDS], route to this prompt.'
const PROMPT_CONTENT_TEMPLATE = `You are an AI assistant specialized in [YOUR_SPECIALTY].

Your primary goal is to [DESCRIBE_THE_MAIN_OBJECTIVE].

Key guidelines:
- [GUIDELINE_1]
- [GUIDELINE_2]
- [GUIDELINE_3]

When responding:
1. [INSTRUCTION_1]
2. [INSTRUCTION_2]
3. [INSTRUCTION_3]

Remember to always [IMPORTANT_REMINDER].`

// Extended TaskPrompt interface with UI fields
interface TaskPrompt extends ApiTaskPrompt {
  rules?: string
  aiModel?: string
  availableTools?: string[]
  content: string
}

// Tool definition
interface ToolOption {
  value: string
  label: string
  icon: string
}

const { success, error: showError } = useNotification()
const dialog = useDialog()

const prompts = ref<TaskPrompt[]>([])
const selectedPromptId = ref<number | null>(null)
const currentPrompt = ref<TaskPrompt | null>(null)
const formData = ref<Partial<TaskPrompt>>({})
const originalData = ref<Partial<TaskPrompt>>({})
const newPromptName = ref('')
const newPromptTopic = ref('')
const newPromptContent = ref('')
const newPromptRules = ref('')
const newPromptSelectedFiles = ref<number[]>([])
const newPromptFilesSearch = ref('')
const showCreateModal = ref(false)
const contentTextarea = ref<HTMLTextAreaElement | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

// Files for current prompt
const promptFiles = ref<PromptFile[]>([])

// Available files for linking
const availableFiles = ref<AvailableFile[]>([])
const availableFilesSearch = ref('')
const loadingAvailableFiles = ref(false)

// Models from API
const allModels = ref<{ [capability: string]: ModelInfo[] }>({})
const loadingModels = ref(false)

// Available tools with icons (removed image/video generation as they're not clickable tools)
const availableTools: ToolOption[] = [
  { value: 'internet-search', label: 'Internet Search', icon: 'heroicons:magnifying-glass' },
  { value: 'files-search', label: 'Files Search', icon: 'heroicons:document-magnifying-glass' },
  { value: 'url-screenshot', label: 'URL Screenshot', icon: 'heroicons:camera' }
]

// Group models by capability for dropdown
const groupedModels = computed(() => {
  const groups: { label: string; models: ModelInfo[]; capability: string }[] = []
  
  const capabilityLabels: Record<string, string> = {
    'CHAT': 'Chat & General AI',
    'SORT': 'Message Sorting',
    'TEXT2PIC': 'Image Generation',
    'TEXT2VID': 'Video Generation',
    'TEXT2SOUND': 'Text-to-Speech',
    'SOUND2TEXT': 'Speech-to-Text',
    'PIC2TEXT': 'Vision (Image Analysis)',
    'VECTORIZE': 'Embedding / RAG',
    'ANALYZE': 'File Analysis'
  }
  
  // Order of capabilities in dropdown
  const orderedCapabilities = ['CHAT', 'TEXT2PIC', 'TEXT2VID', 'TEXT2SOUND', 'SOUND2TEXT', 'PIC2TEXT', 'ANALYZE', 'VECTORIZE', 'SORT']
  
  orderedCapabilities.forEach(capability => {
    if (allModels.value[capability] && allModels.value[capability].length > 0) {
      groups.push({
        label: capabilityLabels[capability] || capability,
        models: allModels.value[capability],
        capability
      })
    }
  })
  
  return groups
})

// Check if template texts are still present (user must customize them)
const hasTemplateText = computed(() => {
  const rulesHasTemplate = newPromptRules.value.includes('[TOPIC_NAME]') || 
                          newPromptRules.value.includes('[SPECIFIC_KEYWORDS]')
  const contentHasTemplate = newPromptContent.value.includes('[YOUR_SPECIALTY]') || 
                            newPromptContent.value.includes('[DESCRIBE_THE_MAIN_OBJECTIVE]') ||
                            newPromptContent.value.includes('[GUIDELINE_') ||
                            newPromptContent.value.includes('[INSTRUCTION_') ||
                            newPromptContent.value.includes('[IMPORTANT_REMINDER]')
  return rulesHasTemplate || contentHasTemplate
})

// Check if create button should be enabled
const canCreatePrompt = computed(() => {
  return !loading.value && 
         newPromptName.value.trim() !== '' && 
         newPromptTopic.value.trim() !== '' && 
         newPromptContent.value.trim() !== '' && 
         !hasTemplateText.value
})

// Filtered files for new prompt creation
const filteredNewPromptFiles = computed(() => {
  if (!newPromptFilesSearch.value.trim()) {
    return availableFiles.value
  }
  const search = newPromptFilesSearch.value.toLowerCase()
  return availableFiles.value.filter(file => 
    file.fileName.toLowerCase().includes(search) ||
    (file.currentGroupKey && file.currentGroupKey.toLowerCase().includes(search))
  )
})

const markdownTools = [
  { icon: 'heroicons:bold', label: 'Bold', before: '**', after: '**' },
  { icon: 'heroicons:italic', label: 'Italic', before: '*', after: '*' },
  { icon: 'heroicons:hashtag', label: 'Heading', before: '# ', after: '' },
  { icon: 'heroicons:code-bracket', label: 'Code', before: '`', after: '`' },
  { icon: 'heroicons:list-bullet', label: 'List', before: '- ', after: '' },
  { icon: 'heroicons:link', label: 'Link', before: '[', after: '](url)' },
]

// Unsaved changes tracking
const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } = useUnsavedChanges(
  formData as any,
  originalData as any
)

let cleanupGuard: (() => void) | undefined

/**
 * Load AI models from API
 */
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

/**
 * Load all prompts from API
 */
const loadPrompts = async () => {
  loading.value = true
  error.value = null
  
  try {
    const data = await promptsApi.getPrompts('en')
    prompts.value = data.map(p => {
      // Parse metadata from backend
      const metadata = p.metadata || {}
      
      // Determine AI Model string from metadata.aiModel (ID)
      let aiModelString = 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
      if (metadata.aiModel && metadata.aiModel > 0) {
        // Find model by ID in all capabilities
        let foundModel = null
        for (const capability in allModels.value) {
          const models = allModels.value[capability]
          foundModel = models.find((m: any) => m.id === metadata.aiModel)
          if (foundModel) break
        }
        if (foundModel) {
          aiModelString = `${foundModel.name} (${foundModel.service})`
        }
      }
      
      // Parse available tools from metadata (tool_* keys)
      const availableTools: string[] = []
      if (metadata.tool_internet_search) availableTools.push('internet-search')
      if (metadata.tool_files_search) availableTools.push('files-search')
      if (metadata.tool_url_screenshot) availableTools.push('url-screenshot')
      
      return {
        ...p,
        content: p.prompt,
        rules: p.selectionRules || p.shortDescription,
        aiModel: aiModelString,
        availableTools
      }
    })
    
    // Don't auto-select any prompt - let user choose or use URL parameter
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to load prompts'
    error.value = errorMessage
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

/**
 * Load selected prompt
 */
const loadPrompt = () => {
  const prompt = prompts.value.find(p => p.id === selectedPromptId.value)
  if (prompt) {
    currentPrompt.value = { ...prompt }
    formData.value = {
      rules: prompt.rules,
      aiModel: prompt.aiModel,
      availableTools: prompt.availableTools,
      content: prompt.content
    }
    originalData.value = { ...formData.value }
    
    // Load files for this prompt
    loadPromptFiles()
  }
}

/**
 * Handle prompt selection
 */
const onPromptSelect = async () => {
  if (hasUnsavedChanges.value) {
    const confirmed = await dialog.confirm({
      title: 'Unsaved Changes',
      message: 'You have unsaved changes. Do you want to discard them?',
      confirmText: 'Discard',
      cancelText: 'Cancel',
      danger: true
    })
    
    if (!confirmed) {
      selectedPromptId.value = currentPrompt.value?.id || null
      return
    }
  }
  loadPrompt()
}

/**
 * Insert markdown formatting
 */
const insertMarkdown = (before: string, after: string) => {
  const textarea = contentTextarea.value
  if (!textarea || !formData.value.content) return
  
  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const text = formData.value.content
  const selectedText = text.substring(start, end)
  
  formData.value.content =
    text.substring(0, start) +
    before +
    selectedText +
    after +
    text.substring(end)
  
  setTimeout(() => {
    textarea.focus()
    textarea.setSelectionRange(start + before.length, end + before.length)
  }, 0)
}

/**
 * Handle save
 */
const handleSave = saveChanges(async () => {
  if (!currentPrompt.value) return
  
  try {
    // Build metadata object
    const metadata: Record<string, any> = {}
    
    // Parse AI Model from dropdown string back to ID (for SAVE)
    if (formData.value.aiModel !== 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]') {
      const selectedModelString = formData.value.aiModel
      // Find model by ID in all capabilities
      let foundModel = null
      for (const capability in allModels.value) {
        const models = allModels.value[capability]
        foundModel = models.find((m: any) => `${m.name} (${m.service})` === selectedModelString)
        if (foundModel) break
      }
      if (foundModel) {
        metadata.aiModel = foundModel.id
      }
    } else {
      metadata.aiModel = -1 // AUTOMATED
    }
    
    // Set tool flags (for SAVE)
    metadata.tool_internet_search = (formData.value.availableTools || []).includes('internet-search')
    metadata.tool_files_search = (formData.value.availableTools || []).includes('files-search')
    metadata.tool_url_screenshot = (formData.value.availableTools || []).includes('url-screenshot')
    
    // If it's a system prompt (isDefault=true and no user override), 
    // we need to CREATE a user override instead of UPDATE
    if (currentPrompt.value.isDefault && !currentPrompt.value.isUserOverride) {
      // Create user override
      const newPrompt = await promptsApi.createPrompt({
        topic: currentPrompt.value.topic,
        shortDescription: currentPrompt.value.shortDescription,
        prompt: formData.value.content || '',
        language: currentPrompt.value.language || 'en',
        selectionRules: formData.value.rules || null,
        metadata
      })
      
      // Update local state - replace system prompt with user override
      const index = prompts.value.findIndex(p => p.id === currentPrompt.value!.id)
      if (index !== -1) {
        prompts.value[index] = {
          ...newPrompt,
          content: newPrompt.prompt,
          rules: newPrompt.selectionRules || newPrompt.shortDescription,
          aiModel: formData.value.aiModel,
          availableTools: formData.value.availableTools,
          isUserOverride: true
        }
        currentPrompt.value = { ...prompts.value[index] }
        selectedPromptId.value = newPrompt.id
        originalData.value = { ...formData.value }
      }
      
      success('User override created successfully!')
    } else {
      // Update existing user prompt
      const updated = await promptsApi.updatePrompt(currentPrompt.value.id, {
        shortDescription: currentPrompt.value.shortDescription, // Keep original name
        prompt: formData.value.content || '',
        selectionRules: formData.value.rules || null,
        metadata
      })
      
      // Update local state
      const index = prompts.value.findIndex(p => p.id === currentPrompt.value!.id)
      if (index !== -1) {
        prompts.value[index] = {
          ...updated,
          content: updated.prompt,
          rules: updated.selectionRules || updated.shortDescription,
          aiModel: formData.value.aiModel,
          availableTools: formData.value.availableTools
        }
        currentPrompt.value = { ...prompts.value[index] }
        originalData.value = { ...formData.value }
      }
      
      success('Prompt updated successfully!')
    }
  } catch (err: any) {
    let errorMessage = err.message || 'Failed to save prompt'
    
    // Handle specific errors
    if (errorMessage.includes('Validation failed')) {
      errorMessage = 'Validation failed. Please check all fields and try again.'
    } else if (errorMessage.includes('Not authenticated')) {
      errorMessage = 'Your session has expired. Please login again.'
    } else if (errorMessage.includes('Access denied')) {
      errorMessage = 'You do not have permission to modify this prompt.'
    }
    
    showError(errorMessage)
    throw err
  }
})

/**
 * Handle discard
 */
const handleDiscard = () => {
  discardChanges()
}

/**
 * Load template texts for new prompt and replace placeholders with user input
 */
const loadTemplates = () => {
  // Get the topic name from input (convert to readable format)
  const topicName = newPromptTopic.value.trim()
  const displayName = newPromptName.value.trim()
  
  // Replace placeholders in Selection Rules
  let rules = SELECTION_RULES_TEMPLATE
  if (topicName) {
    rules = rules.replace(/\[TOPIC_NAME\]/g, displayName || topicName)
    rules = rules.replace(/\[SPECIFIC_KEYWORDS\]/g, topicName.replace(/-/g, ' '))
  }
  
  // Replace placeholders in Prompt Content
  let content = PROMPT_CONTENT_TEMPLATE
  if (displayName || topicName) {
    const specialty = displayName || topicName.replace(/-/g, ' ')
    content = content.replace(/\[YOUR_SPECIALTY\]/g, specialty)
    content = content.replace(/\[TOPIC_NAME\]/g, specialty)
  }
  
  newPromptRules.value = rules
  newPromptContent.value = content
}

/**
 * Toggle file selection for new prompt
 */
const toggleFileForNewPrompt = (fileId: number) => {
  const index = newPromptSelectedFiles.value.indexOf(fileId)
  if (index > -1) {
    newPromptSelectedFiles.value.splice(index, 1)
  } else {
    newPromptSelectedFiles.value.push(fileId)
  }
}

/**
 * Remove file from new prompt selection
 */
const removeFileFromNewPrompt = (fileId: number) => {
  const index = newPromptSelectedFiles.value.indexOf(fileId)
  if (index > -1) {
    newPromptSelectedFiles.value.splice(index, 1)
  }
}

/**
 * Create a new custom prompt
 */
const handleCreateNew = async () => {
  if (!newPromptName.value.trim() || !newPromptTopic.value.trim() || !newPromptContent.value.trim() || loading.value) {
    showError('Please enter topic, name, and prompt content')
    return
  }
  
  // Check if template text is still present
  if (hasTemplateText.value) {
    showError('Please customize the template text before creating the prompt')
    return
  }
  
  loading.value = true
  
  try {
    // Build metadata object
    const metadata: Record<string, any> = {}
    
    // For new prompts created via modal, we use defaults since AI Model/Tools are not in the modal
    // User can edit these after creation
    metadata.aiModel = -1 // AUTOMATED by default
    metadata.tool_internet_search = true // Enable by default
    metadata.tool_files_search = true // Enable by default
    metadata.tool_url_screenshot = false // Disable by default
    
    const requestPayload = {
      topic: newPromptTopic.value.trim().toLowerCase().replace(/\s+/g, '-'),
      shortDescription: newPromptName.value.trim(),
      prompt: newPromptContent.value.trim(),
      language: 'en',
      selectionRules: newPromptRules.value.trim() || null,
      metadata
    }
    
    console.log('🔵 Creating prompt with payload:', requestPayload)
    
    const newPrompt = await promptsApi.createPrompt(requestPayload)
    
    // Add to local state
    const mappedPrompt: TaskPrompt = {
      ...newPrompt,
      content: newPrompt.prompt,
      rules: newPrompt.selectionRules || newPrompt.shortDescription,
      aiModel: formData.value.aiModel || 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
      availableTools: formData.value.availableTools || []
    }
    
    prompts.value.push(mappedPrompt)
    selectedPromptId.value = newPrompt.id
    currentPrompt.value = { ...mappedPrompt }
    formData.value = {
      rules: mappedPrompt.rules,
      aiModel: mappedPrompt.aiModel,
      availableTools: mappedPrompt.availableTools,
      content: mappedPrompt.content
    }
    originalData.value = { ...formData.value }
    
    // Link selected files to the new prompt (if any)
    if (newPromptSelectedFiles.value.length > 0) {
      try {
        for (const fileId of newPromptSelectedFiles.value) {
          await promptsApi.linkFileToPrompt(newPrompt.topic, fileId)
        }
        success(`Custom prompt created successfully with ${newPromptSelectedFiles.value.length} file(s) linked!`)
      } catch (linkErr: any) {
        console.error('Failed to link files:', linkErr)
        success('Custom prompt created successfully, but some files could not be linked.')
      }
    } else {
      success('Custom prompt created successfully!')
    }
    
    // Clear form
    newPromptName.value = ''
    newPromptTopic.value = ''
    newPromptContent.value = ''
    newPromptRules.value = ''
    newPromptSelectedFiles.value = []
    newPromptFilesSearch.value = ''
    
    // Close modal
    showCreateModal.value = false
    
    // Reload files for the new prompt
    await loadPromptFiles()
  } catch (err: any) {
    let errorMessage = err.message || 'Failed to create prompt'
    
    // Handle specific errors
    if (errorMessage.includes('already have a prompt with this topic')) {
      errorMessage = 'A prompt with this topic already exists. Please choose a different topic.'
    } else if (errorMessage.includes('tools:')) {
      errorMessage = 'Cannot create prompts with "tools:" prefix - reserved for system.'
    } else if (errorMessage.includes('Missing required fields')) {
      errorMessage = 'Please fill in all required fields: Topic, Name, and Prompt Content.'
    }
    
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

/**
 * Delete a custom prompt
 */
const handleDelete = async () => {
  if (!currentPrompt.value || currentPrompt.value.isDefault || loading.value) {
    return
  }
  
  const confirmed = await dialog.confirm({
    title: 'Delete Prompt',
    message: `Are you sure you want to delete "${currentPrompt.value.name}"? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    danger: true
  })
  
  if (!confirmed) return
  
  loading.value = true
  
  try {
    await promptsApi.deletePrompt(currentPrompt.value.id)
    
    // Remove from local state
    const index = prompts.value.findIndex(p => p.id === currentPrompt.value!.id)
    if (index !== -1) {
      prompts.value.splice(index, 1)
      if (prompts.value.length > 0) {
        selectedPromptId.value = prompts.value[0].id
        loadPrompt()
      } else {
        selectedPromptId.value = null
        currentPrompt.value = null
      }
    }
    
    success('Prompt deleted successfully!')
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to delete prompt'
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

/**
 * Load files for current prompt
 */
const loadPromptFiles = async () => {
  if (!currentPrompt.value?.topic) {
    promptFiles.value = []
    return
  }
  
  try {
    promptFiles.value = await promptsApi.getPromptFiles(currentPrompt.value.topic)
  } catch (err: any) {
    console.error('Failed to load prompt files:', err)
    promptFiles.value = []
  }
}

/**
 * Delete file from prompt
 */
const handleDeleteFile = async (messageId: number) => {
  if (!currentPrompt.value?.topic) return
  
  const confirmed = await dialog.confirm({
    title: 'Delete File',
    message: 'Are you sure you want to remove this file from the knowledge base? This action cannot be undone.',
    confirmText: 'Delete',
    cancelText: 'Cancel',
    danger: true
  })
  
  if (!confirmed) return
  
  try {
    await promptsApi.deletePromptFile(currentPrompt.value.topic, messageId)
    success('File removed from knowledge base')
    
    // Reload files list
    await loadPromptFiles()
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to delete file'
    showError(errorMessage)
  }
}

/**
 * Format date for display
 */
const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)
  
  if (diffInSeconds < 60) return 'Just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`
  return date.toLocaleDateString()
}

/**
 * Load available files for linking
 */
const loadAvailableFiles = async () => {
  loadingAvailableFiles.value = true
  try {
    availableFiles.value = await promptsApi.getAvailableFiles(availableFilesSearch.value)
  } catch (err: any) {
    console.error('Failed to load available files:', err)
    availableFiles.value = []
  } finally {
    loadingAvailableFiles.value = false
  }
}

/**
 * Check if file is already linked to current prompt
 */
const isFileLinked = (messageId: number): boolean => {
  return promptFiles.value.some(f => f.messageId === messageId)
}

/**
 * Link existing file to current prompt
 */
const handleLinkFile = async (messageId: number) => {
  if (!currentPrompt.value?.topic) return
  
  try {
    await promptsApi.linkFileToPrompt(currentPrompt.value.topic, messageId)
    success('File linked successfully!')
    
    // Reload both lists
    await Promise.all([
      loadPromptFiles(),
      loadAvailableFiles()
    ])
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to link file'
    showError(errorMessage)
  }
}

onMounted(() => {
  cleanupGuard = setupNavigationGuard()
  Promise.all([
    loadAIModels(),
    loadPrompts(),
    loadAvailableFiles() // Load available files on mount
  ]).then(() => {
    // Check if there's a topic query parameter to auto-select
    const urlParams = new URLSearchParams(window.location.search)
    const topicParam = urlParams.get('topic')
    if (topicParam) {
      const prompt = prompts.value.find(p => p.topic === topicParam)
      if (prompt) {
        selectedPromptId.value = prompt.id
        loadPrompt()
      }
    }
  })
})

onUnmounted(() => {
  cleanupGuard?.()
})
</script>
