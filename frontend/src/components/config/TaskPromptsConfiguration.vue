<template>
  <div class="space-y-6" data-testid="page-config-task-prompts">
    <!-- Header / overview card -->
    <div class="surface-card p-6" data-testid="section-task-prompts-overview">
      <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="flex items-start gap-3 flex-1 min-w-0">
          <div class="p-2 rounded-lg bg-[var(--brand)]/10 flex-shrink-0">
            <Icon icon="heroicons:document-text" class="w-6 h-6 text-[var(--brand)]" />
          </div>
          <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-semibold txt-primary">{{ $t('config.taskPrompts.title') }}</h2>
            <p class="txt-secondary text-sm mt-1">{{ $t('config.taskPrompts.subtitle') }}</p>
            <div class="flex items-center gap-2 mt-3 text-xs flex-wrap">
              <span
                class="px-2 py-1 rounded-full bg-[var(--brand)]/10 text-[var(--brand)] flex items-center gap-1.5"
                data-testid="badge-language"
              >
                <Icon icon="heroicons:language" class="w-3.5 h-3.5" />
                {{ $t('config.taskPrompts.workingLanguage', { language: currentLanguageLabel }) }}
              </span>
              <span class="txt-secondary">{{ $t('config.taskPrompts.workingLanguageHint') }}</span>
            </div>
          </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-3 lg:flex-shrink-0">
          <!-- Stat pills -->
          <div class="flex flex-wrap items-center gap-2" data-testid="section-task-prompts-stats">
            <div
              class="px-3 py-2 rounded-lg surface-chip flex items-center gap-2"
              data-testid="stat-total"
            >
              <Icon icon="heroicons:rectangle-stack" class="w-4 h-4 text-[var(--brand)]" />
              <span class="text-sm font-semibold txt-primary">{{ prompts.length }}</span>
              <span class="text-xs txt-secondary">{{ $t('config.taskPrompts.statTotal') }}</span>
            </div>
            <div
              class="px-3 py-2 rounded-lg surface-chip flex items-center gap-2"
              data-testid="stat-system"
            >
              <Icon icon="heroicons:shield-check" class="w-4 h-4 text-blue-500" />
              <span class="text-sm font-semibold txt-primary">{{ systemPromptCount }}</span>
              <span class="text-xs txt-secondary">{{ $t('config.taskPrompts.statSystem') }}</span>
            </div>
            <div
              class="px-3 py-2 rounded-lg surface-chip flex items-center gap-2"
              data-testid="stat-custom"
            >
              <Icon icon="heroicons:user" class="w-4 h-4 text-purple-500" />
              <span class="text-sm font-semibold txt-primary">{{ customPromptCount }}</span>
              <span class="text-xs txt-secondary">{{ $t('config.taskPrompts.statCustom') }}</span>
            </div>
          </div>

          <button
            class="px-4 py-2.5 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium text-sm flex items-center justify-center gap-2 whitespace-nowrap"
            data-testid="btn-create-prompt"
            @click="showCreateModal = true"
          >
            <Icon icon="heroicons:plus-circle" class="w-5 h-5" />
            {{ $t('config.taskPrompts.createNew') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Two-pane layout: list + editor -->
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)] gap-6">
      <!-- LEFT: Topic list -->
      <aside
        class="surface-card p-0 lg:sticky lg:top-4 lg:self-start lg:max-h-[calc(100vh-2rem)] flex flex-col overflow-hidden"
        :class="[currentPrompt && !listVisibleMobile && 'hidden lg:flex']"
        data-testid="section-task-prompts-list"
      >
        <!-- Sticky list header -->
        <div
          class="p-4 border-b border-light-border/30 dark:border-dark-border/20 space-y-3 flex-shrink-0"
        >
          <!-- Search + density toggle row -->
          <div class="flex items-center gap-2">
            <div class="relative flex-1">
              <Icon
                icon="heroicons:magnifying-glass"
                class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none"
              />
              <input
                v-model="promptListSearch"
                type="text"
                :placeholder="$t('config.taskPrompts.searchPlaceholder')"
                class="w-full pl-9 pr-9 py-2 rounded-lg surface-chip border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-prompt-search"
              />
              <button
                v-if="promptListSearch"
                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded txt-secondary hover:txt-primary"
                :title="$t('config.taskPrompts.clearFilters')"
                data-testid="btn-clear-search"
                @click="promptListSearch = ''"
              >
                <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
              </button>
            </div>
            <button
              type="button"
              class="p-2 rounded-lg surface-chip txt-secondary hover:txt-primary transition-colors"
              :title="
                viewDensity === 'compact'
                  ? $t('config.taskPrompts.densityDetailed')
                  : $t('config.taskPrompts.densityCompact')
              "
              data-testid="btn-density-toggle"
              @click="viewDensity = viewDensity === 'compact' ? 'detailed' : 'compact'"
            >
              <Icon
                :icon="
                  viewDensity === 'compact'
                    ? 'heroicons:bars-3-bottom-left'
                    : 'heroicons:queue-list'
                "
                class="w-4 h-4"
              />
            </button>
          </div>

          <!-- Filter chips -->
          <div class="flex gap-1.5 overflow-x-auto pb-1 -mx-1 px-1">
            <button
              v-for="filter in promptListFilters"
              :key="filter.value"
              type="button"
              class="px-2.5 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-colors flex items-center gap-1.5"
              :class="
                promptListFilter === filter.value
                  ? 'bg-[var(--brand)] text-white'
                  : 'surface-chip txt-secondary hover:txt-primary'
              "
              :data-testid="`filter-${filter.value}`"
              @click="promptListFilter = filter.value"
            >
              <span>{{ filter.label }}</span>
              <span
                class="px-1.5 rounded-full text-[10px] font-semibold leading-none py-0.5"
                :class="
                  promptListFilter === filter.value
                    ? 'bg-white/20'
                    : 'bg-light-border/30 dark:bg-dark-border/20'
                "
              >
                {{ filter.count }}
              </span>
            </button>
          </div>

          <!-- Result count + collapse-all toggle -->
          <div class="flex items-center justify-between text-[11px] txt-secondary">
            <span data-testid="text-list-count">
              {{ $t('config.taskPrompts.listCount', { n: filteredPrompts.length }) }}
            </span>
            <button
              type="button"
              class="hover:txt-primary transition-colors"
              data-testid="btn-toggle-all-groups"
              @click="toggleAllGroups"
            >
              {{
                allGroupsCollapsed
                  ? $t('config.taskPrompts.expandAll')
                  : $t('config.taskPrompts.collapseAll')
              }}
            </button>
          </div>
        </div>

        <!-- Scrollable category groups -->
        <div class="flex-1 overflow-y-auto py-2" data-testid="section-task-prompt-cards">
          <template v-for="group in categorizedPrompts" :key="group.id">
            <div v-if="group.prompts.length > 0" class="px-2 mb-1">
              <button
                type="button"
                class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md txt-secondary hover:txt-primary hover:bg-light-border/10 dark:hover:bg-dark-border/10 transition-colors text-[11px] uppercase tracking-wide font-semibold"
                :data-testid="`group-${group.id}`"
                @click="toggleGroup(group.id)"
              >
                <Icon
                  :icon="
                    isGroupCollapsed(group.id)
                      ? 'heroicons:chevron-right'
                      : 'heroicons:chevron-down'
                  "
                  class="w-3.5 h-3.5"
                />
                <Icon :icon="group.icon" class="w-3.5 h-3.5" />
                <span class="flex-1 text-left">{{ group.label }}</span>
                <span
                  class="px-1.5 rounded-full text-[10px] font-semibold leading-none py-0.5 bg-light-border/30 dark:bg-dark-border/20"
                >
                  {{ group.prompts.length }}
                </span>
              </button>

              <ul v-if="!isGroupCollapsed(group.id)" class="space-y-0.5 mt-1">
                <li v-for="prompt in group.prompts" :key="prompt.id">
                  <button
                    type="button"
                    class="w-full text-left px-2 rounded-md transition-all group flex items-center gap-2"
                    :class="[
                      viewDensity === 'compact' ? 'py-1.5' : 'py-2.5',
                      selectedPromptId === prompt.id
                        ? 'bg-[var(--brand)]/10 text-[var(--brand)]'
                        : 'hover:bg-light-border/10 dark:hover:bg-dark-border/10 txt-primary',
                    ]"
                    :data-testid="`card-prompt-${prompt.topic}`"
                    :title="prompt.shortDescription || prompt.topic"
                    @click="onCardSelect(prompt.id)"
                  >
                    <!-- Active indicator stripe -->
                    <span
                      class="w-0.5 self-stretch rounded-full transition-all"
                      :class="
                        selectedPromptId === prompt.id ? 'bg-[var(--brand)]' : 'bg-transparent'
                      "
                    />

                    <Icon
                      :icon="topicIcon(prompt.topic)"
                      class="w-4 h-4 flex-shrink-0"
                      :class="
                        selectedPromptId === prompt.id
                          ? 'text-[var(--brand)]'
                          : 'txt-secondary group-hover:text-[var(--brand)]'
                      "
                    />

                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-1.5">
                        <span class="text-sm font-medium truncate">{{ prompt.name }}</span>

                        <!-- Inline mini-badges (always visible) -->
                        <span
                          v-if="prompt.isUserOverride"
                          class="px-1 py-0.5 rounded text-[9px] font-medium uppercase bg-amber-500/10 text-amber-600 dark:text-amber-400 leading-none flex-shrink-0"
                          :title="$t('config.taskPrompts.badgeOverride')"
                          data-testid="badge-override"
                        >
                          {{ $t('config.taskPrompts.badgeOverride') }}
                        </span>
                      </div>
                      <p
                        v-if="viewDensity === 'detailed' && prompt.shortDescription"
                        class="text-[11px] txt-secondary line-clamp-1 mt-0.5"
                      >
                        {{ prompt.shortDescription }}
                      </p>
                      <p
                        v-else-if="viewDensity === 'detailed'"
                        class="text-[10px] txt-secondary font-mono truncate mt-0.5"
                      >
                        {{ prompt.topic }}
                      </p>
                    </div>
                  </button>
                </li>
              </ul>
            </div>
          </template>

          <div
            v-if="filteredPrompts.length === 0"
            class="text-center py-12 px-4"
            data-testid="text-no-prompts-match"
          >
            <Icon icon="heroicons:funnel" class="w-10 h-10 mx-auto mb-2 txt-secondary opacity-30" />
            <p class="text-sm txt-secondary">{{ $t('config.taskPrompts.noPromptsMatch') }}</p>
            <button
              v-if="promptListSearch || promptListFilter !== 'all'"
              class="text-xs text-[var(--brand)] hover:underline mt-2"
              data-testid="btn-clear-filters"
              @click="clearFilters"
            >
              {{ $t('config.taskPrompts.clearFilters') }}
            </button>
          </div>
        </div>

        <!-- Hidden compat select for legacy automation tools -->
        <select
          v-model="selectedPromptId"
          class="sr-only"
          aria-hidden="true"
          tabindex="-1"
          data-testid="input-prompt-select"
          @change="onPromptSelect"
        >
          <option :value="null" disabled>{{ $t('config.taskPrompts.selectPlaceholder') }}</option>
          <option v-for="prompt in filteredPrompts" :key="prompt.id" :value="prompt.id">
            {{ prompt.name }}
          </option>
        </select>
      </aside>

      <!-- RIGHT: Editor or empty state -->
      <section class="min-w-0" :class="[!currentPrompt && listVisibleMobile && 'hidden lg:block']">
        <template v-if="currentPrompt">
          <!-- Sticky breadcrumb header -->
          <div
            class="surface-card p-4 mb-4 flex items-center gap-3"
            data-testid="section-prompt-header"
          >
            <button
              class="lg:hidden p-2 rounded-lg hover:bg-light-border/10 dark:hover:bg-dark-border/10"
              :title="$t('config.taskPrompts.backToList')"
              data-testid="btn-back-to-list"
              @click="backToListMobile"
            >
              <Icon icon="heroicons:arrow-left" class="w-5 h-5 txt-secondary" />
            </button>
            <div class="p-2 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] flex-shrink-0">
              <Icon :icon="topicIcon(currentPrompt.topic)" class="w-5 h-5" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h3 class="text-lg font-semibold txt-primary truncate">
                  {{ currentPrompt.name }}
                </h3>
                <span
                  v-if="currentPrompt.isDefault && !currentPrompt.isUserOverride"
                  class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-blue-500/10 text-blue-600 dark:text-blue-400 leading-none"
                >
                  {{ $t('config.taskPrompts.badgeSystem') }}
                </span>
                <span
                  v-else-if="!currentPrompt.isDefault"
                  class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-purple-500/10 text-purple-600 dark:text-purple-400 leading-none"
                >
                  {{ $t('config.taskPrompts.badgeCustom') }}
                </span>
              </div>
              <p class="text-xs txt-secondary font-mono truncate">{{ currentPrompt.topic }}</p>
            </div>
          </div>

          <!-- System prompt info banner -->
          <div
            v-if="currentPrompt.isDefault && !currentPrompt.isUserOverride && !isAdmin"
            class="p-3 mb-4 bg-blue-500/10 border border-blue-500/30 rounded-lg flex items-start gap-2"
            data-testid="banner-system-prompt"
          >
            <Icon
              icon="heroicons:information-circle"
              class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5"
            />
            <div class="text-sm">
              <p class="font-medium text-blue-700 dark:text-blue-300">
                {{ $t('config.taskPrompts.systemPromptUserTitle') }}
              </p>
              <p class="text-blue-700/80 dark:text-blue-300/80 text-xs mt-0.5">
                {{ $t('config.taskPrompts.systemPromptUserDesc') }}
              </p>
            </div>
          </div>
          <div
            v-else-if="currentPrompt.isDefault && isAdmin"
            class="p-3 mb-4 bg-amber-500/10 border border-amber-500/30 rounded-lg flex items-start gap-2"
            data-testid="banner-system-admin"
          >
            <Icon
              icon="heroicons:shield-check"
              class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"
            />
            <div class="text-sm">
              <p class="font-medium text-amber-700 dark:text-amber-300">
                {{ $t('config.taskPrompts.systemPromptAdminTitle') }}
              </p>
              <p class="text-amber-700/80 dark:text-amber-300/80 text-xs mt-0.5">
                {{ $t('config.taskPrompts.systemPromptAdminDesc') }}
              </p>
            </div>
          </div>

          <!-- Tab nav -->
          <div
            class="surface-card p-1 mb-4 flex gap-1 overflow-x-auto"
            role="tablist"
            data-testid="section-prompt-tabs"
          >
            <button
              v-for="tab in editorTabs"
              :key="tab.id"
              type="button"
              role="tab"
              :aria-selected="activeTab === tab.id"
              class="flex-1 min-w-fit px-3 py-2 rounded-md text-sm font-medium transition-colors flex items-center justify-center gap-1.5 whitespace-nowrap"
              :class="
                activeTab === tab.id
                  ? 'bg-[var(--brand)]/10 text-[var(--brand)]'
                  : 'txt-secondary hover:txt-primary hover:bg-light-border/10 dark:hover:bg-dark-border/10'
              "
              :data-testid="`tab-${tab.id}`"
              @click="activeTab = tab.id"
            >
              <Icon :icon="tab.icon" class="w-4 h-4" />
              <span>{{ tab.label }}</span>
              <span
                v-if="tab.id === 'danger' && (currentPrompt.isDefault ? !isAdmin : false)"
                class="hidden"
                aria-hidden="true"
              />
            </button>
          </div>

          <!-- TAB: Routing -->
          <div
            v-show="activeTab === 'routing'"
            class="surface-card p-6 space-y-5"
            data-testid="section-prompt-details"
            role="tabpanel"
            aria-labelledby="tab-routing"
          >
            <!-- Description -->
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:document-text" class="w-4 h-4" />
                {{ $t('config.taskPrompts.descriptionLabel') }}
              </label>
              <textarea
                v-model="formData.shortDescription"
                rows="3"
                class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none disabled:opacity-50"
                :placeholder="$t('config.taskPrompts.descriptionPlaceholder')"
                data-testid="input-description"
              />
              <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
                <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
                {{ $t('config.taskPrompts.descriptionHelp') }}
              </p>
            </div>

            <!-- Routing rules -->
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:bolt" class="w-4 h-4" />
                {{ $t('config.taskPrompts.rulesForSelection') }}
              </label>
              <textarea
                v-model="formData.selectionRules"
                rows="3"
                class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none disabled:opacity-50"
                :placeholder="$t('config.taskPrompts.rulesPlaceholder')"
                data-testid="input-rules"
              />
              <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
                <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
                {{ $t('config.taskPrompts.rulesHelp') }}
              </p>
            </div>

            <!-- Language -->
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:language" class="w-4 h-4" />
                {{ $t('config.taskPrompts.language') }}
              </label>
              <select
                v-model="formData.language"
                :disabled="currentPrompt.isDefault && isAdmin"
                class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="input-language"
              >
                <option v-for="lang in PROMPT_LANGUAGES" :key="lang.value" :value="lang.value">
                  {{ lang.label }}
                </option>
              </select>
              <p
                v-if="currentPrompt.isDefault && isAdmin"
                class="text-xs text-amber-600 dark:text-amber-400 mt-1.5 flex items-center gap-1"
              >
                <Icon icon="heroicons:lock-closed" class="w-3.5 h-3.5" />
                {{ $t('config.taskPrompts.systemPromptLanguageFixed') }}
              </p>
              <p v-else class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
                <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
                {{ $t('config.taskPrompts.customPromptLanguageNote') }}
              </p>
            </div>
          </div>

          <!-- TAB: Prompt content + AI -->
          <div
            v-show="activeTab === 'prompt'"
            class="space-y-4"
            role="tabpanel"
            aria-labelledby="tab-prompt"
          >
            <!-- Model + tools -->
            <div class="surface-card p-6 space-y-5" data-testid="section-prompt-config">
              <div>
                <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                  <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
                  {{ $t('config.taskPrompts.aiModel') }}
                </label>
                <ModelSelectDropdown
                  :model-value="formData.aiModel ?? 'default'"
                  :groups="groupedModels"
                  :loading="loadingModels"
                  default-option="Default Model (Auto-selected based on capability)"
                  data-testid="input-ai-model"
                  @update:model-value="
                    (v: string | number | null) => (formData.aiModel = String(v ?? 'default'))
                  "
                />
                <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
                  <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
                  {{ $t('config.taskPrompts.aiModelHelp') }}
                </p>
              </div>

              <div>
                <label class="block text-sm font-semibold txt-primary mb-3 flex items-center gap-2">
                  <Icon icon="heroicons:wrench-screwdriver" class="w-4 h-4" />
                  {{ $t('config.taskPrompts.availableTools') }}
                </label>

                <!--
                  Internet search is tri-state (auto / always on / always off).
                  A checkbox can only express two states, so saving silently
                  collapsed the "let the classifier decide" default (null) into
                  "always off" and disabled web search for the prompt (#1138).
                  A dropdown keeps all three states distinguishable and lets an
                  admin restore "auto" at any time.
                -->
                <div class="mb-3 p-3 rounded-lg surface-chip">
                  <div class="flex items-center gap-3 mb-2">
                    <Icon icon="heroicons:magnifying-glass" class="w-5 h-5 txt-secondary" />
                    <span class="text-sm font-medium txt-primary">{{
                      $t('config.taskPrompts.internetSearch.label')
                    }}</span>
                  </div>
                  <select
                    v-model="formData.toolInternet"
                    class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                    data-testid="select-tool-internet"
                  >
                    <option value="auto">
                      {{ $t('config.taskPrompts.internetSearch.auto') }}
                    </option>
                    <option value="on">{{ $t('config.taskPrompts.internetSearch.on') }}</option>
                    <option value="off">{{ $t('config.taskPrompts.internetSearch.off') }}</option>
                  </select>
                  <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
                    <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
                    {{ $t('config.taskPrompts.internetSearch.help') }}
                  </p>
                </div>

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
                      class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                    />
                    <Icon :icon="tool.icon" class="w-5 h-5 txt-secondary" />
                    <span class="text-sm txt-primary">{{ tool.label }}</span>
                  </label>
                </div>
              </div>
            </div>

            <!-- Prompt content -->
            <div class="surface-card p-6" data-testid="section-prompt-content">
              <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <div>
                  <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
                    <Icon icon="heroicons:code-bracket" class="w-5 h-5 text-[var(--brand)]" />
                    {{ $t('config.taskPrompts.promptContent') }}
                  </h3>
                  <p class="text-xs txt-secondary mt-0.5">
                    {{ $t('config.taskPrompts.promptContentSubtitle') }}
                  </p>
                </div>

                <!-- Markdown toolbar -->
                <div class="flex items-center gap-1 p-1 surface-chip rounded-lg">
                  <button
                    v-for="tool in markdownTools"
                    :key="tool.label"
                    class="p-2 rounded hover:bg-[var(--brand)]/10 txt-secondary hover:txt-primary transition-colors"
                    :title="tool.label"
                    data-testid="btn-markdown-tool"
                    @click="insertMarkdown(tool.before, tool.after)"
                  >
                    <Icon :icon="tool.icon" class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <textarea
                ref="contentTextarea"
                v-model="formData.content"
                rows="18"
                class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y font-mono leading-relaxed"
                :placeholder="$t('config.taskPrompts.contentPlaceholder')"
                data-testid="input-content"
              />

              <div class="flex items-center justify-between mt-2 flex-wrap gap-2">
                <p class="text-xs txt-secondary flex items-center gap-1">
                  <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
                  {{ $t('config.taskPrompts.contentHelp') }}
                </p>
                <span class="text-[10px] txt-secondary" data-testid="text-content-stats">
                  {{
                    $t('config.taskPrompts.contentStats', {
                      chars: contentLength,
                      words: contentWordCount,
                    })
                  }}
                </span>
              </div>
            </div>
          </div>

          <!-- TAB: Knowledge base -->
          <div
            v-show="activeTab === 'knowledge'"
            class="surface-card p-6"
            data-testid="section-knowledge-base"
            role="tabpanel"
            aria-labelledby="tab-knowledge"
          >
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
              <div>
                <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
                  <Icon icon="heroicons:document-text" class="w-5 h-5 text-[var(--brand)]" />
                  {{ $t('config.taskPrompts.knowledgeTitle') }}
                </h3>
                <p class="text-xs txt-secondary mt-0.5">
                  {{ $t('config.taskPrompts.knowledgeSubtitle') }}
                </p>
              </div>
              <router-link
                to="/files"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-sm font-medium"
                data-testid="link-upload-files"
              >
                <Icon icon="heroicons:cloud-arrow-up" class="w-4 h-4" />
                {{ $t('config.taskPrompts.openFileManager') }}
                <Icon icon="heroicons:arrow-top-right-on-square" class="w-3.5 h-3.5" />
              </router-link>
            </div>

            <!-- Linked files -->
            <div class="mb-6">
              <h4 class="text-sm font-semibold txt-primary mb-3 flex items-center gap-2">
                <Icon icon="heroicons:link" class="w-4 h-4" />
                {{ $t('config.taskPrompts.linkedFiles', { n: promptFiles.length }) }}
              </h4>

              <div
                v-if="promptFiles.length > 0"
                class="space-y-2 p-3 surface-chip rounded-lg max-h-[280px] overflow-y-auto"
                data-testid="section-linked-files"
              >
                <div
                  v-for="file in promptFiles"
                  :key="file.messageId"
                  class="flex items-center justify-between p-2.5 bg-emerald-500/5 border border-emerald-500/20 rounded-lg group hover:bg-emerald-500/10 transition-colors"
                  data-testid="item-linked-file"
                >
                  <div class="flex items-center gap-2.5 flex-1 min-w-0">
                    <Icon
                      icon="heroicons:check-circle"
                      class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0"
                    />
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium txt-primary truncate">{{ file.fileName }}</p>
                      <p class="text-xs text-emerald-600/70 dark:text-emerald-400/70">
                        {{
                          $t('config.taskPrompts.fileMeta', {
                            chunks: file.chunks,
                            date: file.uploadedAt
                              ? formatDate(file.uploadedAt)
                              : $t('config.taskPrompts.unknownDate'),
                          })
                        }}
                      </p>
                    </div>
                  </div>
                  <button
                    :disabled="loading"
                    class="w-7 h-7 rounded-lg hover:bg-rose-500/10 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
                    :title="$t('config.taskPrompts.unlinkFile')"
                    data-testid="btn-unlink"
                    @click="handleDeleteFile(file.messageId)"
                  >
                    <Icon icon="heroicons:x-mark" class="w-4 h-4 text-rose-500" />
                  </button>
                </div>
              </div>

              <div
                v-else
                class="text-center py-6 surface-chip rounded-lg border-2 border-dashed border-light-border/30 dark:border-dark-border/20"
                data-testid="section-linked-empty"
              >
                <Icon
                  icon="heroicons:folder-open"
                  class="w-10 h-10 mx-auto mb-2 txt-secondary opacity-30"
                />
                <p class="text-sm txt-secondary">{{ $t('config.taskPrompts.noFilesLinked') }}</p>
                <p class="text-xs txt-secondary mt-1">
                  {{ $t('config.taskPrompts.noFilesLinkedHint') }}
                </p>
              </div>
            </div>

            <!-- Link existing files -->
            <div class="space-y-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
              <h4 class="text-sm font-semibold txt-primary flex items-center gap-2">
                <Icon icon="heroicons:magnifying-glass" class="w-4 h-4" />
                {{ $t('config.taskPrompts.linkExistingFiles') }}
              </h4>

              <div class="relative">
                <Icon
                  icon="heroicons:magnifying-glass"
                  class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary pointer-events-none"
                />
                <input
                  v-model="availableFilesSearch"
                  type="text"
                  :placeholder="$t('config.taskPrompts.searchFilesPlaceholder')"
                  class="w-full pl-10 pr-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-file-search"
                  @input="loadAvailableFiles"
                />
              </div>

              <div
                v-if="loadingAvailableFiles"
                class="text-center py-8"
                data-testid="section-files-loading"
              >
                <Icon
                  icon="heroicons:arrow-path"
                  class="w-8 h-8 mx-auto mb-2 txt-secondary animate-spin"
                />
                <p class="text-sm txt-secondary">{{ $t('config.taskPrompts.loadingFiles') }}</p>
              </div>

              <div
                v-else-if="availableFiles.length > 0"
                class="space-y-2 max-h-[320px] overflow-y-auto"
                data-testid="section-available-files"
              >
                <div
                  v-for="file in availableFiles"
                  :key="file.messageId"
                  class="flex items-center justify-between p-3 surface-chip rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                  data-testid="item-available-file"
                >
                  <div class="flex items-center gap-3 flex-1 min-w-0">
                    <Icon
                      icon="heroicons:document-text"
                      class="w-5 h-5 txt-secondary flex-shrink-0"
                    />
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium txt-primary truncate">{{ file.fileName }}</p>
                      <p class="text-xs txt-secondary">
                        {{ $t('config.taskPrompts.chunksCount', { n: file.chunks }) }}
                        <template v-if="file.currentGroupKey !== 'DEFAULT'">
                          ·
                          {{
                            $t('config.taskPrompts.currentlyLinkedTo', {
                              key: file.currentGroupKey,
                            })
                          }}
                        </template>
                      </p>
                    </div>
                  </div>
                  <button
                    :disabled="loading || isFileLinked(file.messageId)"
                    :class="[
                      'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1.5',
                      isFileLinked(file.messageId)
                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 cursor-default'
                        : 'bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20',
                    ]"
                    data-testid="btn-link-file"
                    @click="handleLinkFile(file.messageId)"
                  >
                    <Icon
                      :icon="
                        isFileLinked(file.messageId) ? 'heroicons:check-circle' : 'heroicons:link'
                      "
                      class="w-3.5 h-3.5"
                    />
                    {{
                      isFileLinked(file.messageId)
                        ? $t('config.taskPrompts.linked')
                        : $t('config.taskPrompts.linkFile')
                    }}
                  </button>
                </div>
              </div>

              <div v-else class="text-center py-8" data-testid="section-files-empty">
                <Icon
                  icon="heroicons:document-magnifying-glass"
                  class="w-12 h-12 mx-auto mb-2 txt-secondary opacity-30"
                />
                <p class="text-sm txt-secondary">
                  {{
                    availableFilesSearch
                      ? $t('config.taskPrompts.noFilesMatch')
                      : $t('config.taskPrompts.noVectorizedFiles')
                  }}
                </p>
              </div>
            </div>
          </div>

          <!-- TAB: Danger -->
          <div
            v-show="activeTab === 'danger'"
            class="surface-card p-6 border-2 border-rose-500/20"
            data-testid="section-danger"
            role="tabpanel"
            aria-labelledby="tab-danger"
          >
            <h3
              class="text-lg font-semibold text-rose-600 dark:text-rose-400 mb-2 flex items-center gap-2"
            >
              <Icon icon="heroicons:exclamation-triangle" class="w-5 h-5" />
              {{ $t('config.taskPrompts.dangerZone') }}
            </h3>

            <div
              v-if="currentPrompt.isDefault && !isAdmin"
              class="p-3 surface-chip rounded-lg flex items-start gap-2"
            >
              <Icon icon="heroicons:lock-closed" class="w-5 h-5 txt-secondary mt-0.5" />
              <div class="text-sm txt-secondary">
                {{ $t('config.taskPrompts.dangerLockedForUser') }}
              </div>
            </div>

            <template v-else>
              <p
                v-if="currentPrompt.isDefault && isAdmin"
                class="text-sm text-rose-500 mb-4 font-medium"
              >
                {{ $t('config.taskPrompts.dangerSystemWarning') }}
              </p>
              <p v-else class="text-sm txt-secondary mb-4">
                {{ $t('config.taskPrompts.deleteWarning') }}
              </p>
              <button
                :disabled="loading"
                class="px-5 py-2.5 rounded-lg text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 border border-rose-500/30 font-medium flex items-center gap-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-delete"
                @click="handleDelete"
              >
                <Icon icon="heroicons:trash" class="w-5 h-5" />
                {{ $t('config.taskPrompts.deletePrompt') }}
              </button>
            </template>
          </div>
        </template>

        <!-- Empty state -->
        <template v-else>
          <div
            class="surface-card p-10 text-center rounded-lg"
            data-testid="section-no-prompt-selected"
          >
            <div
              class="w-16 h-16 mx-auto mb-4 rounded-full bg-[var(--brand)]/10 flex items-center justify-center"
            >
              <Icon icon="heroicons:cursor-arrow-ripple" class="w-8 h-8 text-[var(--brand)]" />
            </div>
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('config.taskPrompts.selectPromptTitle') }}
            </h3>
            <p class="text-sm txt-secondary max-w-xl mx-auto mb-6">
              {{ $t('config.taskPrompts.selectPromptDescription') }}
            </p>
            <button
              class="px-5 py-2.5 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium text-sm inline-flex items-center gap-2"
              data-testid="btn-create-prompt-empty"
              @click="showCreateModal = true"
            >
              <Icon icon="heroicons:plus-circle" class="w-5 h-5" />
              {{ $t('config.taskPrompts.createNew') }}
            </button>
          </div>
        </template>
      </section>
    </div>

    <!-- Create modal -->
    <div
      v-if="showCreateModal"
      class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
      data-testid="modal-task-prompt-create"
      @click.self="showCreateModal = false"
    >
      <div
        class="modal-panel surface-card p-6 rounded-lg max-w-4xl w-full overflow-y-auto"
        data-testid="section-create-modal"
      >
        <div class="flex items-center justify-between mb-6">
          <h3 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:plus-circle" class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('config.taskPrompts.createNew') }}
          </h3>
          <button
            class="p-2 rounded-lg hover:bg-light-border/10 dark:hover:bg-dark-border/10 transition-colors"
            :title="$t('common.close', 'Close')"
            data-testid="btn-close"
            @click="showCreateModal = false"
          >
            <Icon icon="heroicons:x-mark" class="w-5 h-5 txt-secondary" />
          </button>
        </div>

        <div class="space-y-4">
          <div v-if="newPromptContent === '' && newPromptRules === ''" class="flex justify-end">
            <button
              class="text-xs px-3 py-1.5 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors flex items-center gap-1.5"
              data-testid="btn-load-template"
              @click="loadTemplates"
            >
              <Icon icon="heroicons:document-duplicate" class="w-3.5 h-3.5" />
              {{ $t('config.taskPrompts.loadTemplate') }}
            </button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
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
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:language" class="w-4 h-4" />
                {{ $t('config.taskPrompts.language') }}
              </label>
              <select
                v-model="newPromptLanguage"
                class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-new-language"
              >
                <option v-for="lang in PROMPT_LANGUAGES" :key="lang.value" :value="lang.value">
                  {{ lang.label }}
                </option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:document-text" class="w-4 h-4" />
              {{ $t('config.taskPrompts.descriptionLabel') }}
            </label>
            <textarea
              v-model="newPromptDescription"
              rows="2"
              class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y"
              :placeholder="$t('config.taskPrompts.descriptionPlaceholder')"
              data-testid="input-new-description"
            ></textarea>
            <p class="text-xs txt-secondary mt-1.5">
              {{ $t('config.taskPrompts.descriptionHelp') }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:bolt" class="w-4 h-4" />
              {{ $t('config.taskPrompts.rulesForSelection') }}
            </label>
            <textarea
              v-model="newPromptRules"
              rows="3"
              class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y"
              :placeholder="SELECTION_RULES_TEMPLATE"
              data-testid="input-new-rules"
            ></textarea>
            <p class="text-xs txt-secondary mt-1.5">{{ $t('config.taskPrompts.rulesHelp') }}</p>
          </div>

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
            <p
              v-if="hasTemplateText"
              class="text-xs text-amber-600 dark:text-amber-400 mt-1.5 flex items-center gap-1"
            >
              <Icon icon="heroicons:exclamation-triangle" class="w-3.5 h-3.5" />
              {{ $t('config.taskPrompts.templatePlaceholderHint') }}
            </p>
          </div>

          <div
            class="border-t border-light-border/30 dark:border-dark-border/20 pt-4"
            data-testid="section-new-files"
          >
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:document-plus" class="w-4 h-4" />
              {{ $t('config.taskPrompts.knowledgeOptional') }}
            </label>
            <p class="text-xs txt-secondary mb-3">
              {{ $t('config.taskPrompts.knowledgeOptionalHint') }}
            </p>

            <div class="mb-3">
              <input
                v-model="newPromptFilesSearch"
                type="text"
                :placeholder="$t('config.taskPrompts.searchFilesPlaceholderShort')"
                class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:outline-none focus:ring-1 focus:ring-[var(--brand)]"
                data-testid="input-new-file-search"
              />
            </div>

            <div
              v-if="newPromptSelectedFiles.length > 0"
              class="mb-3 space-y-1.5"
              data-testid="section-new-selected-files"
            >
              <p class="text-xs font-medium txt-primary">
                {{ $t('config.taskPrompts.selectedCount', { n: newPromptSelectedFiles.length }) }}
              </p>
              <div class="space-y-1">
                <div
                  v-for="fileId in newPromptSelectedFiles"
                  :key="fileId"
                  class="flex items-center justify-between p-2 bg-emerald-500/5 border border-emerald-500/20 rounded text-xs"
                  data-testid="item-new-selected-file"
                >
                  <span class="txt-primary flex-1 min-w-0 truncate">
                    {{ availableFiles.find((f) => f.messageId === fileId)?.fileName || 'Unknown' }}
                  </span>
                  <button
                    class="ml-2 text-rose-500 hover:text-rose-600"
                    :title="$t('common.remove', 'Remove')"
                    data-testid="btn-remove-selected-file"
                    @click="removeFileFromNewPrompt(fileId)"
                  >
                    <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>

            <div
              class="max-h-[200px] overflow-y-auto space-y-1"
              data-testid="section-new-available-files"
            >
              <div
                v-for="file in filteredNewPromptFiles"
                :key="file.messageId"
                class="flex items-center justify-between p-2 surface-chip rounded hover:bg-light-border/10 dark:hover:bg-dark-border/10 cursor-pointer transition-colors text-xs"
                :class="{ 'bg-[var(--brand)]/10': newPromptSelectedFiles.includes(file.messageId) }"
                data-testid="item-new-file"
                @click="toggleFileForNewPrompt(file.messageId)"
              >
                <div class="flex-1 min-w-0">
                  <p class="txt-primary font-medium truncate">{{ file.fileName }}</p>
                  <p class="txt-secondary text-[10px]">
                    {{ $t('config.taskPrompts.chunksCount', { n: file.chunks }) }}
                    <span
                      v-if="file.currentGroupKey"
                      class="ml-1 text-amber-600 dark:text-amber-400"
                    >
                      ({{
                        $t('config.taskPrompts.currentlyUsedIn', { key: file.currentGroupKey })
                      }})
                    </span>
                  </p>
                </div>
                <Icon
                  v-if="newPromptSelectedFiles.includes(file.messageId)"
                  icon="heroicons:check-circle"
                  class="w-4 h-4 text-[var(--brand)] flex-shrink-0 ml-2"
                />
              </div>
              <div
                v-if="filteredNewPromptFiles.length === 0"
                class="text-center py-4 txt-secondary text-xs"
                data-testid="section-new-files-empty"
              >
                {{
                  newPromptFilesSearch
                    ? $t('config.taskPrompts.noFilesMatch')
                    : $t('config.taskPrompts.noVectorizedFiles')
                }}
              </div>
            </div>
          </div>

          <div
            class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 pt-4 border-t border-light-border/30 dark:border-dark-border/20"
          >
            <button
              class="w-full sm:flex-1 px-6 py-3 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-light-border/10 dark:hover:bg-dark-border/10 transition-colors font-medium"
              data-testid="btn-cancel-create"
              @click="showCreateModal = false"
            >
              {{ $t('common.cancel', 'Cancel') }}
            </button>
            <button
              :disabled="!canCreatePrompt"
              class="w-full sm:flex-1 px-6 py-3 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
              data-testid="btn-confirm-create"
              @click="handleCreateNew"
            >
              <Icon icon="heroicons:plus-circle" class="w-5 h-5" />
              {{ $t('config.taskPrompts.createButton') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <UnsavedChangesBar
      :show="hasUnsavedChanges"
      data-testid="comp-unsaved-bar"
      @save="handleSave"
      @discard="handleDiscard"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useEscapeKey } from '@/composables/useEscapeKey'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import { Icon } from '@iconify/vue'
import {
  promptsApi,
  type TaskPrompt as ApiTaskPrompt,
  type PromptFile,
  type AvailableFile,
  type PromptMetadata,
  type UpdatePromptRequest,
} from '@/services/api/promptsApi'
import { configApi } from '@/services/api/configApi'
import type { AIModel, Capability } from '@/types/ai-models'
import { findModelIdByString } from '@/utils/aiModelDefaults'
import {
  type InternetSearchMode,
  internetModeFromMetadata,
  applyInternetModeToMetadata,
} from '@/utils/promptInternetSearch'
import { useNotification } from '@/composables/useNotification'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { useDialog } from '@/composables/useDialog'
import ModelSelectDropdown from '@/components/ModelSelectDropdown.vue'
import { useAuthStore } from '@/stores/auth'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import { ApiError } from '@/services/api/httpClient'

const SELECTION_RULES_TEMPLATE =
  'When the user mentions [TOPIC_NAME] or asks about [SPECIFIC_KEYWORDS], route to this prompt.'
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

interface TaskPrompt extends ApiTaskPrompt {
  rules?: string
  aiModel?: string
  availableTools?: string[]
  toolInternet?: InternetSearchMode
  content: string
}

interface ToolOption {
  value: string
  label: string
  icon: string
}

type EditorTabId = 'routing' | 'prompt' | 'knowledge' | 'danger'

const { success, error: showError } = useNotification()
const dialog = useDialog()
const { t, locale } = useI18n()
const { formatRelativeTime } = useDateFormat()
const authStore = useAuthStore()
const isAdmin = computed(() => authStore.isAdmin)

const PROMPT_LANGUAGES = [
  { value: 'en', label: 'English' },
  { value: 'de', label: 'Deutsch' },
  { value: 'es', label: 'Español' },
  { value: 'tr', label: 'Türkçe' },
]

const currentLanguageLabel = computed(() => {
  const currentLang = locale.value || 'en'
  const found = PROMPT_LANGUAGES.find((l) => l.value === currentLang)
  return found ? `${found.label} (${found.value})` : currentLang
})

const prompts = ref<TaskPrompt[]>([])
const selectedPromptId = ref<number | null>(null)
const currentPrompt = ref<TaskPrompt | null>(null)
const formData = ref<Partial<TaskPrompt>>({})
const originalData = ref<Partial<TaskPrompt>>({})
const newPromptName = ref('')
const newPromptTopic = ref('')
const newPromptContent = ref('')
const newPromptRules = ref('')
const newPromptDescription = ref('')
const newPromptLanguage = ref(locale.value || 'en')
const newPromptSelectedFiles = ref<number[]>([])
const newPromptFilesSearch = ref('')
const showCreateModal = ref(false)
const promptListSearch = ref('')
const promptListFilter = ref<'all' | 'system' | 'custom'>('all')
const activeTab = ref<EditorTabId>('routing')
const viewDensity = ref<'compact' | 'detailed'>('compact')
const collapsedGroups = ref<Set<string>>(new Set())
// Mobile: when an editor is open, the list slides out of view; the back button
// brings it back. On `lg+` both panes are always visible side by side.
const listVisibleMobile = ref(true)

// Categorise prompts so the filter chips can show counts inline
const systemPromptCount = computed(
  () => prompts.value.filter((p) => p.isDefault && !p.isUserOverride).length
)
const customPromptCount = computed(() => prompts.value.filter((p) => !p.isDefault).length)

const promptListFilters = computed(() => [
  {
    value: 'all' as const,
    label: t('config.taskPrompts.filterAll'),
    count: prompts.value.length,
  },
  {
    value: 'system' as const,
    label: t('config.taskPrompts.filterSystem'),
    count: systemPromptCount.value,
  },
  {
    value: 'custom' as const,
    label: t('config.taskPrompts.filterCustom'),
    count: customPromptCount.value,
  },
])

const contentLength = computed(() => (formData.value.content || '').length)
const contentWordCount = computed(() => {
  const text = (formData.value.content || '').trim()
  if (!text) return 0
  return text.split(/\s+/).length
})

const filteredPrompts = computed(() => {
  const search = promptListSearch.value.trim().toLowerCase()
  return prompts.value.filter((p) => {
    if (promptListFilter.value === 'system' && !(p.isDefault && !p.isUserOverride)) {
      return false
    }
    if (promptListFilter.value === 'custom' && p.isDefault && !p.isUserOverride) {
      return false
    }
    if (search === '') return true
    const haystack = [p.name, p.topic, p.shortDescription].join(' ').toLowerCase()
    return haystack.includes(search)
  })
})

/**
 * Map a prompt to a display category for grouping in the sidebar list.
 */
type CategoryId =
  'conversation' | 'code' | 'generation' | 'productivity' | 'other-system' | 'custom'

interface CategoryDef {
  id: CategoryId
  label: string
  icon: string
}

function topicCategory(prompt: TaskPrompt): CategoryId {
  if (!prompt.isDefault) return 'custom'
  const t = prompt.topic.toLowerCase()
  if (
    t.includes('image') ||
    t.includes('video') ||
    t.includes('audio') ||
    t.includes('media') ||
    t.includes('voice') ||
    t.includes('sound')
  ) {
    return 'generation'
  }
  if (t.includes('code') || t.includes('coding') || t.includes('dev')) {
    return 'code'
  }
  if (
    t.includes('office') ||
    t.includes('summary') ||
    t.includes('translate') ||
    t.includes('mail') ||
    t.includes('email') ||
    t.includes('docs')
  ) {
    return 'productivity'
  }
  if (t.includes('chat') || t === 'general' || t.includes('smalltalk')) {
    return 'conversation'
  }
  return 'other-system'
}

const categorizedPrompts = computed(() => {
  const order: CategoryDef[] = [
    {
      id: 'conversation',
      label: t('config.taskPrompts.categoryConversation'),
      icon: 'heroicons:chat-bubble-left-right',
    },
    {
      id: 'code',
      label: t('config.taskPrompts.categoryCode'),
      icon: 'heroicons:code-bracket-square',
    },
    {
      id: 'generation',
      label: t('config.taskPrompts.categoryGeneration'),
      icon: 'heroicons:sparkles',
    },
    {
      id: 'productivity',
      label: t('config.taskPrompts.categoryProductivity'),
      icon: 'heroicons:briefcase',
    },
    {
      id: 'other-system',
      label: t('config.taskPrompts.categoryOther'),
      icon: 'heroicons:rectangle-stack',
    },
    {
      id: 'custom',
      label: t('config.taskPrompts.categoryCustom'),
      icon: 'heroicons:user',
    },
  ]

  const buckets: Record<CategoryId, TaskPrompt[]> = {
    conversation: [],
    code: [],
    generation: [],
    productivity: [],
    'other-system': [],
    custom: [],
  }

  for (const prompt of filteredPrompts.value) {
    buckets[topicCategory(prompt)].push(prompt)
  }

  // Stable name sort within each bucket
  for (const id of Object.keys(buckets) as CategoryId[]) {
    buckets[id].sort((a, b) => a.name.localeCompare(b.name))
  }

  return order.map((cat) => ({ ...cat, prompts: buckets[cat.id] }))
})

const allGroupsCollapsed = computed(() => {
  const visibleGroups = categorizedPrompts.value.filter((g) => g.prompts.length > 0)
  if (visibleGroups.length === 0) return false
  return visibleGroups.every((g) => collapsedGroups.value.has(g.id))
})

/**
 * Treat any group that holds a search hit as forced-open so the user never
 * has to expand a category just to see why their search matched. Manual
 * collapse-state still applies once the search field is empty again.
 */
const isGroupCollapsed = (id: string) => {
  if (promptListSearch.value.trim() !== '') return false
  return collapsedGroups.value.has(id)
}

const toggleGroup = (id: string) => {
  const next = new Set(collapsedGroups.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  collapsedGroups.value = next
}

const toggleAllGroups = () => {
  if (allGroupsCollapsed.value) {
    collapsedGroups.value = new Set()
  } else {
    collapsedGroups.value = new Set(
      categorizedPrompts.value.filter((g) => g.prompts.length > 0).map((g) => g.id)
    )
  }
}

const editorTabs = computed(() => {
  const tabs: { id: EditorTabId; label: string; icon: string }[] = [
    {
      id: 'routing',
      label: t('config.taskPrompts.tabRouting'),
      icon: 'heroicons:share',
    },
    {
      id: 'prompt',
      label: t('config.taskPrompts.tabPrompt'),
      icon: 'heroicons:code-bracket',
    },
  ]
  if (currentPrompt.value && (!currentPrompt.value.isDefault || isAdmin.value)) {
    tabs.push({
      id: 'knowledge',
      label: t('config.taskPrompts.tabKnowledge'),
      icon: 'heroicons:book-open',
    })
    tabs.push({
      id: 'danger',
      label: t('config.taskPrompts.tabDanger'),
      icon: 'heroicons:exclamation-triangle',
    })
  }
  return tabs
})

useEscapeKey(() => (showCreateModal.value = false), showCreateModal)

const contentTextarea = ref<HTMLTextAreaElement | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const promptFiles = ref<PromptFile[]>([])
const availableFiles = ref<AvailableFile[]>([])
const availableFilesSearch = ref('')
const loadingAvailableFiles = ref(false)

const allModels = ref<Partial<Record<Capability, AIModel[]>>>({})
const loadingModels = ref(false)

// Internet search is handled separately via a tri-state dropdown (see
// `InternetSearchMode`); the remaining tools are plain on/off checkboxes.
const availableTools: ToolOption[] = [
  { value: 'files-search', label: 'Files Search', icon: 'heroicons:document-magnifying-glass' },
  { value: 'url-screenshot', label: 'URL Content', icon: 'heroicons:globe-alt' },
  // Per-topic gate for external MCP data sources (Channels → MCP Servers).
  // Opt-in per topic (release 4.0 plan 09); the seeded `general` topic ships
  // with it ON so a freshly connected server works for normal chat questions
  // out of the box (PromptCatalog release defaults).
  { value: 'mcp-data', label: 'MCP Data Sources', icon: 'heroicons:server-stack' },
]

const groupedModels = computed(() => {
  const groups: { label: string; models: AIModel[]; capability: Capability }[] = []

  // All UI text goes through vue-i18n; reuse the canonical capability
  // labels defined in `config.aiModels.capabilities.*` so we don't
  // duplicate translations across components / languages.
  const capabilityLabels: Record<Capability, string> = {
    CHAT: t('config.aiModels.capabilities.chat'),
    SORT: t('config.aiModels.capabilities.sort'),
    MEM: t('config.aiModels.capabilities.mem'),
    ANALYZE: t('config.aiModels.capabilities.analyze'),
    TEXT2PIC: t('config.aiModels.capabilities.text2pic'),
    PIC2PIC: t('config.aiModels.capabilities.pic2pic'),
    TEXT2VID: t('config.aiModels.capabilities.text2vid'),
    IMG2VID: t('config.aiModels.capabilities.img2vid'),
    TEXT2SOUND: t('config.aiModels.capabilities.text2sound'),
    SOUND2TEXT: t('config.aiModels.capabilities.sound2text'),
    PIC2TEXT: t('config.aiModels.capabilities.pic2text'),
    VECTORIZE: t('config.aiModels.capabilities.vectorize'),
  }

  const orderedCapabilities: Capability[] = [
    'CHAT',
    'TEXT2PIC',
    'TEXT2VID',
    'TEXT2SOUND',
    'SOUND2TEXT',
    'PIC2TEXT',
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

const hasTemplateText = computed(() => {
  const rulesHasTemplate =
    newPromptRules.value.includes('[TOPIC_NAME]') ||
    newPromptRules.value.includes('[SPECIFIC_KEYWORDS]')
  const contentHasTemplate =
    newPromptContent.value.includes('[YOUR_SPECIALTY]') ||
    newPromptContent.value.includes('[DESCRIBE_THE_MAIN_OBJECTIVE]') ||
    newPromptContent.value.includes('[GUIDELINE_') ||
    newPromptContent.value.includes('[INSTRUCTION_') ||
    newPromptContent.value.includes('[IMPORTANT_REMINDER]')
  return rulesHasTemplate || contentHasTemplate
})

const canCreatePrompt = computed(() => {
  return (
    !loading.value &&
    newPromptName.value.trim() !== '' &&
    newPromptTopic.value.trim() !== '' &&
    newPromptContent.value.trim() !== '' &&
    !hasTemplateText.value
  )
})

const filteredNewPromptFiles = computed(() => {
  if (!newPromptFilesSearch.value.trim()) {
    return availableFiles.value
  }
  const search = newPromptFilesSearch.value.toLowerCase()
  return availableFiles.value.filter(
    (file) =>
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

/**
 * Map a topic slug to a heroicon. Falls back to a generic chat icon.
 * Topics get distinct visuals so the list scans well at a glance.
 */
function topicIcon(topic: string): string {
  const t = topic.toLowerCase()
  if (t.includes('image')) return 'heroicons:photo'
  if (t.includes('video')) return 'heroicons:film'
  if (t.includes('audio') || t.includes('sound') || t.includes('voice')) {
    return 'heroicons:musical-note'
  }
  if (t.includes('code') || t.includes('coding') || t.includes('dev')) {
    return 'heroicons:code-bracket-square'
  }
  if (t.includes('office') || t.includes('excel') || t.includes('word') || t.includes('ppt')) {
    return 'heroicons:table-cells'
  }
  if (t.includes('summary') || t.includes('docsummary') || t.includes('summarize')) {
    return 'heroicons:document-arrow-down'
  }
  if (t.includes('search') || t.includes('rag') || t.includes('knowledge')) {
    return 'heroicons:magnifying-glass-circle'
  }
  if (t.includes('mail') || t.includes('email')) return 'heroicons:envelope'
  if (t.includes('translate') || t.includes('language')) return 'heroicons:language'
  if (t.startsWith('w_')) return 'heroicons:rectangle-group'
  if (t.includes('chat') || t === 'general') {
    return 'heroicons:chat-bubble-left-right'
  }
  return 'heroicons:sparkles'
}

const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } = useUnsavedChanges(
  formData,
  originalData
)

let cleanupGuard: (() => void) | undefined

const loadAIModels = async () => {
  loadingModels.value = true
  try {
    const response = await configApi.getModels()
    if (response.success) {
      allModels.value = response.models
    }
  } catch (err: unknown) {
    console.error('Failed to load AI models:', err)
  } finally {
    loadingModels.value = false
  }
}

const loadPrompts = async () => {
  loading.value = true
  error.value = null

  try {
    const data = await promptsApi.getPrompts(locale.value || 'en')
    const nonWidgetPrompts = data.filter((p) => !p.topic.startsWith('w_'))
    prompts.value = nonWidgetPrompts.map((p) => {
      const metadata = p.metadata || {}

      let aiModelString = 'default'
      if (metadata.aiModel && metadata.aiModel > 0) {
        let foundModel = null
        for (const models of Object.values(allModels.value)) {
          if (models) {
            foundModel = models.find((m: AIModel) => m.id === metadata.aiModel)
            if (foundModel) break
          }
        }
        if (foundModel) {
          aiModelString = `${foundModel.name} (${foundModel.service})`
        }
      }

      const tools: string[] = []
      if (metadata.tool_files ?? metadata.tool_files_search) tools.push('files-search')
      if (metadata.tool_url_screenshot) tools.push('url-screenshot')
      if (metadata.tool_mcp) tools.push('mcp-data')

      return {
        ...p,
        content: p.prompt,
        rules: p.selectionRules || '',
        aiModel: aiModelString,
        availableTools: tools,
        toolInternet: internetModeFromMetadata(metadata),
      }
    })
  } catch (err: unknown) {
    const errorMessage = err instanceof Error ? err.message : 'Failed to load prompts'
    error.value = errorMessage
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

const loadPrompt = () => {
  const prompt = prompts.value.find((p) => p.id === selectedPromptId.value)
  if (prompt) {
    currentPrompt.value = { ...prompt }
    formData.value = {
      shortDescription: prompt.shortDescription || '',
      selectionRules: prompt.selectionRules || '',
      aiModel: prompt.aiModel,
      availableTools: prompt.availableTools,
      toolInternet: prompt.toolInternet ?? 'auto',
      content: prompt.content,
      language: prompt.language || 'en',
    }
    originalData.value = { ...formData.value }
    activeTab.value = 'routing'
    loadPromptFiles()
  }
}

const onPromptSelect = async () => {
  if (hasUnsavedChanges.value) {
    const confirmed = await dialog.confirm({
      title: t('config.taskPrompts.unsavedTitle'),
      message: t('config.taskPrompts.unsavedMessage'),
      confirmText: t('config.taskPrompts.unsavedDiscard'),
      cancelText: t('common.cancel', 'Cancel'),
      danger: true,
    })

    if (!confirmed) {
      selectedPromptId.value = currentPrompt.value?.id || null
      return
    }
  }
  loadPrompt()
}

const onCardSelect = async (id: number) => {
  if (selectedPromptId.value === id) {
    // Same topic: just hide the mobile sidebar (lg+ ignores this)
    listVisibleMobile.value = false
    return
  }
  if (hasUnsavedChanges.value) {
    const confirmed = await dialog.confirm({
      title: t('config.taskPrompts.unsavedTitle'),
      message: t('config.taskPrompts.unsavedMessage'),
      confirmText: t('config.taskPrompts.unsavedDiscard'),
      cancelText: t('common.cancel', 'Cancel'),
      danger: true,
    })
    if (!confirmed) return
  }
  selectedPromptId.value = id
  loadPrompt()
  listVisibleMobile.value = false
}

const closeEditor = () => {
  selectedPromptId.value = null
  currentPrompt.value = null
  formData.value = {}
  originalData.value = {}
}

/**
 * Mobile-only navigation: when the editor is open and the viewport is narrow,
 * the topic list is hidden behind it. The back button restores the list view
 * without dropping the current selection (so reopening keeps state intact).
 */
const backToListMobile = () => {
  listVisibleMobile.value = true
  closeEditor()
}

const clearFilters = () => {
  promptListSearch.value = ''
  promptListFilter.value = 'all'
}

const insertMarkdown = (before: string, after: string) => {
  const textarea = contentTextarea.value
  if (!textarea || !formData.value.content) return

  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const text = formData.value.content
  const selectedText = text.substring(start, end)

  formData.value.content =
    text.substring(0, start) + before + selectedText + after + text.substring(end)

  setTimeout(() => {
    textarea.focus()
    textarea.setSelectionRange(start + before.length, end + before.length)
  }, 0)
}

const handleSave = saveChanges(async () => {
  if (!currentPrompt.value) return

  try {
    const metadata: PromptMetadata = {}

    if (formData.value.aiModel === 'default' || !formData.value.aiModel) {
      metadata.aiModel = 0
    } else {
      metadata.aiModel = findModelIdByString(allModels.value, formData.value.aiModel)
    }

    metadata.tool_files = (formData.value.availableTools || []).includes('files-search')
    metadata.tool_url_screenshot = (formData.value.availableTools || []).includes('url-screenshot')
    metadata.tool_mcp = (formData.value.availableTools || []).includes('mcp-data')

    // Tri-state internet search (#1138): only persist an explicit boolean for
    // 'on'/'off'. 'auto' omits the key so the backend keeps the "classifier
    // decides" default instead of hard-disabling web search.
    applyInternetModeToMetadata(metadata, formData.value.toolInternet ?? 'auto')

    if (currentPrompt.value.isDefault && !currentPrompt.value.isUserOverride && !isAdmin.value) {
      const newPrompt = await promptsApi.createPrompt({
        topic: currentPrompt.value.topic,
        shortDescription: formData.value.shortDescription || currentPrompt.value.shortDescription,
        prompt: formData.value.content || '',
        language: formData.value.language || locale.value || 'en',
        selectionRules: formData.value.selectionRules ?? null,
        metadata,
      })

      const index = prompts.value.findIndex((p) => p.id === currentPrompt.value!.id)
      if (index !== -1) {
        prompts.value[index] = {
          ...newPrompt,
          content: newPrompt.prompt,
          rules: newPrompt.selectionRules || '',
          aiModel: formData.value.aiModel,
          availableTools: formData.value.availableTools,
          isUserOverride: true,
        }
        currentPrompt.value = { ...prompts.value[index] }
        selectedPromptId.value = newPrompt.id
        originalData.value = { ...formData.value }
      }
    } else {
      const isSystemPrompt = currentPrompt.value.isDefault
      const updatePayload: UpdatePromptRequest = {
        shortDescription: formData.value.shortDescription || currentPrompt.value.shortDescription,
        prompt: formData.value.content || '',
        selectionRules: formData.value.selectionRules ?? null,
        metadata,
      }
      if (!isSystemPrompt) {
        updatePayload.language = formData.value.language || 'en'
      }
      const updated = await promptsApi.updatePrompt(currentPrompt.value.id, updatePayload)

      const index = prompts.value.findIndex((p) => p.id === currentPrompt.value!.id)
      if (index !== -1) {
        prompts.value[index] = {
          ...updated,
          content: updated.prompt,
          rules: updated.selectionRules || '',
          aiModel: formData.value.aiModel,
          availableTools: formData.value.availableTools,
        }
        currentPrompt.value = { ...prompts.value[index] }
        originalData.value = { ...formData.value }
      }
    }
  } catch (err: unknown) {
    // Issue #891: the prompt save now mirrors ConfigController's premium
    // gate. When the backend rejects the chosen aiModel for the current
    // subscription tier, surface the structured reason instead of a
    // generic "Failed to save" toast — same pattern as
    // AIModelsConfiguration.vue (issue #883).
    if (err instanceof ApiError && err.status === 403 && err.code === 'requires_premium') {
      showError(t('config.taskPrompts.saveErrorPremiumRequired', { reason: err.message }))
      throw err
    }

    let errorMessage = err instanceof Error ? err.message : 'Failed to save prompt'

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

const handleDiscard = () => {
  discardChanges()
}

const loadTemplates = () => {
  const topicName = newPromptTopic.value.trim()
  const displayName = newPromptName.value.trim()

  let rules = SELECTION_RULES_TEMPLATE
  if (topicName) {
    rules = rules.replace(/\[TOPIC_NAME\]/g, displayName || topicName)
    rules = rules.replace(/\[SPECIFIC_KEYWORDS\]/g, topicName.replace(/-/g, ' '))
  }

  let content = PROMPT_CONTENT_TEMPLATE
  if (displayName || topicName) {
    const specialty = displayName || topicName.replace(/-/g, ' ')
    content = content.replace(/\[YOUR_SPECIALTY\]/g, specialty)
    content = content.replace(/\[TOPIC_NAME\]/g, specialty)
  }

  newPromptRules.value = rules
  newPromptContent.value = content
}

const toggleFileForNewPrompt = (fileId: number) => {
  if (newPromptSelectedFiles.value.includes(fileId)) {
    newPromptSelectedFiles.value = newPromptSelectedFiles.value.filter((id) => id !== fileId)
  } else {
    newPromptSelectedFiles.value = [...newPromptSelectedFiles.value, fileId]
  }
}

const removeFileFromNewPrompt = (fileId: number) => {
  newPromptSelectedFiles.value = newPromptSelectedFiles.value.filter((id) => id !== fileId)
}

const handleCreateNew = async () => {
  if (
    !newPromptName.value.trim() ||
    !newPromptTopic.value.trim() ||
    !newPromptContent.value.trim() ||
    loading.value
  ) {
    showError(t('config.taskPrompts.createValidation'))
    return
  }

  if (hasTemplateText.value) {
    showError(t('config.taskPrompts.templatePlaceholderHint'))
    return
  }

  loading.value = true

  try {
    const metadata: PromptMetadata = {}

    metadata.aiModel = 0
    // Leave `tool_internet` unset so new prompts default to "auto" (the
    // classifier decides) instead of hard-forcing web search on every message.
    metadata.tool_files = true
    metadata.tool_url_screenshot = false
    // External MCP calls are opt-in per topic (release 4.0 plan 09).
    metadata.tool_mcp = false

    const requestPayload = {
      topic: newPromptTopic.value.trim().toLowerCase().replace(/\s+/g, '-'),
      shortDescription: newPromptDescription.value.trim() || newPromptName.value.trim(),
      prompt: newPromptContent.value.trim(),
      language: newPromptLanguage.value || locale.value || 'en',
      selectionRules: newPromptRules.value.trim() || null,
      metadata,
    }

    const newPrompt = await promptsApi.createPrompt(requestPayload)

    const mappedPrompt: TaskPrompt = {
      ...newPrompt,
      content: newPrompt.prompt,
      rules: newPrompt.selectionRules || '',
      aiModel: 'default',
      availableTools: ['files-search'],
      toolInternet: 'auto',
    }

    prompts.value.push(mappedPrompt)
    selectedPromptId.value = newPrompt.id
    currentPrompt.value = { ...mappedPrompt }
    formData.value = {
      shortDescription: mappedPrompt.shortDescription || '',
      selectionRules: mappedPrompt.selectionRules || '',
      aiModel: mappedPrompt.aiModel,
      availableTools: mappedPrompt.availableTools,
      toolInternet: mappedPrompt.toolInternet,
      content: mappedPrompt.content,
      language: mappedPrompt.language || 'en',
    }
    originalData.value = { ...formData.value }
    activeTab.value = 'routing'

    if (newPromptSelectedFiles.value.length > 0) {
      let linkedCount = 0
      const failedFiles: number[] = []

      for (const fileId of newPromptSelectedFiles.value) {
        try {
          const result = await promptsApi.linkFileToPrompt(newPrompt.topic, fileId)
          if (result && result.chunksLinked === 0) {
            failedFiles.push(fileId)
          } else {
            linkedCount++
          }
        } catch (linkErr: unknown) {
          console.error(`Failed to link file ${fileId}:`, linkErr)
          failedFiles.push(fileId)
        }
      }

      if (failedFiles.length === 0) {
        success(t('config.taskPrompts.createSuccessWithFiles', { count: linkedCount }))
      } else if (linkedCount > 0) {
        success(
          t('config.taskPrompts.createSuccessPartialFiles', {
            linked: linkedCount,
            failed: failedFiles.length,
          })
        )
      } else {
        success(t('config.taskPrompts.createSuccessNoFilesLinked'))
      }
    } else {
      success(t('config.taskPrompts.createSuccess'))
    }

    newPromptName.value = ''
    newPromptTopic.value = ''
    newPromptContent.value = ''
    newPromptRules.value = ''
    newPromptDescription.value = ''
    newPromptLanguage.value = locale.value || 'en'
    newPromptSelectedFiles.value = []
    newPromptFilesSearch.value = ''
    showCreateModal.value = false

    await loadPromptFiles()
  } catch (err: unknown) {
    // Issue #891: prompt create also gates the aiModel through the
    // premium guard. Show the structured reason from the backend.
    if (err instanceof ApiError && err.status === 403 && err.code === 'requires_premium') {
      showError(t('config.taskPrompts.saveErrorPremiumRequired', { reason: err.message }))
      return
    }

    let errorMessage = err instanceof Error ? err.message : 'Failed to create prompt'

    if (errorMessage.includes('already have a prompt with this topic')) {
      errorMessage = t('config.taskPrompts.errorTopicExists')
    } else if (errorMessage.includes('tools:')) {
      errorMessage = t('config.taskPrompts.errorToolsPrefix')
    } else if (errorMessage.includes('Missing required fields')) {
      errorMessage = t('config.taskPrompts.errorMissingFields')
    }

    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

const handleDelete = async () => {
  if (!currentPrompt.value || loading.value) return
  if (currentPrompt.value.isDefault && !isAdmin.value) return

  const isSystemPrompt = currentPrompt.value.isDefault
  const confirmed = await dialog.confirm({
    title: isSystemPrompt
      ? t('config.taskPrompts.deleteSystemTitle')
      : t('config.taskPrompts.deleteTitle'),
    message: isSystemPrompt
      ? t('config.taskPrompts.deleteSystemConfirm', { name: currentPrompt.value.name })
      : t('config.taskPrompts.deleteConfirm', { name: currentPrompt.value.name }),
    confirmText: t('config.taskPrompts.deletePrompt'),
    cancelText: t('common.cancel', 'Cancel'),
    danger: true,
  })

  if (!confirmed) return

  loading.value = true

  try {
    await promptsApi.deletePrompt(currentPrompt.value.id)

    const index = prompts.value.findIndex((p) => p.id === currentPrompt.value!.id)
    if (index !== -1) {
      prompts.value.splice(index, 1)
      selectedPromptId.value = null
      currentPrompt.value = null
    }

    success(t('config.taskPrompts.deleteSuccess'))
  } catch (err: unknown) {
    const errorMessage = err instanceof Error ? err.message : 'Failed to delete prompt'
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

const loadPromptFiles = async () => {
  if (!currentPrompt.value?.topic) {
    promptFiles.value = []
    return
  }

  try {
    promptFiles.value = await promptsApi.getPromptFiles(currentPrompt.value.topic)
  } catch (err: unknown) {
    console.error('Failed to load prompt files:', err)
    promptFiles.value = []
  }
}

const handleDeleteFile = async (messageId: number) => {
  if (!currentPrompt.value?.topic) return

  const confirmed = await dialog.confirm({
    title: t('config.taskPrompts.deleteFileTitle'),
    message: t('config.taskPrompts.deleteFileConfirm'),
    confirmText: t('config.taskPrompts.deletePrompt'),
    cancelText: t('common.cancel', 'Cancel'),
    danger: true,
  })

  if (!confirmed) return

  try {
    await promptsApi.deletePromptFile(currentPrompt.value.topic, messageId)
    success(t('config.taskPrompts.deleteFileSuccess'))
    await loadPromptFiles()
  } catch (err: unknown) {
    const errorMessage = err instanceof Error ? err.message : 'Failed to delete file'
    showError(errorMessage)
  }
}

const formatDate = (dateString: string): string => {
  return formatRelativeTime(new Date(dateString))
}

const loadAvailableFiles = async () => {
  loadingAvailableFiles.value = true
  try {
    availableFiles.value = await promptsApi.getAvailableFiles(availableFilesSearch.value)
  } catch (err: unknown) {
    console.error('Failed to load available files:', err)
    availableFiles.value = []
  } finally {
    loadingAvailableFiles.value = false
  }
}

const isFileLinked = (messageId: number): boolean => {
  return promptFiles.value.some((f) => f.messageId === messageId)
}

const handleLinkFile = async (messageId: number) => {
  if (!currentPrompt.value?.topic) return

  try {
    const result = await promptsApi.linkFileToPrompt(currentPrompt.value.topic, messageId)

    if (result.chunksLinked === 0) {
      showError(t('config.taskPrompts.fileLinkErrorNoChunks'))
    } else {
      success(t('config.taskPrompts.fileLinkSuccess', { count: result.chunksLinked }))
    }

    await Promise.all([loadPromptFiles(), loadAvailableFiles()])
  } catch (err: unknown) {
    const errorMessage = err instanceof Error ? err.message : 'Failed to link file'
    showError(errorMessage)
  }
}

watch(locale, () => {
  loadPrompts()
})

onMounted(() => {
  cleanupGuard = setupNavigationGuard()
  Promise.all([loadAIModels(), loadPrompts(), loadAvailableFiles()]).then(() => {
    const urlParams = new URLSearchParams(window.location.search)
    const topicParam = urlParams.get('topic')
    if (topicParam) {
      const prompt = prompts.value.find((p) => p.topic === topicParam)
      if (prompt) {
        selectedPromptId.value = prompt.id
        loadPrompt()
        // Deep link from another page: open the editor and stash the list (mobile)
        listVisibleMobile.value = false
      }
    }
  })
})

onUnmounted(() => {
  cleanupGuard?.()
})
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
