<template>
  <!-- Collapsed inline bar -->
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="opacity-0 translate-y-2 scale-[0.98]"
    enter-to-class="opacity-100 translate-y-0 scale-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="opacity-100 translate-y-0 scale-100"
    leave-to-class="opacity-0 translate-y-2 scale-[0.98]"
  >
    <div
      v-if="tip && !expanded"
      class="mx-auto max-w-4xl px-4 pb-2"
      data-testid="comp-promo-tip"
    >
      <div
        :class="[
          'relative overflow-hidden rounded-xl border transition-all duration-200',
          isDark ? 'border-white/[0.06]' : 'border-black/[0.06]',
          'bg-gradient-to-r',
          tip.gradient,
        ]"
      >
        <div
          class="flex items-center gap-3 px-4 py-2.5 cursor-pointer select-none"
          @click="$emit('toggle')"
        >
          <div
            :class="[
              'w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0',
              isDark ? 'bg-white/[0.08]' : 'bg-white/60',
            ]"
          >
            <Icon :icon="tip.icon" class="w-4 h-4 txt-primary" />
          </div>
          <p class="flex-1 text-[13px] font-medium txt-primary truncate">
            {{ $t(tip.titleKey) }}
          </p>
          <div class="flex items-center gap-1.5 flex-shrink-0">
            <button
              :class="[
                'w-6 h-6 rounded-md flex items-center justify-center transition-colors',
                isDark ? 'hover:bg-white/5' : 'hover:bg-black/5',
              ]"
              :title="$t('common.expand') || 'Expand'"
              @click.stop="$emit('toggle')"
            >
              <Icon icon="mdi:arrow-expand" class="w-3.5 h-3.5 txt-secondary" />
            </button>
            <button
              :class="[
                'w-6 h-6 rounded-md flex items-center justify-center transition-colors',
                isDark ? 'hover:bg-white/5' : 'hover:bg-black/5',
              ]"
              @click.stop="$emit('dismiss')"
            >
              <Icon icon="mdi:close" class="w-3.5 h-3.5 txt-secondary" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </Transition>

  <!-- Expanded: Full-screen centered modal with blurred backdrop -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="tip && expanded"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4 sm:p-6"
        data-testid="modal-promo-tip"
      >
        <!-- Blurred backdrop — click to close -->
        <div
          :class="[
            'absolute inset-0 backdrop-blur-sm',
            isDark ? 'bg-black/50' : 'bg-black/25',
          ]"
          @click="$emit('dismiss')"
        ></div>

        <!-- Modal content -->
        <Transition
          enter-active-class="transition-all duration-300 ease-out"
          enter-from-class="opacity-0 scale-95 translate-y-4"
          enter-to-class="opacity-100 scale-100 translate-y-0"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="opacity-100 scale-100 translate-y-0"
          leave-to-class="opacity-0 scale-95 translate-y-4"
          appear
        >
          <div
            v-if="tip && expanded"
            :class="[
              'relative w-full overflow-hidden rounded-2xl shadow-2xl',
              isDark ? 'bg-[#0f1729] border-white/[0.08]' : 'bg-white border-black/[0.06]',
              'border',
              hasPreview ? 'max-w-3xl' : 'max-w-md',
            ]"
            @click.stop
          >
            <!-- Gradient accent top bar -->
            <div
              :class="['h-1 bg-gradient-to-r', tip.gradient]"
            ></div>

            <!-- Close button -->
            <button
              :class="[
                'absolute top-3 right-3 z-10 w-8 h-8 rounded-lg flex items-center justify-center transition-colors',
                isDark ? 'bg-white/5 hover:bg-white/10' : 'bg-black/5 hover:bg-black/10',
              ]"
              @click="$emit('dismiss')"
            >
              <Icon icon="mdi:close" class="w-4 h-4 txt-secondary" />
            </button>

            <!-- Preview layout (chat-widget) -->
            <div v-if="hasPreview" class="p-5 sm:p-6">
              <!-- Header -->
              <div class="flex items-center gap-3 mb-4 pr-8">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-[var(--brand)]/10">
                  <Icon :icon="tip.icon" class="w-5 h-5 text-[var(--brand)]" />
                </div>
                <div>
                  <h3
                    :class="[
                      'text-base font-semibold leading-tight',
                      isDark ? 'text-gray-100' : 'text-gray-800',
                    ]"
                  >
                    {{ $t(tip.titleKey) }}
                  </h3>
                  <p
                    :class="[
                      'text-xs mt-0.5',
                      isDark ? 'text-gray-400' : 'text-gray-500',
                    ]"
                  >
                    {{ $t(tip.descriptionKey) }}
                  </p>
                </div>
              </div>

              <!-- Two-column: features + preview -->
              <div class="flex flex-col sm:flex-row gap-5">
                <!-- Left: Features & CTA -->
                <div class="sm:w-2/5 flex flex-col justify-between min-w-0">
                  <ul class="space-y-2.5 mb-5">
                    <li
                      v-for="(feature, idx) in previewFeatures"
                      :key="idx"
                      :class="[
                        'flex items-start gap-2.5 text-[13px]',
                        isDark ? 'text-gray-400' : 'text-gray-600',
                      ]"
                    >
                      <div class="w-6 h-6 rounded-md bg-[var(--brand)]/8 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <Icon :icon="feature.icon" class="w-3.5 h-3.5 text-[var(--brand)]" />
                      </div>
                      <span class="leading-snug pt-0.5">{{ $t(feature.key) }}</span>
                    </li>
                  </ul>

                  <div class="flex flex-col gap-2">
                    <button
                      v-if="tip.actionRoute"
                      class="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-medium rounded-xl btn-primary transition-all hover:shadow-lg w-full sm:w-auto"
                      @click="$emit('action', tip.actionRoute)"
                    >
                      {{ $t(tip.actionKey) }}
                      <Icon icon="mdi:arrow-right" class="w-4 h-4" />
                    </button>
                    <button
                      class="text-xs txt-secondary hover:txt-primary transition-colors py-1.5 text-center sm:text-left"
                      @click="$emit('dismiss-permanent')"
                    >
                      {{ $t('promoTips.dontShowAgain') }}
                    </button>
                  </div>
                </div>

                <!-- Right: Interactive Preview -->
                <div class="sm:w-3/5 min-w-0">
                  <ChatWidgetPromoPreview :auto-play="true" />
                </div>
              </div>
            </div>

            <!-- Standard layout (no preview) -->
            <div v-else class="p-5 sm:p-6">
              <div class="flex items-center gap-3 mb-3 pr-8">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-[var(--brand)]/10">
                  <Icon :icon="tip.icon" class="w-5 h-5 text-[var(--brand)]" />
                </div>
                <h3
                  :class="[
                    'text-base font-semibold',
                    isDark ? 'text-gray-100' : 'text-gray-800',
                  ]"
                >
                  {{ $t(tip.titleKey) }}
                </h3>
              </div>
              <p
                :class="[
                  'text-sm leading-relaxed mb-5 pl-[52px]',
                  isDark ? 'text-gray-400' : 'text-gray-500',
                ]"
              >
                {{ $t(tip.descriptionKey) }}
              </p>
              <div class="flex items-center gap-3 pl-[52px]">
                <button
                  v-if="tip.actionRoute"
                  class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-xl btn-primary transition-all hover:shadow-lg"
                  @click="$emit('action', tip.actionRoute)"
                >
                  {{ $t(tip.actionKey) }}
                  <Icon icon="mdi:arrow-right" class="w-4 h-4" />
                </button>
                <button
                  class="text-xs txt-secondary hover:txt-primary transition-colors px-2 py-1.5"
                  @click="$emit('dismiss-permanent')"
                >
                  {{ $t('promoTips.dontShowAgain') }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Icon } from '@iconify/vue'
import type { PromoTip } from '@/composables/usePromoTips'
import ChatWidgetPromoPreview from '@/components/ChatWidgetPromoPreview.vue'

const props = defineProps<{
  tip: PromoTip | null
  expanded: boolean
}>()

const emit = defineEmits<{
  toggle: []
  dismiss: []
  'dismiss-permanent': []
  action: [route: string]
}>()

const isDark = ref(document.documentElement.classList.contains('dark'))

const observer = new MutationObserver(() => {
  isDark.value = document.documentElement.classList.contains('dark')
})

const TIPS_WITH_PREVIEW = ['chat-widget']

const hasPreview = computed(() => props.tip && TIPS_WITH_PREVIEW.includes(props.tip.id))

const previewFeatures = computed(() => {
  if (props.tip?.id === 'chat-widget') {
    return [
      { icon: 'mdi:palette-outline', key: 'promoTips.widgetPreview.featureCustomizable' },
      { icon: 'mdi:robot-outline', key: 'promoTips.widgetPreview.featureAiPowered' },
      { icon: 'mdi:code-tags', key: 'promoTips.widgetPreview.featureEasyEmbed' },
      { icon: 'mdi:cellphone-link', key: 'promoTips.widgetPreview.featureResponsive' },
    ]
  }
  return []
})

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.expanded) {
    emit('dismiss')
  }
}

watch(() => props.expanded, (isExpanded) => {
  if (isExpanded) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleKeydown)
  observer.disconnect()
  document.body.style.overflow = ''
})
</script>
