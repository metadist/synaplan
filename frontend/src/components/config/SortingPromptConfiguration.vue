<template>
  <div class="space-y-6" data-testid="page-config-routing">
    <!-- Header -->
    <div class="surface-card p-6" data-testid="section-routing-overview">
      <div class="flex items-start gap-3">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10">
          <Icon icon="heroicons:share" class="w-6 h-6 text-[var(--brand)]" />
        </div>
        <div class="flex-1">
          <h2 class="text-2xl font-semibold txt-primary mb-1">
            {{ $t('config.routing.title') }}
          </h2>
          <p class="txt-secondary text-sm">
            {{ $t('config.routing.subtitle') }}
          </p>
          <p class="text-xs txt-secondary mt-2">
            {{ $t('config.routing.editPromptsHint') }}
            <router-link
              to="/config/task-prompts"
              class="text-[var(--brand)] hover:underline font-medium"
            >
              {{ $t('config.routing.editPromptsLink') }}
            </router-link>
          </p>
        </div>
      </div>
    </div>

    <!-- Live status (admin) -->
    <div v-if="isAdmin" class="surface-card p-6" data-testid="section-routing-status">
      <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <Icon icon="heroicons:signal" class="w-5 h-5 text-[var(--brand)]" />
          {{ $t('config.routing.statusTitle') }}
        </h3>
        <div class="flex items-center gap-2">
          <button
            class="px-3 py-2 rounded-lg surface-chip txt-secondary hover:txt-primary text-sm flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-status-refresh"
            :disabled="loadingStatus"
            @click="loadStatus"
          >
            <Icon
              :icon="loadingStatus ? 'heroicons:arrow-path' : 'heroicons:arrow-path'"
              :class="['w-4 h-4', loadingStatus && 'animate-spin']"
            />
            {{ $t('config.routing.refresh') }}
          </button>
          <button
            class="px-3 py-2 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 text-sm font-medium flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-reindex-force"
            :disabled="reindexing"
            @click="reindex({ force: true })"
          >
            <Icon icon="heroicons:bolt" class="w-4 h-4" />
            {{ $t('config.routing.reindexForce') }}
          </button>
          <button
            class="px-3 py-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 text-sm font-medium flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-reindex-recreate"
            :disabled="reindexing"
            @click="confirmRecreate"
          >
            <Icon icon="heroicons:arrow-uturn-left" class="w-4 h-4" />
            {{ $t('config.routing.reindexRecreate') }}
          </button>
        </div>
      </div>

      <!-- Dim mismatch warning -->
      <div
        v-if="status?.dimensionMismatch"
        class="p-3 mb-4 bg-amber-500/10 border border-amber-500/30 rounded-lg flex items-start gap-2"
        data-testid="banner-dim-mismatch"
      >
        <Icon
          icon="heroicons:exclamation-triangle"
          class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"
        />
        <div class="text-sm">
          <p class="font-medium text-amber-700 dark:text-amber-300">
            {{ $t('config.routing.dimMismatchTitle') }}
          </p>
          <p class="text-amber-700/80 dark:text-amber-300/80 mt-1">
            {{
              $t('config.routing.dimMismatchBody', {
                collectionDim: status?.collection.vectorDim ?? '?',
                modelDim: status?.activeModel.vectorDim ?? '?',
              })
            }}
          </p>
        </div>
      </div>

      <!-- Stat cards -->
      <div v-if="status" class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="surface-chip rounded-lg p-3" data-testid="stat-active-model">
          <p class="text-xs txt-secondary uppercase tracking-wide mb-1">
            {{ $t('config.routing.activeModel') }}
          </p>
          <p class="text-sm font-semibold txt-primary truncate">
            {{ status.activeModel.model || '—' }}
          </p>
          <p class="text-xs txt-secondary">
            {{ status.activeModel.provider || '—' }}
            ·
            {{ $t('config.routing.dim', { dim: status.activeModel.vectorDim }) }}
          </p>
        </div>

        <div class="surface-chip rounded-lg p-3" data-testid="stat-indexed">
          <p class="text-xs txt-secondary uppercase tracking-wide mb-1">
            {{ $t('config.routing.indexed') }}
          </p>
          <p class="text-2xl font-semibold txt-primary">{{ status.totalIndexed }}</p>
          <p class="text-xs txt-secondary">
            {{ $t('config.routing.collection', { name: status.collection.name }) }}
          </p>
        </div>

        <div class="surface-chip rounded-lg p-3" data-testid="stat-stale">
          <p class="text-xs txt-secondary uppercase tracking-wide mb-1">
            {{ $t('config.routing.stale') }}
          </p>
          <p
            class="text-2xl font-semibold"
            :class="status.staleCount > 0 ? 'text-amber-600 dark:text-amber-400' : 'txt-primary'"
          >
            {{ status.staleCount }}
          </p>
          <p class="text-xs txt-secondary">
            {{ $t('config.routing.staleHint') }}
          </p>
        </div>

        <div class="surface-chip rounded-lg p-3" data-testid="stat-disabled">
          <p class="text-xs txt-secondary uppercase tracking-wide mb-1">
            {{ $t('config.routing.disabled') }}
          </p>
          <p class="text-2xl font-semibold txt-primary">{{ disabledTopicsCount }}</p>
          <p class="text-xs txt-secondary">
            {{ $t('config.routing.disabledHint') }}
          </p>
        </div>
      </div>

      <!-- Per-model breakdown -->
      <div v-if="status && status.perModel.length > 0" class="mt-4">
        <p class="text-xs txt-secondary uppercase tracking-wide mb-2">
          {{ $t('config.routing.perModelBreakdown') }}
        </p>
        <div class="flex flex-wrap gap-2">
          <span
            v-for="(entry, idx) in status.perModel"
            :key="`pm-${idx}`"
            class="px-3 py-1.5 rounded-full text-xs surface-chip txt-primary flex items-center gap-1.5"
            data-testid="chip-per-model"
          >
            <Icon
              :icon="
                entry.modelId === status.activeModel.modelId
                  ? 'heroicons:check-circle'
                  : 'heroicons:exclamation-triangle'
              "
              :class="[
                'w-3.5 h-3.5',
                entry.modelId === status.activeModel.modelId
                  ? 'text-emerald-500'
                  : 'text-amber-500',
              ]"
            />
            {{ entry.model || $t('config.routing.unknownModel') }}
            <span class="txt-secondary">({{ entry.vectorDim ?? '?' }}d)</span>
            <span class="font-semibold">×{{ entry.count }}</span>
          </span>
        </div>
      </div>

      <p
        v-if="!status && !loadingStatus"
        class="text-sm txt-secondary mt-2"
        data-testid="text-status-empty"
      >
        {{ $t('config.routing.statusEmpty') }}
      </p>
    </div>

    <!-- Topic cards -->
    <div
      v-if="isAdmin && status && status.topics.length > 0"
      class="surface-card p-6"
      data-testid="section-routing-topics"
    >
      <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <Icon icon="heroicons:rectangle-group" class="w-5 h-5 text-[var(--brand)]" />
          {{ $t('config.routing.topicsTitle') }}
        </h3>
        <div class="relative w-full sm:w-72">
          <Icon
            icon="heroicons:magnifying-glass"
            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary"
          />
          <input
            v-model="topicSearch"
            type="text"
            class="w-full pl-9 pr-3 py-2 rounded-lg surface-chip border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('config.routing.topicSearchPlaceholder')"
            data-testid="input-topic-search"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <router-link
          v-for="topic in filteredTopics"
          :key="`${topic.ownerId}-${topic.topic}`"
          :to="`/config/task-prompts?topic=${encodeURIComponent(topic.topic)}`"
          class="surface-chip rounded-lg p-3 flex items-start justify-between gap-3 hover:bg-[var(--brand)]/5 transition-colors"
          data-testid="card-topic"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap mb-1">
              <span class="font-mono text-sm font-semibold txt-primary truncate">
                {{ topic.topic }}
              </span>
              <span
                v-if="!topic.enabled"
                class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-gray-500/10 text-gray-500 dark:text-gray-400"
                data-testid="badge-disabled"
              >
                {{ $t('config.routing.badgeDisabled') }}
              </span>
              <span
                v-if="topic.stale"
                class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-amber-500/10 text-amber-600 dark:text-amber-400"
                data-testid="badge-stale"
              >
                {{ $t('config.routing.badgeStale') }}
              </span>
              <span
                v-if="!topic.indexed && topic.enabled"
                class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-rose-500/10 text-rose-600 dark:text-rose-400"
                data-testid="badge-not-indexed"
              >
                {{ $t('config.routing.badgeNotIndexed') }}
              </span>
              <span
                v-if="topic.indexed && !topic.stale"
                class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                data-testid="badge-indexed"
              >
                {{ $t('config.routing.badgeIndexed') }}
              </span>
            </div>
            <p class="text-xs txt-secondary truncate">
              <template v-if="topic.indexed">
                {{ topic.embeddingModel || '—' }}
                · {{ topic.vectorDim ?? '?' }}d
                <span v-if="topic.indexedAt" class="opacity-75">
                  · {{ formatIndexedAt(topic.indexedAt) }}
                </span>
              </template>
              <template v-else>
                {{ $t('config.routing.notIndexedHint') }}
              </template>
            </p>
          </div>
          <Icon icon="heroicons:chevron-right" class="w-4 h-4 txt-secondary flex-shrink-0" />
        </router-link>
      </div>

      <p
        v-if="filteredTopics.length === 0"
        class="text-sm txt-secondary mt-2"
        data-testid="text-topics-empty"
      >
        {{ $t('config.routing.topicsEmpty') }}
      </p>
    </div>

    <!-- Test box -->
    <div class="surface-card p-6" data-testid="section-routing-test">
      <h3 class="text-lg font-semibold txt-primary mb-2 flex items-center gap-2">
        <Icon icon="heroicons:beaker" class="w-5 h-5 text-[var(--brand)]" />
        {{ $t('config.routing.testTitle') }}
      </h3>
      <p class="text-xs txt-secondary mb-4">
        {{ $t('config.routing.testSubtitle') }}
      </p>

      <div class="flex flex-col sm:flex-row gap-2 mb-3">
        <input
          v-model="testInput"
          type="text"
          class="flex-1 px-4 py-2.5 rounded-lg surface-chip border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('config.routing.testPlaceholder')"
          data-testid="input-test-text"
          @keydown.enter="runTest"
        />
        <button
          class="px-5 py-2.5 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 text-sm font-medium flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="btn-test-run"
          :disabled="testRunning || !testInput.trim()"
          @click="runTest"
        >
          <Icon
            :icon="testRunning ? 'heroicons:arrow-path' : 'heroicons:play'"
            :class="['w-4 h-4', testRunning && 'animate-spin']"
          />
          {{ $t('config.routing.testRun') }}
        </button>
      </div>

      <!-- Result -->
      <div
        v-if="testResult"
        class="border border-light-border/30 dark:border-dark-border/20 rounded-lg overflow-hidden"
        data-testid="section-test-result"
      >
        <div
          class="surface-chip px-4 py-3 border-b border-light-border/30 dark:border-dark-border/20"
        >
          <div class="flex items-center justify-between gap-3">
            <p class="text-xs txt-secondary">
              {{ $t('config.routing.testQuery') }}:
              <span class="font-mono txt-primary">"{{ testResult.query }}"</span>
            </p>
            <p class="text-xs txt-secondary">
              {{ $t('config.routing.testLatency', { ms: testResult.latency_ms }) }}
            </p>
          </div>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('config.routing.testModel') }}:
            <span class="font-mono txt-primary">
              {{ testResult.model.model || '—' }}
            </span>
            <span class="opacity-75">({{ testResult.model.provider || '—' }})</span>
          </p>
        </div>

        <div
          v-if="testResult.error"
          class="p-4 bg-rose-500/5 text-rose-600 dark:text-rose-400 text-sm"
        >
          {{ testResult.error }}
        </div>

        <div v-else-if="testResult.candidates.length === 0" class="p-4 text-sm txt-secondary">
          {{ $t('config.routing.testNoCandidates') }}
        </div>

        <ul v-else class="divide-y divide-light-border/30 dark:divide-dark-border/20">
          <li
            v-for="(c, idx) in testResult.candidates"
            :key="`cand-${idx}`"
            class="px-4 py-3 flex items-center justify-between gap-3"
            data-testid="item-test-candidate"
          >
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs txt-secondary">#{{ idx + 1 }}</span>
                <span class="font-mono text-sm font-semibold txt-primary">
                  {{ c.topic }}
                </span>
                <span
                  v-if="c.alias_target && c.alias_target !== c.topic"
                  class="text-xs txt-secondary inline-flex items-center gap-1"
                  data-testid="text-alias-target"
                >
                  <Icon icon="heroicons:arrow-right" class="w-3 h-3" />
                  <span class="font-mono">{{ c.alias_target }}</span>
                </span>
                <span
                  v-if="c.stale"
                  class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-amber-500/10 text-amber-600 dark:text-amber-400"
                >
                  {{ $t('config.routing.badgeStale') }}
                </span>
              </div>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-sm font-mono font-semibold" :class="scoreColor(c.score)">
                {{ c.score.toFixed(3) }}
              </p>
              <p class="text-[10px] txt-secondary uppercase tracking-wide">
                {{ $t('config.routing.score') }}
              </p>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <!-- AI Fallback Prompt (collapsed accordion) -->
    <div class="surface-card overflow-hidden" data-testid="section-routing-fallback">
      <button
        type="button"
        class="w-full px-6 py-4 flex items-center justify-between hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
        data-testid="btn-fallback-toggle"
        @click="fallbackOpen = !fallbackOpen"
      >
        <div class="flex items-start gap-3 text-left">
          <Icon icon="heroicons:cpu-chip" class="w-5 h-5 text-[var(--brand)] mt-0.5" />
          <div>
            <h3 class="text-lg font-semibold txt-primary">
              {{ $t('config.routing.fallbackTitle') }}
            </h3>
            <p class="text-xs txt-secondary mt-0.5">
              {{ $t('config.routing.fallbackSubtitle') }}
            </p>
          </div>
        </div>
        <Icon
          :icon="fallbackOpen ? 'heroicons:chevron-up' : 'heroicons:chevron-down'"
          class="w-5 h-5 txt-secondary flex-shrink-0"
        />
      </button>

      <div v-if="fallbackOpen" class="border-t border-light-border/30 dark:border-dark-border/20">
        <div class="flex border-b border-light-border/30 dark:border-dark-border/20">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            :class="[
              'px-6 py-3 text-sm font-medium transition-colors relative',
              activeTab === tab.id
                ? 'txt-primary bg-[var(--brand)]/5 border-b-2 border-[var(--brand)]'
                : 'txt-secondary hover:bg-black/5 dark:hover:bg-white/5',
            ]"
            data-testid="btn-tab"
            @click="activeTab = tab.id"
          >
            {{ tab.label }}
          </button>
        </div>

        <div class="p-6">
          <div v-if="activeTab === 'rendered'" data-testid="section-rendered">
            <div class="space-y-6">
              <div>
                <h3 class="text-xl font-semibold txt-primary mb-3">
                  {{ introTitle }}
                </h3>
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="markdown-content txt-secondary text-sm" v-html="introHtml"></div>
              </div>

              <div>
                <h4 class="text-lg font-semibold txt-primary mb-3">
                  {{ $t('config.sortingPrompt.yourTasks') }}
                </h4>
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="markdown-content txt-secondary text-sm" v-html="tasksHtml"></div>
              </div>

              <div>
                <h4 class="text-lg font-semibold txt-primary mb-3">
                  Your tasks in every new message are to:
                </h4>
                <ol class="space-y-4 txt-secondary text-sm">
                  <li
                    v-for="(instructionHtml, index) in instructionHtmls"
                    :key="`instruction-${index}`"
                    class="flex gap-3"
                  >
                    <span class="font-semibold txt-primary">{{ index + 1 }}.</span>
                    <div>
                      <!-- eslint-disable-next-line vue/no-v-html -->
                      <div class="markdown-content" v-html="instructionHtml"></div>
                      <ul v-if="index === 1" class="space-y-3 ml-4 mt-3">
                        <li
                          v-for="category in sortingPrompt.categories"
                          :key="category.name"
                          class="pl-4 border-l-2 border-[var(--brand)]/30"
                        >
                          <div class="flex items-center gap-2 mb-1">
                            <router-link
                              :to="getPromptLink(category.name)"
                              class="font-semibold txt-primary hover:underline"
                            >
                              {{ category.name }}
                            </router-link>
                            <span v-if="category.type === 'default'" class="text-xs txt-secondary"
                              >(default)</span
                            >
                            <span v-else class="text-xs text-purple-500">(custom)</span>
                          </div>
                          <p class="text-sm txt-secondary">{{ category.description }}</p>
                        </li>
                      </ul>
                    </div>
                  </li>
                </ol>
              </div>
            </div>
          </div>

          <div v-else-if="activeTab === 'source'" data-testid="section-source">
            <div class="space-y-4">
              <div class="flex justify-between items-center">
                <h3 class="text-lg font-semibold txt-primary">
                  {{ $t('config.sortingPrompt.tabSource') }}
                </h3>
                <button
                  class="px-4 py-2 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                  data-testid="btn-toggle-mode"
                  :disabled="!canEdit || loading"
                  @click="toggleEditMode"
                >
                  <PencilIcon v-if="!editMode" class="w-4 h-4 inline mr-1" />
                  <EyeIcon v-else class="w-4 h-4 inline mr-1" />
                  {{ editMode ? 'View Mode' : 'Edit Mode' }}
                </button>
              </div>

              <div
                v-if="!editMode"
                class="surface-chip p-6 rounded border border-light-border/30 dark:border-dark-border/20"
                data-testid="section-prompt-preview"
              >
                <pre class="whitespace-pre-wrap font-mono text-xs txt-primary leading-relaxed">{{
                  sortingPrompt.promptContent
                }}</pre>
              </div>

              <textarea
                v-else
                v-model="sortingPrompt.promptContent"
                rows="25"
                class="w-full px-4 py-3 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none font-mono"
                data-testid="input-prompt"
              />

              <div v-if="editMode" class="flex gap-3">
                <button
                  class="btn-primary px-6 py-2.5 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                  data-testid="btn-save"
                  :disabled="saving || loading"
                  @click="savePrompt"
                >
                  <CheckIcon class="w-5 h-5" />
                  {{ $t('config.sortingPrompt.savePrompt') }}
                </button>
                <button
                  class="px-6 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  data-testid="btn-reset"
                  :disabled="saving || loading"
                  @click="resetPrompt"
                >
                  {{ $t('config.sortingPrompt.resetPrompt') }}
                </button>
              </div>
            </div>
          </div>

          <div v-else-if="activeTab === 'json'" data-testid="section-json">
            <div class="space-y-4">
              <div>
                <h3 class="text-lg font-semibold txt-primary mb-2">
                  {{ $t('config.sortingPrompt.tabJson') }}
                </h3>
                <p class="txt-secondary text-sm mb-3">
                  {{ $t('config.sortingPrompt.jsonDescription') }}
                </p>
                <p class="txt-secondary text-sm mb-3">
                  {{ $t('config.sortingPrompt.jsonNote') }}
                </p>
                <p class="txt-secondary text-sm font-medium mb-4">
                  {{ $t('config.sortingPrompt.jsonExample') }}
                </p>
              </div>

              <div
                class="bg-black/90 dark:bg-black/50 rounded-lg p-4 font-mono text-sm text-green-400 overflow-x-auto"
              >
                <pre>{{ sortingPrompt.jsonExample }}</pre>
              </div>

              <div class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-lg">
                <p class="text-sm txt-primary">
                  <InformationCircleIcon class="w-5 h-5 text-cyan-500 inline mr-2" />
                  {{ $t('config.sortingPrompt.btopicNote') }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { PencilIcon, EyeIcon, CheckIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'
import { mockSortingPrompt } from '@/mocks/sortingPrompt'
import type { SortingPromptData } from '@/mocks/sortingPrompt'
import { promptsApi } from '@/services/api/promptsApi'
import type { SortingPromptPayload, RoutingTestResult } from '@/services/api/promptsApi'
import { adminSynapseApi } from '@/services/api/adminSynapseApi'
import type { SynapseStatusResponse } from '@/services/api/adminSynapseApi'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { useAuthStore } from '@/stores/auth'
import { getMarkdownRenderer } from '@/composables/useMarkdown'
import { useDateFormat } from '@/composables/useDateFormat'

// --- Routing-status state ---------------------------------------------------
const status = ref<SynapseStatusResponse | null>(null)
const loadingStatus = ref(false)
const reindexing = ref(false)
const topicSearch = ref('')

// --- Test-box state ---------------------------------------------------------
const testInput = ref('')
const testRunning = ref(false)
const testResult = ref<RoutingTestResult | null>(null)

// --- AI-fallback prompt state (legacy) -------------------------------------
const fallbackOpen = ref(false)
const activeTab = ref('rendered')
const editMode = ref(false)
const sortingPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const originalPrompt = ref<SortingPromptData>({ ...mockSortingPrompt })
const loading = ref(false)
const saving = ref(false)

const authStore = useAuthStore()
const isAdmin = computed(() => authStore.isAdmin)
const canEdit = computed(() => authStore.isAdmin)
const { success, error: showError, warning } = useNotification()
const dialog = useDialog()
const { t, locale } = useI18n()
const { formatRelativeTime } = useDateFormat()
const markdownRenderer = getMarkdownRenderer()

const getPromptLink = (topic: string) => `/config/task-prompts?topic=${encodeURIComponent(topic)}`
const formatIndexedAt = (iso: string): string => {
  try {
    return formatRelativeTime(new Date(iso))
  } catch {
    return iso
  }
}

// --- Computed ---------------------------------------------------------------
const disabledTopicsCount = computed(() => {
  if (!status.value) return 0
  return status.value.topics.filter((t) => !t.enabled).length
})

const filteredTopics = computed(() => {
  if (!status.value) return []
  const search = topicSearch.value.trim().toLowerCase()
  if (!search) return status.value.topics
  return status.value.topics.filter((topic) =>
    [topic.topic, topic.embeddingModel ?? '', topic.embeddingProvider ?? '']
      .join(' ')
      .toLowerCase()
      .includes(search)
  )
})

const scoreColor = (score: number): string => {
  if (score >= 0.7) return 'text-emerald-600 dark:text-emerald-400'
  if (score >= 0.55) return 'text-amber-600 dark:text-amber-400'
  return 'text-rose-600 dark:text-rose-400'
}

// --- AI-fallback markdown rendering (kept verbatim from previous component) -
const renderedPromptText = computed(
  () => sortingPrompt.value.renderedPrompt || sortingPrompt.value.promptContent || ''
)

const extractIntroSection = (
  promptText: string
): {
  title: string
  body: string
} => {
  const match = promptText.match(/#+\s*Your tasks/i)
  const intro =
    !match || match.index === undefined
      ? promptText.trim()
      : promptText.slice(0, match.index).trim()
  const lines = intro.split('\n')
  const normalized = lines.map((line) => line.trim()).filter((line) => line.length > 0)

  let title = ''
  const cleaned: string[] = []

  for (const line of normalized) {
    const headingMatch = line.match(/^#{1,6}\s+(.*)$/)
    if (!title && headingMatch?.[1]) {
      title = headingMatch[1].trim()
      continue
    }

    const lower = line.toLowerCase()
    const last = cleaned[cleaned.length - 1]?.toLowerCase()
    if (lower === 'set btopic and tools in json' && last === lower) {
      continue
    }
    if (lower === 'set btopic and tools in json' && cleaned.length === 0) {
      title = title || line
      continue
    }
    cleaned.push(line)
  }

  return {
    title: title || 'Preprocessor',
    body: cleaned.join('\n').trim(),
  }
}

const extractTasksBody = (promptText: string): string => {
  const startMatch = promptText.match(/#+\s*Your tasks/i)
  if (!startMatch || startMatch.index === undefined) {
    return ''
  }
  const startIndex = startMatch.index + startMatch[0].length
  const rest = promptText.slice(startIndex)
  const endMatch = rest.match(/Your tasks in every new message are to:/i)
  const endIndex = endMatch && endMatch.index !== undefined ? endMatch.index : rest.length
  return rest.slice(0, endIndex).trim()
}

const introSection = computed(() => extractIntroSection(renderedPromptText.value))
const introTitle = computed(() => introSection.value.title)
const introHtml = computed(() => markdownRenderer.render(introSection.value.body))
const tasksHtml = computed(() =>
  markdownRenderer.render(extractTasksBody(renderedPromptText.value))
)
const instructionHtmls = computed(() =>
  sortingPrompt.value.instructions.map((instruction) => markdownRenderer.render(instruction))
)

const tabs = [
  { id: 'rendered', label: 'Rendered Result' },
  { id: 'source', label: 'Prompt Source' },
  { id: 'json', label: 'JSON Object' },
]

const extractTasks = (promptText: string): string => {
  const section = promptText.split('# Your tasks')[1]
  if (!section) {
    return ''
  }

  const lines = section
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
  return lines[0] ?? ''
}

const extractInstructions = (promptText: string): string[] => {
  const match = promptText.match(
    /Your tasks in every new message are to:\s*([\s\S]*?)\n# Answer format/i
  )
  if (!match) {
    return []
  }

  const section = match[1].trim()
  const sanitizeInstruction = (instruction: string): string => {
    const lines = instruction
      .split('\n')
      .filter((line) => !line.trim().startsWith('- "'))
      .map((line) => line.trimEnd())
    return lines.join('\n').trim()
  }

  return section
    .split(/\n(?=\d+\.\s)/)
    .map((item) => item.replace(/^\d+\.\s*/, '').trim())
    .map((item) => sanitizeInstruction(item))
    .filter((item) => item.length > 0)
}

const mapSortingPrompt = (payload: SortingPromptPayload): SortingPromptData => {
  const tasks = extractTasks(payload.renderedPrompt)
  const instructions = extractInstructions(payload.renderedPrompt)

  return {
    id: payload.id,
    description: payload.shortDescription || mockSortingPrompt.description,
    tasks: tasks || mockSortingPrompt.tasks,
    categories: payload.categories,
    instructions: instructions.length ? instructions : mockSortingPrompt.instructions,
    promptContent: payload.prompt,
    renderedPrompt: payload.renderedPrompt,
    jsonExample: mockSortingPrompt.jsonExample,
  }
}

const loadSortingPrompt = async () => {
  loading.value = true
  try {
    const prompt = await promptsApi.getSortingPrompt(locale.value || 'en')
    const mapped = mapSortingPrompt(prompt)
    sortingPrompt.value = { ...mapped }
    originalPrompt.value = { ...mapped }
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to load sorting prompt'
    showError(message)
    sortingPrompt.value = { ...mockSortingPrompt }
    originalPrompt.value = { ...mockSortingPrompt }
  } finally {
    loading.value = false
  }
}

const toggleEditMode = () => {
  if (!canEdit.value) {
    warning('Admin access required to edit the sorting prompt.')
    return
  }
  editMode.value = !editMode.value
}

const savePrompt = async () => {
  if (!canEdit.value) {
    warning('Admin access required to edit the sorting prompt.')
    return
  }

  saving.value = true
  try {
    await promptsApi.updateSortingPrompt(sortingPrompt.value.promptContent)
    success('Sorting prompt saved.')
    await loadSortingPrompt()
    editMode.value = false
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to save sorting prompt'
    showError(message)
  } finally {
    saving.value = false
  }
}

const resetPrompt = () => {
  sortingPrompt.value = { ...originalPrompt.value }
  editMode.value = false
}

// --- Status loading ---------------------------------------------------------
const loadStatus = async () => {
  if (!isAdmin.value) return
  loadingStatus.value = true
  try {
    status.value = await adminSynapseApi.getStatus()
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to load Synapse status'
    showError(message)
  } finally {
    loadingStatus.value = false
  }
}

const reindex = async (opts: { force?: boolean; recreate?: boolean }) => {
  if (!isAdmin.value) {
    warning('Admin access required.')
    return
  }
  reindexing.value = true
  try {
    const result = await adminSynapseApi.reindex(opts)
    success(
      t('config.routing.reindexResult', {
        indexed: result.indexed,
        skipped: result.skipped,
        errors: result.errors,
      })
    )
    await loadStatus()
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Re-index failed'
    showError(message)
  } finally {
    reindexing.value = false
  }
}

const confirmRecreate = async () => {
  const confirmed = await dialog.confirm({
    title: t('config.routing.confirmRecreateTitle'),
    message: t('config.routing.confirmRecreateBody'),
    confirmText: t('config.routing.confirmRecreateConfirm'),
    cancelText: t('config.routing.confirmRecreateCancel'),
    danger: true,
  })
  if (!confirmed) return
  await reindex({ recreate: true, force: true })
}

// --- Test box ---------------------------------------------------------------
const runTest = async () => {
  const text = testInput.value.trim()
  if (!text) return
  testRunning.value = true
  try {
    if (isAdmin.value) {
      testResult.value = await adminSynapseApi.dryRun(text, 5)
    } else {
      testResult.value = await promptsApi.testRouting(text, 5)
    }
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Test routing failed'
    showError(message)
  } finally {
    testRunning.value = false
  }
}

watch(locale, () => {
  loadSortingPrompt()
})

onMounted(() => {
  loadSortingPrompt()
  if (isAdmin.value) {
    loadStatus()
  }
})
</script>
