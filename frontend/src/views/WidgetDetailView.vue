<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-chat" data-testid="page-widget-detail">
      <!-- Header -->
      <div class="px-4 lg:px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20">
        <div class="max-w-5xl mx-auto">
          <button
            class="text-xs txt-secondary hover:txt-primary transition-colors mb-3 inline-flex items-center gap-1.5"
            @click="router.push({ name: 'tools-chat-widget' })"
          >
            <Icon icon="heroicons:arrow-left" class="w-3.5 h-3.5" />
            {{ $t('widgets.detail.back') }}
          </button>
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h1 class="text-2xl lg:text-3xl font-bold txt-primary truncate">
              {{ widget?.name || '...' }}
            </h1>
            <div class="flex gap-2">
              <button
                class="btn-primary px-5 py-2.5 rounded-xl text-sm font-medium inline-flex items-center gap-2"
                @click="openAiSetup"
              >
                <Icon icon="heroicons:sparkles" class="w-4 h-4" />
                {{ $t('widgets.detail.aiButton') }}
              </button>
              <button
                class="px-4 py-2.5 rounded-xl border border-light-border/30 dark:border-dark-border/20 txt-secondary text-sm hover:txt-primary transition-colors"
                @click="openAdvancedModal"
              >
                <Icon icon="heroicons:cog-6-tooth" class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto px-4 lg:px-6 py-6 scroll-thin">
        <div class="max-w-5xl mx-auto space-y-8">
          <div v-if="loading" class="py-20 text-center">
            <div
              class="animate-spin w-10 h-10 border-4 border-[var(--brand)] border-t-transparent rounded-full mx-auto mb-4"
            />
            <p class="txt-secondary">{{ $t('common.loading') }}</p>
          </div>

          <div v-else-if="!widget" class="py-20 text-center">
            <Icon
              icon="heroicons:exclamation-triangle"
              class="w-12 h-12 txt-secondary mx-auto mb-3 opacity-40"
            />
            <p class="txt-secondary">{{ $t('widgets.detail.notFound') }}</p>
          </div>

          <template v-else>
            <!-- Flow Builder -->
            <section>
              <h2 class="text-xl font-bold txt-primary mb-1">
                {{ $t('widgets.detail.flowTitle') }}
              </h2>
              <p class="text-sm txt-secondary mb-4">
                {{ $t('widgets.detail.flowSubtitle') }}
              </p>

              <!-- Contextual hint -->
              <div
                :class="[
                  'rounded-xl px-4 py-2.5 text-sm flex items-center gap-2 transition-all duration-300 mb-5',
                  selectedTriggerId
                    ? 'bg-[var(--brand)]/10 border border-[var(--brand)]/25 text-[var(--brand)]'
                    : 'bg-gray-100 dark:bg-white/5 txt-secondary',
                ]"
              >
                <Icon
                  :icon="
                    selectedTriggerId ? 'heroicons:arrow-long-right' : 'heroicons:cursor-arrow-rays'
                  "
                  class="w-4 h-4 flex-shrink-0"
                />
                {{
                  selectedTriggerId
                    ? $t('widgets.detail.flowHintConnect')
                    : $t('widgets.detail.flowHintStart')
                }}
              </div>

              <!-- Flow canvas -->
              <div ref="flowRef" class="relative">
                <!-- SVG connections -->
                <svg
                  v-if="svgLines.length"
                  :width="svgWidth"
                  :height="svgHeight"
                  class="absolute top-0 left-0 pointer-events-none z-10"
                >
                  <defs>
                    <linearGradient id="flowGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                      <stop offset="0%" stop-color="var(--brand)" stop-opacity="0.9" />
                      <stop offset="100%" stop-color="var(--brand)" stop-opacity="0.35" />
                    </linearGradient>
                  </defs>
                  <g v-for="line in svgLines" :key="line.id">
                    <path
                      :d="line.path"
                      fill="none"
                      stroke="var(--brand)"
                      stroke-width="8"
                      opacity="0.06"
                      stroke-linecap="round"
                    />
                    <path
                      :d="line.path"
                      fill="none"
                      stroke="url(#flowGrad)"
                      stroke-width="2.5"
                      stroke-linecap="round"
                    />
                  </g>
                </svg>

                <!-- Animated neural dots -->
                <div
                  v-for="(line, lineIdx) in svgLines"
                  :key="'dot-' + line.id"
                  class="absolute w-1.5 h-1.5 rounded-full pointer-events-none z-10"
                  :style="{
                    background: 'var(--brand)',
                    boxShadow: '0 0 6px var(--brand)',
                    offsetPath: `path('${line.path}')`,
                    animation: `flowDot ${2 + lineIdx * 0.25}s linear infinite`,
                  }"
                />

                <!-- Two-column layout -->
                <div class="flex gap-8 sm:gap-14 lg:gap-24">
                  <!-- LEFT: Triggers -->
                  <div class="flex-1 space-y-3">
                    <p class="text-[11px] font-bold uppercase tracking-widest txt-secondary mb-1">
                      {{ $t('widgets.detail.triggersLabel') }}
                    </p>

                    <template v-for="trigger in triggers" :key="trigger.id">
                      <!-- Trigger card -->
                      <div
                        :ref="(el) => setRef('trigger', trigger.id, el)"
                        :class="[
                          'group relative p-4 rounded-xl border-2 cursor-pointer transition-all duration-200',
                          selectedTriggerId === trigger.id
                            ? 'border-[var(--brand)] bg-[var(--brand)]/5 shadow-lg shadow-[var(--brand)]/10 scale-[1.02]'
                            : hasConnectionFrom(trigger.id)
                              ? 'border-[var(--brand)]/30 bg-[var(--brand)]/[0.02] hover:border-[var(--brand)]/50'
                              : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40',
                        ]"
                        @click="selectTrigger(trigger.id)"
                      >
                        <div class="flex items-center justify-between gap-2">
                          <div class="flex-1 min-w-0">
                            <input
                              v-if="editingNodeId === trigger.id"
                              ref="editInputRef"
                              v-model="editingLabel"
                              class="w-full text-sm font-medium txt-primary bg-transparent border-b-2 border-[var(--brand)] focus:outline-none py-0.5"
                              @click.stop
                              @blur="finishEditing(trigger.id, 'trigger')"
                              @keydown.enter.prevent="finishEditing(trigger.id, 'trigger')"
                              @keydown.escape="cancelEditing"
                            />
                            <span v-else class="text-sm font-medium txt-primary truncate block">
                              {{ trigger.label }}
                            </span>
                            <!-- Source badges -->
                            <div v-if="trigger.sources?.length" class="flex gap-1 mt-1.5">
                              <span
                                v-for="s in trigger.sources"
                                :key="s.id"
                                :class="[
                                  'w-5 h-5 rounded-md flex items-center justify-center',
                                  s.type === 'website' ? 'bg-blue-500/10' : 'bg-amber-500/10',
                                ]"
                              >
                                <Icon
                                  :icon="sourceIcon(s.type)"
                                  class="w-3 h-3"
                                  :class="s.type === 'website' ? 'text-blue-500' : 'text-amber-500'"
                                />
                              </span>
                            </div>
                          </div>
                          <div class="flex items-center gap-0.5 flex-shrink-0">
                            <button
                              class="p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:txt-primary"
                              @click.stop="startEditing(trigger.id, trigger.label)"
                            >
                              <Icon icon="heroicons:pencil-square" class="w-3.5 h-3.5" />
                            </button>
                            <button
                              class="p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:text-red-500"
                              @click.stop="removeTrigger(trigger.id)"
                            >
                              <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </div>
                        <!-- Connection dot (right edge) -->
                        <span
                          :class="[
                            'absolute right-0 top-1/2 translate-x-1/2 -translate-y-1/2 w-3 h-3 rounded-full border-2 z-20 transition-all',
                            selectedTriggerId === trigger.id
                              ? 'border-[var(--brand)] bg-[var(--brand)] scale-125'
                              : hasConnectionFrom(trigger.id)
                                ? 'border-[var(--brand)] bg-[var(--brand)]/50'
                                : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800',
                          ]"
                        />
                      </div>

                      <!-- Source editor (appears below selected trigger) -->
                      <Transition
                        enter-active-class="transition-all duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-y-1"
                        enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition-all duration-200 ease-in"
                        leave-from-class="opacity-100 translate-y-0"
                        leave-to-class="opacity-0 -translate-y-1"
                      >
                        <div
                          v-if="selectedTriggerId === trigger.id"
                          class="rounded-xl border border-[var(--brand)]/20 bg-white/60 dark:bg-black/20 p-4 space-y-3"
                          @click.stop
                        >
                          <p class="text-[10px] font-bold uppercase tracking-widest txt-secondary">
                            {{ $t('widgets.detail.sources.title') }}
                          </p>

                          <!-- Existing sources -->
                          <div
                            v-for="source in trigger.sources || []"
                            :key="source.id"
                            class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-white/5 border border-light-border/15 dark:border-dark-border/10"
                          >
                            <div
                              :class="[
                                'w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0',
                                source.type === 'website' ? 'bg-blue-500/10' : 'bg-amber-500/10',
                              ]"
                            >
                              <Icon
                                :icon="sourceIcon(source.type)"
                                class="w-4 h-4"
                                :class="
                                  source.type === 'website' ? 'text-blue-500' : 'text-amber-500'
                                "
                              />
                            </div>
                            <div class="flex-1 min-w-0">
                              <p class="text-sm font-medium txt-primary truncate">
                                {{ source.url }}
                              </p>
                            </div>
                            <select
                              :value="source.crawlInterval"
                              class="text-xs px-2 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-1 focus:ring-[var(--brand)]"
                              @change="
                                updateSourceInterval(
                                  trigger.id,
                                  source.id,
                                  ($event.target as HTMLSelectElement).value
                                )
                              "
                            >
                              <option value="hourly">
                                {{ $t('widgets.detail.sources.hourly') }}
                              </option>
                              <option value="daily">
                                {{ $t('widgets.detail.sources.daily') }}
                              </option>
                              <option value="weekly">
                                {{ $t('widgets.detail.sources.weekly') }}
                              </option>
                              <option value="monthly">
                                {{ $t('widgets.detail.sources.monthly') }}
                              </option>
                            </select>
                            <button
                              class="p-1 txt-secondary hover:text-red-500 transition-colors"
                              @click="removeSource(trigger.id, source.id)"
                            >
                              <Icon icon="heroicons:trash" class="w-4 h-4" />
                            </button>
                          </div>

                          <!-- Empty state -->
                          <p v-if="!trigger.sources?.length" class="text-xs txt-secondary py-2">
                            {{ $t('widgets.detail.sources.empty') }}
                          </p>

                          <!-- Add source form -->
                          <form
                            class="flex gap-2 items-center"
                            @submit.prevent="addSource(trigger.id)"
                          >
                            <input
                              v-model="newSourceUrl"
                              :placeholder="$t('widgets.detail.sources.addUrl')"
                              class="flex-1 min-w-0 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                            />
                            <button
                              type="submit"
                              :disabled="!newSourceUrl.trim()"
                              class="px-3 py-2 rounded-lg bg-[var(--brand)] text-white text-sm font-medium disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90 transition-opacity"
                            >
                              <Icon icon="heroicons:plus" class="w-4 h-4" />
                            </button>
                          </form>
                        </div>
                      </Transition>
                    </template>

                    <!-- Add trigger -->
                    <form class="flex gap-2" @submit.prevent="addTrigger">
                      <input
                        v-model="newTriggerText"
                        :placeholder="$t('widgets.detail.addTrigger')"
                        class="flex-1 min-w-0 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                      />
                      <button
                        type="submit"
                        :disabled="!newTriggerText.trim()"
                        class="px-3 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary hover:border-[var(--brand)]/40 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                      >
                        <Icon icon="heroicons:plus" class="w-4 h-4" />
                      </button>
                    </form>
                  </div>

                  <!-- RIGHT: Responses -->
                  <div class="flex-1 space-y-3">
                    <p class="text-[11px] font-bold uppercase tracking-widest txt-secondary mb-1">
                      {{ $t('widgets.detail.responsesLabel') }}
                    </p>

                    <div
                      v-for="response in responses"
                      :key="response.id"
                      :ref="(el) => setRef('response', response.id, el)"
                      :class="[
                        'group relative p-4 pl-5 rounded-xl border-2 transition-all duration-200',
                        selectedTriggerId
                          ? isConnected(selectedTriggerId, response.id)
                            ? 'border-[var(--brand)] bg-[var(--brand)]/5 shadow-lg shadow-[var(--brand)]/10 cursor-pointer'
                            : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40 cursor-pointer hover:scale-[1.01]'
                          : hasConnectionTo(response.id)
                            ? 'border-[var(--brand)]/30 bg-[var(--brand)]/[0.02]'
                            : 'border-light-border/30 dark:border-dark-border/20',
                      ]"
                      @click="handleResponseClick(response.id)"
                    >
                      <!-- Connection dot (left edge) -->
                      <span
                        :class="[
                          'absolute left-0 top-1/2 -translate-x-1/2 -translate-y-1/2 w-3 h-3 rounded-full border-2 z-20 transition-all',
                          selectedTriggerId && isConnected(selectedTriggerId, response.id)
                            ? 'border-[var(--brand)] bg-[var(--brand)] scale-125'
                            : selectedTriggerId
                              ? 'border-[var(--brand)]/40 bg-white dark:bg-gray-800 animate-pulse'
                              : hasConnectionTo(response.id)
                                ? 'border-[var(--brand)] bg-[var(--brand)]/50'
                                : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800',
                        ]"
                      />
                      <div class="flex items-center justify-between gap-2">
                        <div class="flex-1 min-w-0">
                          <input
                            v-if="editingNodeId === response.id"
                            ref="editInputRef"
                            v-model="editingLabel"
                            class="w-full text-sm font-medium txt-primary bg-transparent border-b-2 border-[var(--brand)] focus:outline-none py-0.5"
                            @click.stop
                            @blur="finishEditing(response.id, 'response')"
                            @keydown.enter.prevent="finishEditing(response.id, 'response')"
                            @keydown.escape="cancelEditing"
                          />
                          <span v-else class="text-sm font-medium txt-primary truncate block">
                            {{ response.label }}
                          </span>
                        </div>
                        <div class="flex items-center gap-0.5 flex-shrink-0">
                          <button
                            class="p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:txt-primary"
                            @click.stop="startEditing(response.id, response.label)"
                          >
                            <Icon icon="heroicons:pencil-square" class="w-3.5 h-3.5" />
                          </button>
                          <button
                            class="p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:text-red-500"
                            @click.stop="removeResponse(response.id)"
                          >
                            <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </div>
                    </div>

                    <!-- Add response -->
                    <form class="flex gap-2" @submit.prevent="addResponse">
                      <input
                        v-model="newResponseText"
                        :placeholder="$t('widgets.detail.addResponse')"
                        class="flex-1 min-w-0 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                      />
                      <button
                        type="submit"
                        :disabled="!newResponseText.trim()"
                        class="px-3 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary hover:border-[var(--brand)]/40 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                      >
                        <Icon icon="heroicons:plus" class="w-4 h-4" />
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            </section>

            <!-- Connected Files -->
            <section v-if="promptFiles.length > 0">
              <h2 class="text-lg font-bold txt-primary mb-3 flex items-center gap-2">
                <Icon icon="heroicons:document-text" class="w-5 h-5 txt-brand" />
                {{ $t('widgets.detail.filesTitle') }}
              </h2>
              <div class="flex flex-wrap gap-2">
                <span
                  v-for="file in promptFiles"
                  :key="file.id"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-[var(--brand)]/10 txt-primary border border-[var(--brand)]/20"
                >
                  <Icon icon="heroicons:document" class="w-3.5 h-3.5" />
                  {{ file.fileName }}
                </span>
              </div>
            </section>

            <!-- Expert: Prompt -->
            <details class="group">
              <summary
                class="cursor-pointer text-sm font-medium txt-secondary hover:txt-primary transition-colors inline-flex items-center gap-2 select-none"
              >
                <Icon
                  icon="heroicons:chevron-right"
                  class="w-4 h-4 transition-transform group-open:rotate-90"
                />
                {{ $t('widgets.detail.expertPrompt') }}
              </summary>
              <div class="mt-3">
                <textarea
                  v-model="manualPromptContent"
                  rows="8"
                  class="w-full px-4 py-3 rounded-xl border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary resize-y focus:outline-none focus:ring-2 focus:ring-[var(--brand)] text-sm font-mono"
                />
              </div>
            </details>

            <!-- Save -->
            <div class="flex justify-end pt-2 pb-8">
              <button
                :disabled="saving"
                class="btn-primary px-8 py-3 rounded-xl text-sm font-medium disabled:opacity-60 inline-flex items-center gap-2"
                @click="save"
              >
                <Icon v-if="saving" icon="heroicons:arrow-path" class="w-4 h-4 animate-spin" />
                <Icon v-else icon="heroicons:check" class="w-4 h-4" />
                {{ saving ? $t('common.saving') : $t('common.save') }}
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>

    <SetupChatModal
      v-if="setupModalWidget"
      :widget="setupModalWidget"
      @close="setupModalWidget = null"
      @completed="handleSetupCompleted"
    />

    <AdvancedWidgetConfig
      v-if="advancedWidget"
      :widget="advancedWidget"
      @close="advancedWidget = null"
      @saved="handleAdvancedSaved"
      @start-ai-setup="openAiSetup"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch, type ComponentPublicInstance } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import SetupChatModal from '@/components/widgets/SetupChatModal.vue'
import AdvancedWidgetConfig from '@/components/widgets/AdvancedWidgetConfig.vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi, type PromptMetadata } from '@/services/api/promptsApi'
import { useNotification } from '@/composables/useNotification'
import {
  WIDGET_RULES_BLOCK_START,
  WIDGET_RULES_BLOCK_END,
  parsePromptAndRulesBlock,
  parseWidgetBehaviorRulesMetadata,
} from '@/utils/widgetBehaviorRules'
import { useI18n } from 'vue-i18n'

interface FlowSource {
  id: string
  type: 'website' | 'file' | 'custom'
  url: string
  crawlInterval: 'hourly' | 'daily' | 'weekly' | 'monthly'
}
interface FlowNode {
  id: string
  label: string
  sources?: FlowSource[]
}
interface FlowConnection {
  from: string
  to: string
}
interface FlowData {
  triggers: FlowNode[]
  responses: FlowNode[]
  connections: FlowConnection[]
}
interface SvgLine {
  id: string
  path: string
}

const router = useRouter()
const route = useRoute()
const { error: showError, success } = useNotification()
const { t } = useI18n()

const loading = ref(false)
const saving = ref(false)
const widget = ref<widgetsApi.Widget | null>(null)
const setupModalWidget = ref<widgetsApi.Widget | null>(null)
const advancedWidget = ref<widgetsApi.Widget | null>(null)
const promptId = ref(0)
const promptMetadata = ref<PromptMetadata>({})
const manualPromptContent = ref('')
const promptFiles = ref<Array<{ id: number; fileName: string; chunks: number }>>([])

// Flow state
const triggers = ref<FlowNode[]>([])
const responses = ref<FlowNode[]>([])
const connections = ref<FlowConnection[]>([])
const selectedTriggerId = ref<string | null>(null)
const newTriggerText = ref('')
const newResponseText = ref('')

// Editing state
const editingNodeId = ref<string | null>(null)
const editingLabel = ref('')
const editInputRef = ref<HTMLInputElement[] | null>(null)

// Source state
const newSourceUrl = ref('')

// SVG state
const flowRef = ref<HTMLElement | null>(null)
const svgWidth = ref(0)
const svgHeight = ref(0)
const svgLines = ref<SvgLine[]>([])

// DOM ref tracking
const triggerEls = new Map<string, HTMLElement>()
const responseEls = new Map<string, HTMLElement>()

const setRef = (
  type: 'trigger' | 'response',
  id: string,
  el: Element | ComponentPublicInstance | null
) => {
  const map = type === 'trigger' ? triggerEls : responseEls
  if (el instanceof HTMLElement) map.set(id, el)
  else map.delete(id)
}

// SVG line calculation
const recalcLines = () => {
  const container = flowRef.value
  if (!container) {
    svgLines.value = []
    return
  }
  const cr = container.getBoundingClientRect()
  svgWidth.value = cr.width
  svgHeight.value = cr.height
  const lines: SvgLine[] = []
  for (const conn of connections.value) {
    const tEl = triggerEls.get(conn.from)
    const rEl = responseEls.get(conn.to)
    if (!tEl || !rEl) continue
    const tr = tEl.getBoundingClientRect()
    const rr = rEl.getBoundingClientRect()
    const x1 = tr.right - cr.left
    const y1 = tr.top + tr.height / 2 - cr.top
    const x2 = rr.left - cr.left
    const y2 = rr.top + rr.height / 2 - cr.top
    const mx = (x1 + x2) / 2
    lines.push({
      id: `${conn.from}--${conn.to}`,
      path: `M ${x1},${y1} C ${mx},${y1} ${mx},${y2} ${x2},${y2}`,
    })
  }
  svgLines.value = lines
}

let resizeObs: ResizeObserver | null = null
watch(flowRef, (el) => {
  resizeObs?.disconnect()
  if (el) {
    resizeObs = new ResizeObserver(() => recalcLines())
    resizeObs.observe(el)
    nextTick(recalcLines)
  }
})
watch([triggers, responses, connections, selectedTriggerId], () => nextTick(recalcLines), {
  deep: true,
})
onMounted(() => window.addEventListener('resize', recalcLines))
onBeforeUnmount(() => {
  resizeObs?.disconnect()
  window.removeEventListener('resize', recalcLines)
})

// Helpers
const isConnected = (triggerId: string, responseId: string) =>
  connections.value.some((c) => c.from === triggerId && c.to === responseId)
const hasConnectionFrom = (triggerId: string) => connections.value.some((c) => c.from === triggerId)
const hasConnectionTo = (responseId: string) => connections.value.some((c) => c.to === responseId)

const sourceIcon = (type: FlowSource['type']): string => {
  switch (type) {
    case 'website':
      return 'heroicons:globe-alt'
    case 'file':
      return 'heroicons:document'
    default:
      return 'heroicons:light-bulb'
  }
}

// Interaction
const selectTrigger = (id: string) => {
  if (editingNodeId.value === id) return
  selectedTriggerId.value = selectedTriggerId.value === id ? null : id
  newSourceUrl.value = ''
}

const handleResponseClick = (responseId: string) => {
  if (!selectedTriggerId.value) return
  const tid = selectedTriggerId.value
  const idx = connections.value.findIndex((c) => c.from === tid && c.to === responseId)
  if (idx >= 0) connections.value.splice(idx, 1)
  else connections.value.push({ from: tid, to: responseId })
}

const addTrigger = () => {
  const label = newTriggerText.value.trim()
  if (!label) return
  triggers.value.push({ id: `t-${Date.now()}`, label })
  newTriggerText.value = ''
}
const addResponse = () => {
  const label = newResponseText.value.trim()
  if (!label) return
  responses.value.push({ id: `r-${Date.now()}`, label })
  newResponseText.value = ''
}
const removeTrigger = (id: string) => {
  triggers.value = triggers.value.filter((n) => n.id !== id)
  connections.value = connections.value.filter((c) => c.from !== id)
  if (selectedTriggerId.value === id) selectedTriggerId.value = null
}
const removeResponse = (id: string) => {
  responses.value = responses.value.filter((n) => n.id !== id)
  connections.value = connections.value.filter((c) => c.to !== id)
}

// Inline editing
const startEditing = (nodeId: string, label: string) => {
  editingNodeId.value = nodeId
  editingLabel.value = label
  nextTick(() => {
    if (editInputRef.value?.length) editInputRef.value[0].focus()
  })
}
const finishEditing = (nodeId: string, type: 'trigger' | 'response') => {
  const label = editingLabel.value.trim()
  if (label) {
    const list = type === 'trigger' ? triggers.value : responses.value
    const node = list.find((n) => n.id === nodeId)
    if (node) node.label = label
  }
  editingNodeId.value = null
}
const cancelEditing = () => {
  editingNodeId.value = null
}

// Sources
const addSource = (triggerId: string) => {
  const url = newSourceUrl.value.trim()
  if (!url) return
  const trigger = triggers.value.find((n) => n.id === triggerId)
  if (!trigger) return
  if (!trigger.sources) trigger.sources = []
  trigger.sources.push({
    id: `s-${Date.now()}`,
    type: url.startsWith('http') ? 'website' : 'custom',
    url,
    crawlInterval: 'daily',
  })
  newSourceUrl.value = ''
}
const removeSource = (triggerId: string, sourceId: string) => {
  const trigger = triggers.value.find((n) => n.id === triggerId)
  if (!trigger?.sources) return
  trigger.sources = trigger.sources.filter((s) => s.id !== sourceId)
}
const updateSourceInterval = (triggerId: string, sourceId: string, interval: string) => {
  const trigger = triggers.value.find((n) => n.id === triggerId)
  const source = trigger?.sources?.find((s) => s.id === sourceId)
  if (source) source.crawlInterval = interval as FlowSource['crawlInterval']
}

// Defaults
const defaultTriggers = (): FlowNode[] => [
  { id: 'location', label: t('widgets.detail.defaultTriggers.location') },
  { id: 'general', label: t('widgets.detail.defaultTriggers.general') },
]
const defaultResponses = (): FlowNode[] => [
  { id: 'location-link', label: t('widgets.detail.defaultResponses.locationLink') },
  { id: 'location-image', label: t('widgets.detail.defaultResponses.locationImage') },
  { id: 'concise', label: t('widgets.detail.defaultResponses.concise') },
  { id: 'cta', label: t('widgets.detail.defaultResponses.cta') },
]

const migrateFromBehaviorRules = (raw: unknown) => {
  const rules = parseWidgetBehaviorRulesMetadata(raw)
  triggers.value = defaultTriggers()
  responses.value = defaultResponses()
  const conns: FlowConnection[] = []
  if (rules.locationLinkRequired) conns.push({ from: 'location', to: 'location-link' })
  if (rules.locationImageLink) conns.push({ from: 'location', to: 'location-image' })
  if (rules.conciseReplies) {
    conns.push({ from: 'location', to: 'concise' })
    conns.push({ from: 'general', to: 'concise' })
  }
  if (rules.ctaRequired) {
    conns.push({ from: 'location', to: 'cta' })
    conns.push({ from: 'general', to: 'cta' })
  }
  connections.value = conns
}

// Build prompt from flow
const buildFlowRulesBlock = (): string => {
  const grouped = new Map<string, string[]>()
  for (const conn of connections.value) {
    const trig = triggers.value.find((n) => n.id === conn.from)
    const resp = responses.value.find((n) => n.id === conn.to)
    if (!trig || !resp) continue
    if (!grouped.has(trig.label)) grouped.set(trig.label, [])
    grouped.get(trig.label)!.push(resp.label)
  }
  if (grouped.size === 0) return ''
  const lines = [WIDGET_RULES_BLOCK_START, 'Widget behavior rules:']
  for (const [trigger, resps] of grouped) {
    lines.push(`When user asks about "${trigger}":`)
    for (const r of resps) lines.push(`- ${r}`)
  }
  lines.push(WIDGET_RULES_BLOCK_END)
  return lines.join('\n')
}

const removeKnowledgeBaseSection = (content: string): string => {
  let updated = content.replace(
    /\n?\s*<!-- KNOWLEDGE_BASE_START -->[\s\S]*?<!-- KNOWLEDGE_BASE_END -->\n?/,
    ''
  )
  updated = updated.replace(/\n\n## Knowledge Base\n[\s\S]*?(?=\n## |\n$|$)/, '')
  return updated.trim()
}

// Load
const loadData = async () => {
  const widgetId = route.params.widgetId as string
  if (!widgetId) return
  loading.value = true
  try {
    widget.value = await widgetsApi.getWidget(widgetId)
    const topic = widget.value.taskPromptTopic
    if (topic && topic !== 'tools:widget-default') {
      const prompts = await promptsApi.getPrompts()
      const prompt = prompts.find((p) => p.topic === topic)
      if (prompt) {
        promptId.value = prompt.id
        promptMetadata.value = (prompt.metadata || {}) as PromptMetadata
        const parsed = parsePromptAndRulesBlock(prompt.prompt)
        manualPromptContent.value = removeKnowledgeBaseSection(parsed.manualPrompt)

        const flowRaw = prompt.metadata?.widgetFlowRules
        if (typeof flowRaw === 'string' && flowRaw.length > 0) {
          try {
            const flow = JSON.parse(flowRaw) as FlowData
            triggers.value = flow.triggers
            responses.value = flow.responses
            connections.value = flow.connections
          } catch {
            migrateFromBehaviorRules(prompt.metadata?.widgetBehaviorRules)
          }
        } else {
          migrateFromBehaviorRules(prompt.metadata?.widgetBehaviorRules)
        }
      } else {
        triggers.value = defaultTriggers()
        responses.value = defaultResponses()
        connections.value = []
      }
      promptFiles.value = (await promptsApi.getPromptFiles(topic)).map((f) => ({
        id: f.messageId,
        fileName: f.fileName,
        chunks: f.chunks,
      }))
    } else {
      triggers.value = defaultTriggers()
      responses.value = defaultResponses()
      connections.value = []
    }
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err)
    showError(message || 'Failed to load widget')
  } finally {
    loading.value = false
  }
}

// Save
const save = async () => {
  if (!widget.value) return
  saving.value = true
  try {
    const rulesBlock = buildFlowRulesBlock()
    const base = manualPromptContent.value.trim()
    const composed = [rulesBlock, base].filter(Boolean).join('\n\n')

    const flowData: FlowData = {
      triggers: triggers.value,
      responses: responses.value,
      connections: connections.value,
    }
    const metadata: PromptMetadata = {
      ...promptMetadata.value,
      aiModel: typeof promptMetadata.value.aiModel === 'number' ? promptMetadata.value.aiModel : -1,
      widgetFlowRules: JSON.stringify(flowData),
      widgetBehaviorVersion: '2',
    }

    if (promptId.value > 0) {
      await promptsApi.updatePrompt(promptId.value, { prompt: composed, metadata })
    } else {
      const gen = await widgetsApi.generateWidgetPrompt(widget.value.widgetId, composed, [])
      promptId.value = gen.promptId
      await promptsApi.updatePrompt(gen.promptId, { prompt: composed, metadata })
      widget.value = await widgetsApi.getWidget(widget.value.widgetId)
    }
    success(t('widgets.advancedConfig.saveSuccess'))
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err)
    showError(message || t('widgets.advancedConfig.saveError'))
  } finally {
    saving.value = false
  }
}

// Modal handlers
const openAiSetup = () => {
  if (widget.value) setupModalWidget.value = widget.value
}
const openAdvancedModal = () => {
  if (widget.value) advancedWidget.value = widget.value
}
const handleSetupCompleted = async () => {
  setupModalWidget.value = null
  await loadData()
  success(t('widgets.setupComplete'))
}
const handleAdvancedSaved = async () => {
  advancedWidget.value = null
  await loadData()
}

onMounted(loadData)
</script>

<style>
@keyframes flowDot {
  0% {
    offset-distance: 0%;
  }
  100% {
    offset-distance: 100%;
  }
}
</style>
