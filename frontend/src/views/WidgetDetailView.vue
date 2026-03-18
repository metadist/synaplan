<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-chat" data-testid="page-widget-detail">
      <!-- Header -->
      <div class="px-4 lg:px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20">
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
              class="px-4 py-2.5 rounded-xl border border-light-border/30 dark:border-dark-border/20 txt-secondary text-sm hover:txt-primary transition-colors"
              @click="openAdvancedModal()"
            >
              <Icon icon="heroicons:cog-6-tooth" class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Data Processing Notice -->
      <div
        v-if="widget && !widget.config?.dataProcessingAccepted"
        class="mx-4 lg:mx-6 mt-3 p-3 rounded-xl bg-amber-500/10 border border-amber-500/30"
      >
        <div class="flex items-start gap-3">
          <Icon
            icon="heroicons:shield-exclamation"
            class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"
          />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-amber-700 dark:text-amber-300">
              {{ $t('widgets.detail.avvNoticeTitle') }}
            </p>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
              {{ $t('widgets.detail.avvNoticeDescription') }}
            </p>
          </div>
          <button
            class="px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-500/20 text-amber-700 dark:text-amber-300 hover:bg-amber-500/30 transition-colors flex-shrink-0"
            @click="openAdvancedModal('privacy')"
          >
            {{ $t('widgets.detail.avvNoticeCta') }}
          </button>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-hidden">
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

        <div v-else class="h-full relative">
          <!-- PHASE 1: Fullscreen AI Chat (initial setup) -->
          <Transition
            enter-active-class="transition-all duration-500 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition-all duration-500 ease-in-out"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
          >
            <div
              v-if="aiSetupPhase === 'fullscreen'"
              class="absolute inset-0 z-20 flex items-stretch justify-center px-4 py-6"
            >
              <WidgetAiSetupPanel
                v-if="widget"
                :widget-id="widget.widgetId"
                :fullscreen="true"
                :current-flow="currentFlowSnapshot"
                @update-flow="handleAiFlowUpdate"
                @first-flow-received="transitionToSplitView"
                @update-widget-name="handleWidgetNameUpdate"
              />
            </div>
          </Transition>

          <!-- PHASE 2: Split layout (Flow Builder left + AI Panel right) -->
          <div
            :class="[
              'h-full flex flex-col lg:flex-row transition-all duration-700 ease-out',
              aiSetupPhase === 'fullscreen' ? 'opacity-0 pointer-events-none' : 'opacity-100',
            ]"
          >
            <!-- Left: Flow Builder -->
            <div
              :class="[
                'w-full min-w-0 lg:w-[70%] lg:flex-shrink-0 overflow-y-auto px-4 lg:px-6 py-6 scroll-thin transition-all duration-700 ease-out',
                aiSetupPhase === 'entering' ? 'animate-slide-in-left' : '',
              ]"
            >
              <div class="space-y-8">
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
                        selectedTriggerId
                          ? 'heroicons:arrow-long-right'
                          : 'heroicons:cursor-arrow-rays'
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
                      <!-- LEFT: Triggers (criteria only) -->
                      <div class="flex-1 space-y-3">
                        <p
                          class="text-[11px] font-bold uppercase tracking-widest txt-secondary mb-1"
                        >
                          {{ $t('widgets.detail.triggersLabel') }}
                        </p>

                        <!-- Existing trigger cards -->
                        <template v-for="trigger in triggers" :key="trigger.id">
                          <!-- Edit mode -->
                          <FlowNodeEditor
                            v-if="editingNodeId === trigger.id"
                            :node="trigger"
                            node-type="trigger"
                            @save="handleNodeSave($event, 'trigger')"
                            @cancel="cancelEditing"
                          />
                          <!-- Display mode -->
                          <div
                            v-else
                            :ref="(el) => setRef('trigger', trigger.id, el)"
                            :class="[
                              'group relative rounded-xl border-2 cursor-pointer transition-all duration-200',
                              selectedTriggerId === trigger.id
                                ? 'border-[var(--brand)] bg-[var(--brand)]/5 shadow-lg shadow-[var(--brand)]/10 scale-[1.02]'
                                : hasConnectionFrom(trigger.id)
                                  ? 'border-[var(--brand)]/30 bg-[var(--brand)]/[0.02] hover:border-[var(--brand)]/50'
                                  : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40',
                            ]"
                            @click="selectTrigger(trigger.id)"
                          >
                            <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                              <div class="flex-1 min-w-0">
                                <span class="text-xs font-semibold txt-primary block truncate">
                                  {{ splitLabel(trigger.label).title }}
                                </span>
                                <span
                                  v-if="
                                    splitLabel(trigger.label).preview &&
                                    expandedNodeId !== trigger.id
                                  "
                                  class="text-[11px] txt-secondary truncate block mt-0.5"
                                  @click.stop="toggleExpand(trigger.id)"
                                >
                                  {{ splitLabel(trigger.label).preview.substring(0, 50)
                                  }}{{ splitLabel(trigger.label).preview.length > 50 ? '...' : '' }}
                                </span>
                              </div>
                              <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button
                                  class="p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:txt-primary"
                                  @click.stop="startEditing(trigger.id)"
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
                            <!-- Expanded content -->
                            <div
                              v-if="
                                expandedNodeId === trigger.id && splitLabel(trigger.label).preview
                              "
                              class="px-3 pb-2.5 border-t border-light-border/20 dark:border-dark-border/10"
                              @click.stop
                            >
                              <p
                                class="text-[11px] txt-secondary pt-2 whitespace-pre-wrap break-words"
                              >
                                {{ trigger.label }}
                              </p>
                            </div>
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
                        </template>

                        <!-- Trigger wizard form -->
                        <Transition
                          enter-active-class="transition-all duration-300 ease-out"
                          enter-from-class="opacity-0 -translate-y-2 scale-95"
                          enter-to-class="opacity-100 translate-y-0 scale-100"
                          leave-active-class="transition-all duration-200 ease-in"
                          leave-from-class="opacity-100 translate-y-0 scale-100"
                          leave-to-class="opacity-0 -translate-y-2 scale-95"
                        >
                          <div
                            v-if="activeWizard?.side === 'trigger'"
                            class="rounded-xl border-2 border-[var(--brand)]/30 bg-[var(--brand)]/[0.03] p-4 space-y-3"
                          >
                            <div class="relative">
                              <label
                                class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                              >
                                {{ $t('widgets.detail.wizard.label') }}
                              </label>
                              <div class="flex gap-1.5">
                                <input
                                  v-model="wizardLabel"
                                  class="flex-1 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                                />
                                <button
                                  type="button"
                                  :disabled="!wizardLabel.trim() || enhancingField === 'label'"
                                  class="px-2.5 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 transition-all disabled:opacity-30"
                                  :title="$t('widgets.detail.wizard.aiEnhance')"
                                  @click="enhanceField('label')"
                                >
                                  <Icon
                                    :icon="
                                      enhancingField === 'label'
                                        ? 'heroicons:arrow-path'
                                        : 'heroicons:sparkles'
                                    "
                                    :class="[
                                      'w-4 h-4 txt-brand',
                                      enhancingField === 'label' && 'animate-spin',
                                    ]"
                                  />
                                </button>
                              </div>
                            </div>
                            <div class="relative">
                              <label
                                class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                              >
                                {{ $t('widgets.detail.wizard.details') }}
                              </label>
                              <div class="flex gap-1.5">
                                <textarea
                                  v-model="wizardDetails"
                                  rows="2"
                                  :placeholder="
                                    $t(
                                      `widgets.detail.wizard.detailsPlaceholder.${activeWizard.key}`
                                    )
                                  "
                                  class="flex-1 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary resize-none focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                                />
                                <button
                                  type="button"
                                  :disabled="!wizardDetails.trim() || enhancingField === 'details'"
                                  class="self-start px-2.5 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 transition-all disabled:opacity-30"
                                  :title="$t('widgets.detail.wizard.aiEnhance')"
                                  @click="enhanceField('details')"
                                >
                                  <Icon
                                    :icon="
                                      enhancingField === 'details'
                                        ? 'heroicons:arrow-path'
                                        : 'heroicons:sparkles'
                                    "
                                    :class="[
                                      'w-4 h-4 txt-brand',
                                      enhancingField === 'details' && 'animate-spin',
                                    ]"
                                  />
                                </button>
                              </div>
                            </div>
                            <div class="flex justify-end gap-2">
                              <button
                                class="px-3 py-1.5 rounded-lg text-xs font-medium txt-secondary hover:txt-primary transition-colors"
                                @click="cancelWizard"
                              >
                                {{ $t('widgets.detail.wizard.cancel') }}
                              </button>
                              <button
                                :disabled="!wizardLabel.trim()"
                                class="px-4 py-1.5 rounded-lg text-xs font-medium bg-[var(--brand)] text-white hover:opacity-90 transition-opacity disabled:opacity-30"
                                @click="confirmWizard"
                              >
                                {{ $t('widgets.detail.wizard.create') }}
                              </button>
                            </div>
                          </div>
                        </Transition>

                        <!-- Template quick-add buttons (always visible) -->
                        <div :class="['space-y-1.5', triggers.length > 0 && 'pt-1']">
                          <p
                            v-if="triggers.length === 0"
                            class="text-xs txt-secondary opacity-70 mb-2"
                          >
                            {{ $t('widgets.detail.triggersEmptyHint') }}
                          </p>
                          <button
                            v-for="tpl in triggerTemplates"
                            :key="tpl.key"
                            :class="[
                              'w-full flex items-center gap-3 rounded-xl border transition-all duration-200 group/tpl text-left',
                              triggers.length > 0
                                ? 'p-2 border-transparent hover:border-light-border/20 dark:hover:border-dark-border/15 hover:bg-[var(--brand)]/5'
                                : 'p-3 border-light-border/20 dark:border-dark-border/15 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5',
                            ]"
                            @click="openWizard('trigger', tpl.key)"
                          >
                            <span
                              :class="[
                                'rounded-lg flex items-center justify-center flex-shrink-0',
                                triggers.length > 0 ? 'w-6 h-6' : 'w-8 h-8',
                                tpl.bg,
                              ]"
                            >
                              <Icon
                                :icon="tpl.icon"
                                :class="[triggers.length > 0 ? 'w-3 h-3' : 'w-4 h-4', tpl.color]"
                              />
                            </span>
                            <div class="flex-1 min-w-0">
                              <p
                                :class="[
                                  'font-medium txt-primary truncate',
                                  triggers.length > 0 ? 'text-xs' : 'text-sm',
                                ]"
                              >
                                {{ $t(`widgets.detail.triggerTemplates.${tpl.key}`) }}
                              </p>
                              <p
                                v-if="triggers.length === 0"
                                class="text-[11px] txt-secondary truncate"
                              >
                                {{ $t(`widgets.detail.triggerTemplateHints.${tpl.key}`) }}
                              </p>
                            </div>
                            <Icon
                              icon="heroicons:plus-circle"
                              class="w-4 h-4 txt-secondary opacity-0 group-hover/tpl:opacity-100 transition-opacity flex-shrink-0"
                            />
                          </button>
                        </div>

                        <!-- Manual add -->
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

                      <!-- RIGHT: Responses / Sources -->
                      <div class="flex-1 space-y-3">
                        <p
                          class="text-[11px] font-bold uppercase tracking-widest txt-secondary mb-1"
                        >
                          {{ $t('widgets.detail.responsesLabel') }}
                        </p>

                        <!-- Existing response cards -->
                        <template v-for="response in responses" :key="response.id">
                          <!-- Edit mode -->
                          <FlowNodeEditor
                            v-if="editingNodeId === response.id"
                            :node="response"
                            node-type="response"
                            @save="handleNodeSave($event, 'response')"
                            @cancel="cancelEditing"
                          />
                          <!-- Display mode -->
                          <div
                            v-else
                            :ref="(el) => setRef('response', response.id, el)"
                            :class="[
                              'group relative pl-5 rounded-xl border-2 transition-all duration-200',
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
                            <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                              <div class="flex items-center gap-2 flex-1 min-w-0">
                                <span
                                  :class="[
                                    'w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0',
                                    getResponseTypeInfo(response).bg,
                                  ]"
                                >
                                  <Icon
                                    :icon="getResponseTypeInfo(response).icon"
                                    :class="['w-3.5 h-3.5', getResponseTypeInfo(response).color]"
                                  />
                                </span>
                                <div class="flex-1 min-w-0">
                                  <span class="text-xs font-semibold txt-primary block truncate">
                                    {{ splitLabel(response.label).title }}
                                  </span>
                                  <span
                                    v-if="response.meta?.url && expandedNodeId !== response.id"
                                    class="text-[11px] text-blue-500 truncate block mt-0.5 hover:underline"
                                    @click.stop="toggleExpand(response.id)"
                                  >
                                    {{ response.meta.url }}
                                  </span>
                                  <span
                                    v-else-if="
                                      splitLabel(response.label).preview &&
                                      expandedNodeId !== response.id
                                    "
                                    class="text-[11px] txt-secondary truncate block mt-0.5"
                                    @click.stop="toggleExpand(response.id)"
                                  >
                                    {{ splitLabel(response.label).preview.substring(0, 50)
                                    }}{{
                                      splitLabel(response.label).preview.length > 50 ? '...' : ''
                                    }}
                                  </span>
                                </div>
                              </div>
                              <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button
                                  class="p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:txt-primary"
                                  @click.stop="startEditing(response.id)"
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
                            <!-- Expanded content -->
                            <div
                              v-if="expandedNodeId === response.id"
                              class="px-3 pb-2.5 border-t border-light-border/20 dark:border-dark-border/10"
                              @click.stop
                            >
                              <p
                                class="text-[11px] txt-secondary pt-2 whitespace-pre-wrap break-words"
                              >
                                {{ response.label }}
                              </p>
                              <a
                                v-if="response.meta?.url"
                                :href="response.meta.url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 text-[11px] text-blue-500 hover:underline mt-1"
                                @click.stop
                              >
                                <Icon icon="heroicons:arrow-top-right-on-square" class="w-3 h-3" />
                                {{ response.meta.url }}
                              </a>
                            </div>
                          </div>
                        </template>

                        <!-- Response wizard form -->
                        <Transition
                          enter-active-class="transition-all duration-300 ease-out"
                          enter-from-class="opacity-0 -translate-y-2 scale-95"
                          enter-to-class="opacity-100 translate-y-0 scale-100"
                          leave-active-class="transition-all duration-200 ease-in"
                          leave-from-class="opacity-100 translate-y-0 scale-100"
                          leave-to-class="opacity-0 -translate-y-2 scale-95"
                        >
                          <div
                            v-if="activeWizard?.side === 'response'"
                            class="rounded-xl border-2 border-[var(--brand)]/30 bg-[var(--brand)]/[0.03] p-4 space-y-3"
                          >
                            <div class="relative">
                              <label
                                class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                              >
                                {{ $t('widgets.detail.wizard.label') }}
                              </label>
                              <div class="flex gap-1.5">
                                <input
                                  v-model="wizardLabel"
                                  class="flex-1 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                                />
                                <button
                                  type="button"
                                  :disabled="!wizardLabel.trim() || enhancingField === 'label'"
                                  class="px-2.5 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 transition-all disabled:opacity-30"
                                  :title="$t('widgets.detail.wizard.aiEnhance')"
                                  @click="enhanceField('label')"
                                >
                                  <Icon
                                    :icon="
                                      enhancingField === 'label'
                                        ? 'heroicons:arrow-path'
                                        : 'heroicons:sparkles'
                                    "
                                    :class="[
                                      'w-4 h-4 txt-brand',
                                      enhancingField === 'label' && 'animate-spin',
                                    ]"
                                  />
                                </button>
                              </div>
                            </div>

                            <!-- Type-specific fields -->
                            <div v-if="activeWizard.key === 'link'" class="relative">
                              <label
                                class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                              >
                                {{ $t('widgets.detail.wizard.url') }}
                              </label>
                              <input
                                v-model="wizardUrl"
                                placeholder="https://..."
                                class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                              />
                            </div>

                            <template v-if="activeWizard.key === 'api'">
                              <div class="relative">
                                <label
                                  class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                                >
                                  {{ $t('widgets.detail.wizard.endpoint') }}
                                </label>
                                <input
                                  v-model="wizardUrl"
                                  placeholder="https://api.example.com/v1/..."
                                  class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                                />
                              </div>
                              <div class="relative">
                                <label
                                  class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                                >
                                  {{ $t('widgets.detail.wizard.method') }}
                                </label>
                                <select
                                  v-model="wizardMethod"
                                  class="w-full px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                                >
                                  <option value="GET">GET</option>
                                  <option value="POST">POST</option>
                                  <option value="PUT">PUT</option>
                                </select>
                              </div>
                            </template>

                            <div
                              v-if="
                                activeWizard.key === 'text' ||
                                activeWizard.key === 'list' ||
                                activeWizard.key === 'custom'
                              "
                              class="relative"
                            >
                              <label
                                class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                              >
                                {{ $t('widgets.detail.wizard.details') }}
                              </label>
                              <div class="flex gap-1.5">
                                <textarea
                                  v-model="wizardDetails"
                                  rows="3"
                                  :placeholder="
                                    $t(
                                      `widgets.detail.wizard.detailsPlaceholder.${activeWizard.key}`
                                    )
                                  "
                                  class="flex-1 px-3 py-2 rounded-lg text-sm border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary resize-none focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40"
                                />
                                <button
                                  type="button"
                                  :disabled="!wizardDetails.trim() || enhancingField === 'details'"
                                  class="self-start px-2.5 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 transition-all disabled:opacity-30"
                                  :title="$t('widgets.detail.wizard.aiEnhance')"
                                  @click="enhanceField('details')"
                                >
                                  <Icon
                                    :icon="
                                      enhancingField === 'details'
                                        ? 'heroicons:arrow-path'
                                        : 'heroicons:sparkles'
                                    "
                                    :class="[
                                      'w-4 h-4 txt-brand',
                                      enhancingField === 'details' && 'animate-spin',
                                    ]"
                                  />
                                </button>
                              </div>
                            </div>

                            <div v-if="activeWizard.key === 'pdf'" class="space-y-2">
                              <label
                                class="block text-[10px] font-bold uppercase tracking-widest txt-secondary mb-1"
                              >
                                {{ $t('widgets.detail.wizard.files') }}
                              </label>

                              <!-- Attached files list -->
                              <div
                                v-for="wf in wizardFiles"
                                :key="wf.messageId"
                                class="flex items-center gap-2 p-2 rounded-lg bg-gray-50 dark:bg-white/5 border border-light-border/15 dark:border-dark-border/10"
                              >
                                <Icon
                                  icon="heroicons:document"
                                  class="w-4 h-4 txt-brand flex-shrink-0"
                                />
                                <span class="flex-1 text-xs txt-primary truncate">{{
                                  wf.fileName
                                }}</span>
                                <button
                                  type="button"
                                  class="p-0.5 txt-secondary hover:text-red-500 transition-colors"
                                  @click="removeWizardFile(wf.messageId)"
                                >
                                  <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
                                </button>
                              </div>

                              <p
                                v-if="wizardFiles.length === 0"
                                class="text-xs txt-secondary opacity-60 py-1"
                              >
                                {{ $t('widgets.detail.wizard.noFilesYet') }}
                              </p>

                              <!-- Upload + File manager buttons -->
                              <div class="flex gap-2">
                                <label
                                  :class="[
                                    'flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium border border-light-border/30 dark:border-dark-border/20 cursor-pointer hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 transition-all',
                                    wizardUploadingFile && 'opacity-50 pointer-events-none',
                                  ]"
                                >
                                  <Icon
                                    :icon="
                                      wizardUploadingFile
                                        ? 'heroicons:arrow-path'
                                        : 'heroicons:arrow-up-tray'
                                    "
                                    :class="[
                                      'w-3.5 h-3.5 txt-brand',
                                      wizardUploadingFile && 'animate-spin',
                                    ]"
                                  />
                                  {{
                                    wizardUploadingFile
                                      ? $t('widgets.detail.wizard.uploading')
                                      : $t('widgets.detail.wizard.uploadFile')
                                  }}
                                  <input
                                    type="file"
                                    class="hidden"
                                    accept=".pdf,.doc,.docx,.txt,.md,.csv,.xlsx"
                                    @change="handleWizardFileUpload"
                                  />
                                </label>
                                <button
                                  type="button"
                                  class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium border border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 transition-all"
                                  @click="showWizardFilePicker = true"
                                >
                                  <Icon
                                    icon="heroicons:folder-open"
                                    class="w-3.5 h-3.5 txt-brand"
                                  />
                                  {{ $t('widgets.detail.wizard.fromFileManager') }}
                                </button>
                              </div>
                            </div>

                            <div class="flex justify-end gap-2">
                              <button
                                class="px-3 py-1.5 rounded-lg text-xs font-medium txt-secondary hover:txt-primary transition-colors"
                                @click="cancelWizard"
                              >
                                {{ $t('widgets.detail.wizard.cancel') }}
                              </button>
                              <button
                                :disabled="!wizardLabel.trim()"
                                class="px-4 py-1.5 rounded-lg text-xs font-medium bg-[var(--brand)] text-white hover:opacity-90 transition-opacity disabled:opacity-30"
                                @click="confirmWizard"
                              >
                                {{ $t('widgets.detail.wizard.create') }}
                              </button>
                            </div>
                          </div>
                        </Transition>

                        <!-- Source type templates (always visible) -->
                        <div :class="['pt-1', responses.length === 0 && 'pt-0']">
                          <p
                            v-if="responses.length === 0"
                            class="text-xs txt-secondary opacity-70 mb-2"
                          >
                            {{ $t('widgets.detail.responsesEmptyHint') }}
                          </p>
                          <div
                            :class="[
                              'grid gap-2',
                              responses.length > 0 ? 'grid-cols-3' : 'grid-cols-2',
                            ]"
                          >
                            <button
                              v-for="tpl in responseTemplates"
                              :key="tpl.key"
                              :class="[
                                'flex flex-col items-center gap-1.5 rounded-xl border transition-all duration-200 group/tpl',
                                responses.length > 0
                                  ? 'p-2 border-transparent hover:border-light-border/20 dark:hover:border-dark-border/15 hover:bg-[var(--brand)]/5'
                                  : 'p-4 border-light-border/20 dark:border-dark-border/15 hover:border-[var(--brand)]/40 hover:bg-[var(--brand)]/5 hover:scale-[1.03]',
                              ]"
                              @click="openWizard('response', tpl.key)"
                            >
                              <span
                                :class="[
                                  'rounded-xl flex items-center justify-center',
                                  responses.length > 0 ? 'w-7 h-7' : 'w-10 h-10',
                                  tpl.bg,
                                ]"
                              >
                                <Icon
                                  :icon="tpl.icon"
                                  :class="[
                                    responses.length > 0 ? 'w-3.5 h-3.5' : 'w-5 h-5',
                                    tpl.color,
                                  ]"
                                />
                              </span>
                              <span
                                :class="[
                                  'font-medium txt-primary',
                                  responses.length > 0 ? 'text-[10px]' : 'text-xs',
                                ]"
                              >
                                {{ $t(`widgets.detail.responseTemplates.${tpl.key}`) }}
                              </span>
                            </button>
                          </div>
                        </div>

                        <!-- Manual add -->
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
              </div>
            </div>

            <!-- Right: AI Setup Panel (desktop, only in split phase) -->
            <div
              v-if="aiSetupPhase !== 'fullscreen'"
              :class="[
                'hidden lg:flex lg:w-[30%] lg:flex-shrink-0 border-l border-light-border/30 dark:border-dark-border/20 p-4 transition-all duration-700 ease-out',
                aiSetupPhase === 'entering' ? 'animate-slide-in-right' : '',
              ]"
            >
              <WidgetAiSetupPanel
                v-if="widget"
                :widget-id="widget.widgetId"
                :current-flow="currentFlowSnapshot"
                @update-flow="handleAiFlowUpdate"
                @first-flow-received="transitionToSplitView"
                @update-widget-name="handleWidgetNameUpdate"
              />
            </div>

            <!-- Mobile: AI Panel toggle button + overlay (only in split phase) -->
            <template v-if="aiSetupPhase !== 'fullscreen'">
              <button
                class="lg:hidden fixed bottom-6 right-6 z-40 w-14 h-14 rounded-full bg-[var(--brand)] text-white shadow-lg shadow-[var(--brand)]/30 flex items-center justify-center hover:scale-105 active:scale-95 transition-transform"
                @click="showMobileAiPanel = !showMobileAiPanel"
              >
                <Icon
                  :icon="showMobileAiPanel ? 'heroicons:x-mark' : 'heroicons:sparkles'"
                  class="w-6 h-6"
                />
              </button>

              <Transition
                enter-active-class="transition-transform duration-300 ease-out"
                enter-from-class="translate-y-full"
                enter-to-class="translate-y-0"
                leave-active-class="transition-transform duration-200 ease-in"
                leave-from-class="translate-y-0"
                leave-to-class="translate-y-full"
              >
                <div
                  v-if="showMobileAiPanel && widget"
                  class="lg:hidden fixed inset-x-0 bottom-0 z-30 h-[75vh] bg-chat rounded-t-2xl shadow-2xl p-4"
                >
                  <WidgetAiSetupPanel
                    :widget-id="widget.widgetId"
                    :current-flow="currentFlowSnapshot"
                    @update-flow="handleAiFlowUpdate"
                    @first-flow-received="transitionToSplitView"
                    @update-widget-name="handleWidgetNameUpdate"
                  />
                </div>
              </Transition>
            </template>
          </div>
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
      :initial-tab="advancedInitialTab"
      @close="advancedWidget = null"
      @saved="handleAdvancedSaved"
      @start-ai-setup="openAiSetup"
    />

    <FilePicker
      :is-open="showWizardFilePicker"
      :exclude-message-ids="wizardFilePickerExcludeIds"
      @close="showWizardFilePicker = false"
      @select="handleWizardFilePickerSelect"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
  type ComponentPublicInstance,
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import SetupChatModal from '@/components/widgets/SetupChatModal.vue'
import AdvancedWidgetConfig from '@/components/widgets/AdvancedWidgetConfig.vue'
import WidgetAiSetupPanel from '@/components/widgets/WidgetAiSetupPanel.vue'
import FlowNodeEditor from '@/components/widgets/FlowNodeEditor.vue'
import FilePicker from '@/components/widgets/FilePicker.vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { promptsApi, type PromptMetadata } from '@/services/api/promptsApi'
import { chatApi } from '@/services/api/chatApi'
import { useNotification } from '@/composables/useNotification'
import {
  WIDGET_RULES_BLOCK_START,
  WIDGET_RULES_BLOCK_END,
  parsePromptAndRulesBlock,
} from '@/utils/widgetBehaviorRules'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

type ResponseType = 'link' | 'api' | 'text' | 'list' | 'pdf' | 'custom'

interface FlowNode {
  id: string
  label: string
  type?: ResponseType
  meta?: { url?: string; method?: string; crawlInterval?: string }
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
const auth = useAuthStore()
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

// AI setup phase: 'fullscreen' (initial) -> 'entering' (animating) -> 'split' (final)
const aiSetupPhase = ref<'fullscreen' | 'entering' | 'split'>('fullscreen')
const showMobileAiPanel = ref(false)

// Expand state for flow cards
const expandedNodeId = ref<string | null>(null)

// Editing state
const editingNodeId = ref<string | null>(null)

// Wizard state
const activeWizard = ref<{ side: 'trigger' | 'response'; key: string } | null>(null)
const wizardLabel = ref('')
const wizardDetails = ref('')
const wizardUrl = ref('')
const wizardMethod = ref('GET')
const enhancingField = ref<string | null>(null)
const wizardFiles = ref<Array<{ messageId: number; fileName: string }>>([])
const wizardUploadingFile = ref(false)
const showWizardFilePicker = ref(false)
const wizardFilePickerExcludeIds = computed(() => wizardFiles.value.map((f) => f.messageId))

const currentFlowSnapshot = computed(() => ({
  triggers: triggers.value,
  responses: responses.value,
  connections: connections.value,
}))

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

// Label display helpers
const splitLabel = (label: string): { title: string; preview: string } => {
  const colonIdx = label.indexOf(':')
  if (colonIdx > 0 && colonIdx < 40) {
    return {
      title: label.substring(0, colonIdx).trim(),
      preview: label.substring(colonIdx + 1).trim(),
    }
  }
  if (label.length > 40) {
    return { title: label.substring(0, 40) + '...', preview: label }
  }
  return { title: label, preview: '' }
}

const toggleExpand = (id: string) => {
  expandedNodeId.value = expandedNodeId.value === id ? null : id
}

const responseTypeMap: Record<string, { icon: string; bg: string; color: string }> = {
  link: { icon: 'heroicons:globe-alt', bg: 'bg-blue-500/10', color: 'text-blue-500' },
  api: { icon: 'heroicons:server-stack', bg: 'bg-violet-500/10', color: 'text-violet-500' },
  text: { icon: 'heroicons:document-text', bg: 'bg-emerald-500/10', color: 'text-emerald-500' },
  list: { icon: 'heroicons:list-bullet', bg: 'bg-amber-500/10', color: 'text-amber-500' },
  pdf: { icon: 'heroicons:document-arrow-down', bg: 'bg-red-500/10', color: 'text-red-500' },
  custom: { icon: 'heroicons:sparkles', bg: 'bg-pink-500/10', color: 'text-pink-500' },
}
const getResponseTypeInfo = (r: FlowNode) =>
  responseTypeMap[r.type ?? 'text'] ?? responseTypeMap.text

// Helpers
const isConnected = (triggerId: string, responseId: string) =>
  connections.value.some((c) => c.from === triggerId && c.to === responseId)
const hasConnectionFrom = (triggerId: string) => connections.value.some((c) => c.from === triggerId)
const hasConnectionTo = (responseId: string) => connections.value.some((c) => c.to === responseId)

// Template definitions
const triggerTemplates = [
  { key: 'location', icon: 'heroicons:map-pin', bg: 'bg-blue-500/10', color: 'text-blue-500' },
  {
    key: 'product',
    icon: 'heroicons:shopping-bag',
    bg: 'bg-purple-500/10',
    color: 'text-purple-500',
  },
  {
    key: 'pricing',
    icon: 'heroicons:currency-euro',
    bg: 'bg-emerald-500/10',
    color: 'text-emerald-500',
  },
  { key: 'support', icon: 'heroicons:lifebuoy', bg: 'bg-orange-500/10', color: 'text-orange-500' },
  {
    key: 'general',
    icon: 'heroicons:chat-bubble-left-right',
    bg: 'bg-gray-500/10',
    color: 'text-gray-500',
  },
]

const responseTemplates = [
  { key: 'link', icon: 'heroicons:globe-alt', bg: 'bg-blue-500/10', color: 'text-blue-500' },
  { key: 'api', icon: 'heroicons:server-stack', bg: 'bg-violet-500/10', color: 'text-violet-500' },
  {
    key: 'text',
    icon: 'heroicons:document-text',
    bg: 'bg-emerald-500/10',
    color: 'text-emerald-500',
  },
  { key: 'list', icon: 'heroicons:list-bullet', bg: 'bg-amber-500/10', color: 'text-amber-500' },
  { key: 'pdf', icon: 'heroicons:document-arrow-down', bg: 'bg-red-500/10', color: 'text-red-500' },
  { key: 'custom', icon: 'heroicons:sparkles', bg: 'bg-pink-500/10', color: 'text-pink-500' },
]

const openWizard = (side: 'trigger' | 'response', key: string) => {
  activeWizard.value = { side, key }
  wizardLabel.value = t(
    `widgets.detail.${side === 'trigger' ? 'triggerTemplates' : 'responseTemplates'}.${key}`
  )
  wizardDetails.value = ''
  wizardUrl.value = ''
  wizardMethod.value = 'GET'
  wizardFiles.value = []
}

const cancelWizard = () => {
  activeWizard.value = null
  wizardFiles.value = []
}

const handleWizardFileUpload = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !widget.value?.taskPromptTopic) return
  wizardUploadingFile.value = true
  try {
    await promptsApi.uploadPromptFile(widget.value.taskPromptTopic, file)
    const availableFiles = await promptsApi.getAvailableFiles(file.name)
    const match = availableFiles.find((f) => f.fileName === file.name)
    if (match) {
      wizardFiles.value.push({ messageId: match.messageId, fileName: match.fileName })
    }
  } catch {
    showError(t('widgets.detail.wizard.uploadError'))
  } finally {
    wizardUploadingFile.value = false
    input.value = ''
  }
}

const handleWizardFilePickerSelect = (files: Array<{ messageId: number; fileName: string }>) => {
  for (const f of files) {
    if (!wizardFiles.value.some((wf) => wf.messageId === f.messageId)) {
      wizardFiles.value.push({ messageId: f.messageId, fileName: f.fileName })
    }
  }
  showWizardFilePicker.value = false
}

const removeWizardFile = (messageId: number) => {
  wizardFiles.value = wizardFiles.value.filter((f) => f.messageId !== messageId)
}

const autoConnectResponse = (responseId: string) => {
  if (connections.value.length > 0) return
  for (const trig of triggers.value) {
    connections.value.push({ from: trig.id, to: responseId })
  }
}

const confirmWizard = async () => {
  const wiz = activeWizard.value
  if (!wiz) return
  const label = wizardLabel.value.trim()
  if (!label) return

  const details = wizardDetails.value.trim()
  const fullLabel = details ? `${label}: ${details}` : label

  if (wiz.side === 'trigger') {
    triggers.value.push({ id: `t-${Date.now()}`, label: fullLabel })
  } else {
    const id = `r-${Date.now()}`
    const url = wizardUrl.value.trim()
    const fileNames = wizardFiles.value.map((f) => f.fileName)
    const parts = [fullLabel, url, ...fileNames].filter(Boolean)
    const responseLabel =
      parts.length > 1 ? `${fullLabel} (${parts.slice(1).join(', ')})` : fullLabel
    const node: FlowNode = { id, label: responseLabel, type: wiz.key as ResponseType }
    if (url) node.meta = { url }
    if (wiz.key === 'api') {
      node.meta = { url, method: wizardMethod.value || 'GET' }
    }
    responses.value.push(node)
    autoConnectResponse(id)

    if (wizardFiles.value.length > 0 && widget.value?.taskPromptTopic) {
      for (const f of wizardFiles.value) {
        try {
          await promptsApi.linkFileToPrompt(widget.value.taskPromptTopic, f.messageId)
          if (!promptFiles.value.some((pf) => pf.id === f.messageId)) {
            promptFiles.value.push({ id: f.messageId, fileName: f.fileName, chunks: 0 })
          }
        } catch {
          /* file may already be linked */
        }
      }
    }
  }
  activeWizard.value = null
  wizardFiles.value = []
}

const enhanceField = async (field: 'label' | 'details') => {
  const current = field === 'label' ? wizardLabel.value : wizardDetails.value
  if (!current.trim()) return
  enhancingField.value = field
  try {
    const result = await chatApi.enhanceMessage(current)
    if (field === 'label') wizardLabel.value = result.enhanced
    else wizardDetails.value = result.enhanced
  } catch {
    /* silently ignore */
  } finally {
    enhancingField.value = null
  }
}

// Interaction
const selectTrigger = (id: string) => {
  if (editingNodeId.value === id) return
  selectedTriggerId.value = selectedTriggerId.value === id ? null : id
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
  const id = `r-${Date.now()}`
  responses.value.push({ id, label })
  autoConnectResponse(id)
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

const transitionToSplitView = () => {
  if (aiSetupPhase.value !== 'fullscreen') return
  aiSetupPhase.value = 'entering'
  setTimeout(() => {
    aiSetupPhase.value = 'split'
  }, 800)
}

const handleWidgetNameUpdate = async (name: string) => {
  if (!widget.value || !name.trim()) return
  try {
    await widgetsApi.updateWidget(widget.value.widgetId, { name: name.trim() })
    widget.value.name = name.trim()
  } catch {
    /* silently ignore name update failures */
  }
}

const handleAiFlowUpdate = (data: FlowData) => {
  triggers.value = data.triggers
  responses.value = data.responses
  connections.value = data.connections
  autoSaveFlow()
}

let autoSaveTimer: ReturnType<typeof setTimeout> | null = null
const autoSaveFlow = () => {
  if (autoSaveTimer) clearTimeout(autoSaveTimer)
  autoSaveTimer = setTimeout(() => {
    persistFlowData()
  }, 1500)
}

const persistFlowData = async () => {
  if (!widget.value) return
  const flowData: FlowData = {
    triggers: triggers.value,
    responses: responses.value,
    connections: connections.value,
  }
  const rulesBlock = buildFlowRulesBlock()
  const base = manualPromptContent.value.trim()
  const composed = [rulesBlock, base].filter(Boolean).join('\n\n')
  const metadata: PromptMetadata = {
    ...promptMetadata.value,
    aiModel: typeof promptMetadata.value.aiModel === 'number' ? promptMetadata.value.aiModel : -1,
    widgetFlowRules: JSON.stringify(flowData),
    widgetBehaviorVersion: '2',
  }

  try {
    if (promptId.value > 0) {
      await promptsApi.updatePrompt(promptId.value, { prompt: composed, metadata })
    } else {
      const gen = await widgetsApi.generateWidgetPrompt(widget.value.widgetId, composed, [])
      promptId.value = gen.promptId
      await promptsApi.updatePrompt(gen.promptId, { prompt: composed, metadata })
      widget.value = await widgetsApi.getWidget(widget.value.widgetId)
    }
  } catch {
    /* auto-save failures are silent — user can still save manually */
  }
}

// Node editing
const startEditing = (nodeId: string) => {
  editingNodeId.value = nodeId
}
const handleNodeSave = (updated: FlowNode, nodeType: 'trigger' | 'response') => {
  const list = nodeType === 'trigger' ? triggers.value : responses.value
  const idx = list.findIndex((n) => n.id === updated.id)
  if (idx >= 0) {
    list[idx] = updated
  }
  editingNodeId.value = null
  autoSaveFlow()
}
const cancelEditing = () => {
  editingNodeId.value = null
}

// Defaults (empty — user adds their own)
const defaultTriggers = (): FlowNode[] => []
const defaultResponses = (): FlowNode[] => []

const migrateFromBehaviorRules = () => {
  triggers.value = []
  responses.value = []
  connections.value = []
}

// Build prompt from flow
const buildFlowRulesBlock = (): string => {
  const grouped = new Map<string, FlowNode[]>()
  for (const conn of connections.value) {
    const trig = triggers.value.find((n) => n.id === conn.from)
    const resp = responses.value.find((n) => n.id === conn.to)
    if (!trig || !resp) continue
    if (!grouped.has(trig.label)) grouped.set(trig.label, [])
    grouped.get(trig.label)!.push(resp)
  }
  if (grouped.size === 0) return ''
  const lines = [WIDGET_RULES_BLOCK_START, 'Widget behavior rules:']
  for (const [trigger, resps] of grouped) {
    lines.push(`When user asks about "${trigger}":`)
    for (const r of resps) {
      if (r.type === 'link' && r.meta?.url) {
        lines.push(
          `- Respond using knowledge crawled from: ${r.label} (${r.meta.url}). Also include the link for the user.`
        )
      } else if (r.type === 'api' && r.meta?.url) {
        lines.push(
          `- Use the live API data provided in "## Live API Data" section from: ${r.meta.method ?? 'GET'} ${r.meta.url}`
        )
      } else {
        lines.push(`- ${r.label}`)
      }
    }
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
            const legacyIds = new Set([
              'location',
              'general',
              'location-link',
              'location-image',
              'concise',
              'cta',
            ])
            triggers.value = (flow.triggers || []).filter((n) => !legacyIds.has(n.id))
            responses.value = (flow.responses || []).filter((n) => !legacyIds.has(n.id))
            connections.value = (flow.connections || []).filter(
              (c) => !legacyIds.has(c.from) && !legacyIds.has(c.to)
            )
          } catch {
            migrateFromBehaviorRules()
          }
        } else {
          migrateFromBehaviorRules()
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
    if (triggers.value.length > 0 || responses.value.length > 0) {
      aiSetupPhase.value = 'split'
    }
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

    const hasLinkResponses = responses.value.some((r) => r.type === 'link' && r.meta?.url?.trim())
    if (hasLinkResponses && auth.isPro) {
      try {
        const crawlResult = await widgetsApi.triggerCrawl(widget.value.widgetId)
        if (crawlResult.urls_queued > 0) {
          success(t('widgets.detail.nodeEditor.crawlSuccess'))
        }
      } catch {
        // Non-critical: crawl failure should not block save
      }
    }
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
const advancedInitialTab = ref<string | undefined>()
const openAdvancedModal = (tab?: string) => {
  if (widget.value) {
    advancedInitialTab.value = tab
    advancedWidget.value = widget.value
  }
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

@keyframes slideInLeft {
  from {
    opacity: 0;
    transform: translateX(-40px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

@keyframes slideInRight {
  from {
    opacity: 0;
    transform: translateX(40px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.animate-slide-in-left {
  animation: slideInLeft 0.6s ease-out both;
}

.animate-slide-in-right {
  animation: slideInRight 0.6s ease-out 0.15s both;
}
</style>
