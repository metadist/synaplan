<template>
  <div class="widget-preview-container" data-testid="comp-widget-promo-preview">
    <!-- Mock Browser -->
    <div
      class="relative rounded-xl overflow-hidden border border-black/[0.08] shadow-lg"
    >
      <!-- Browser Chrome -->
      <div class="flex items-center gap-2 px-3 py-1.5 border-b bg-gray-100/80 border-black/[0.04]">
        <div class="flex gap-1">
          <div class="w-2 h-2 rounded-full bg-red-400/80"></div>
          <div class="w-2 h-2 rounded-full bg-yellow-400/80"></div>
          <div class="w-2 h-2 rounded-full bg-green-400/80"></div>
        </div>
        <div class="flex-1 px-2.5 py-0.5 rounded-md text-[10px] font-mono truncate bg-white/70 text-gray-500">
          your-website.com
        </div>
        <div class="flex gap-1 opacity-30">
          <div class="w-3 h-3 rounded-sm bg-gray-400"></div>
        </div>
      </div>

      <!-- Mock Website Content -->
      <div
        :class="[
          'relative overflow-hidden bg-white',
          compact ? 'h-[180px]' : 'h-[260px] sm:h-[300px]',
        ]"
      >
        <!-- Skeleton Website -->
        <div :class="compact ? 'p-3' : 'p-4 sm:p-5'">
          <!-- Nav -->
          <div class="flex items-center justify-between" :class="compact ? 'mb-3' : 'mb-4 sm:mb-5'">
            <div class="flex items-center gap-2">
              <div :class="['w-7 h-7 rounded-md', skelClass]"></div>
              <div :class="['w-20 h-3 rounded', skelClass]"></div>
            </div>
            <div class="hidden sm:flex gap-3">
              <div :class="['w-14 h-2.5 rounded', skelClass]"></div>
              <div :class="['w-14 h-2.5 rounded', skelClass]"></div>
              <div :class="['w-14 h-2.5 rounded', skelClass]"></div>
            </div>
          </div>
          <!-- Hero -->
          <div :class="compact ? 'mb-3' : 'mb-4 sm:mb-5'">
            <div :class="['w-3/4 rounded mb-2', skelClass, compact ? 'h-4' : 'h-5 sm:h-6']"></div>
            <div :class="['w-1/2 h-3 rounded', skelLightClass]"></div>
          </div>
          <!-- Cards -->
          <div class="grid grid-cols-3" :class="compact ? 'gap-1.5' : 'gap-2 sm:gap-3'">
            <div :class="['rounded-lg', skelLightClass, compact ? 'h-12' : 'h-16 sm:h-20']"></div>
            <div :class="['rounded-lg', skelLightClass, compact ? 'h-12' : 'h-16 sm:h-20']"></div>
            <div :class="['rounded-lg', skelLightClass, compact ? 'h-12' : 'h-16 sm:h-20']"></div>
          </div>
          <!-- Text lines -->
          <div v-if="!compact" class="mt-3 space-y-1.5">
            <div :class="['w-full h-2 rounded', skelLightClass]"></div>
            <div :class="['w-5/6 h-2 rounded', skelLightClass]"></div>
            <div :class="['w-3/4 h-2 rounded', skelLightClass]"></div>
          </div>
        </div>

        <!-- Chat Widget Overlay -->
        <div :class="compact ? 'absolute bottom-2 right-2 z-10' : 'absolute bottom-3 right-3 z-10'">
          <!-- Chat Window -->
          <Transition
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="opacity-0 translate-y-4 scale-95"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 translate-y-4 scale-95"
          >
            <div
              v-if="chatOpen"
              :class="[
                'absolute right-0 rounded-xl shadow-2xl overflow-hidden border border-black/[0.06]',
                compact ? 'bottom-12 w-56' : 'bottom-14 w-64 sm:w-72',
              ]"
            >
              <!-- Chat Header -->
              <div
                class="px-3 py-2 flex items-center justify-between"
                style="background: linear-gradient(135deg, #00b79d, #00d4bc)"
              >
                <div class="flex items-center gap-1.5">
                  <Icon icon="heroicons:chat-bubble-left-right" class="w-4 h-4 text-white" />
                  <span class="text-white font-medium text-xs">AI Assistant</span>
                </div>
                <button
                  class="text-white/70 hover:text-white transition-colors w-5 h-5 flex items-center justify-center"
                  @click.stop="chatOpen = false"
                >
                  <Icon icon="heroicons:x-mark" class="w-3.5 h-3.5" />
                </button>
              </div>

              <!-- Chat Messages -->
              <div
                :class="[
                  'overflow-y-auto bg-gray-50/80',
                  compact ? 'p-2.5 h-28' : 'p-3 h-32 sm:h-40',
                ]"
              >
                <!-- AI Greeting -->
                <Transition
                  enter-active-class="transition-all duration-300 ease-out"
                  enter-from-class="opacity-0 translate-y-2"
                  enter-to-class="opacity-100 translate-y-0"
                >
                  <div v-if="showGreeting" class="flex gap-2 mb-2.5">
                    <div
                      class="w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center text-white text-[8px] font-bold"
                      style="background: linear-gradient(135deg, #00b79d, #00d4bc)"
                    >
                      AI
                    </div>
                    <div
                      class="rounded-lg rounded-tl-sm px-2.5 py-1.5 text-[11px] shadow-sm max-w-[85%] leading-relaxed bg-white text-gray-700"
                    >
                      {{ $t('promoTips.widgetPreview.aiGreeting') }}
                    </div>
                  </div>
                </Transition>

                <!-- User Message -->
                <Transition
                  enter-active-class="transition-all duration-300 ease-out"
                  enter-from-class="opacity-0 translate-y-2"
                  enter-to-class="opacity-100 translate-y-0"
                >
                  <div v-if="showUserMsg" class="flex gap-2 mb-2.5 justify-end">
                    <div
                      class="rounded-lg rounded-tr-sm px-2.5 py-1.5 text-[11px] text-white shadow-sm max-w-[85%] leading-relaxed"
                      style="background: linear-gradient(135deg, #00b79d, #009e88)"
                    >
                      {{ $t('promoTips.widgetPreview.userMessage') }}
                    </div>
                  </div>
                </Transition>

                <!-- AI Typing / Response -->
                <Transition
                  enter-active-class="transition-all duration-300 ease-out"
                  enter-from-class="opacity-0 translate-y-2"
                  enter-to-class="opacity-100 translate-y-0"
                >
                  <div v-if="showTyping || showAiResponse" class="flex gap-2">
                    <div
                      class="w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center text-white text-[8px] font-bold"
                      style="background: linear-gradient(135deg, #00b79d, #00d4bc)"
                    >
                      AI
                    </div>
                    <div
                      class="rounded-lg rounded-tl-sm px-2.5 py-1.5 text-[11px] shadow-sm max-w-[85%] leading-relaxed bg-white text-gray-700"
                    >
                      <span v-if="showTyping && !showAiResponse" class="flex gap-0.5 py-0.5">
                        <span class="typing-dot"></span>
                        <span class="typing-dot delay-1"></span>
                        <span class="typing-dot delay-2"></span>
                      </span>
                      <span v-else>{{ $t('promoTips.widgetPreview.aiResponse') }}</span>
                    </div>
                  </div>
                </Transition>
              </div>

              <!-- Chat Input -->
              <div class="px-2.5 py-2 border-t border-gray-200/50 bg-white">
                <div class="flex gap-1.5">
                  <div class="flex-1 px-2.5 py-1.5 text-[10px] rounded-lg border border-gray-200/60 bg-gray-50/50 text-gray-400 truncate">
                    {{ $t('promoTips.widgetPreview.inputPlaceholder') }}
                  </div>
                  <button
                    class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                    style="background: linear-gradient(135deg, #00b79d, #00d4bc)"
                  >
                    <Icon icon="heroicons:paper-airplane" class="w-3.5 h-3.5 text-white" />
                  </button>
                </div>
              </div>
            </div>
          </Transition>

          <!-- Floating Chat Button -->
          <button
            class="relative group flex items-center justify-center rounded-full shadow-lg cursor-pointer transition-all duration-200 hover:scale-110 hover:shadow-xl"
            :class="compact ? 'w-10 h-10' : 'w-12 h-12'"
            style="background: linear-gradient(135deg, #00b79d, #00d4bc)"
            @click.stop="chatOpen = !chatOpen"
          >
            <Icon
              :icon="chatOpen ? 'heroicons:x-mark' : 'heroicons:chat-bubble-left-right'"
              :class="compact ? 'w-5 h-5' : 'w-6 h-6'"
              class="text-white transition-transform duration-200"
            />
            <span
              v-if="!chatOpen"
              class="absolute -top-0.5 -right-0.5 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-pulse"
            ></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { Icon } from '@iconify/vue'

const props = defineProps<{
  compact?: boolean
  autoPlay?: boolean
}>()

const skelClass = 'bg-black/[0.06]'
const skelLightClass = 'bg-black/[0.03]'

const chatOpen = ref(false)
const showGreeting = ref(false)
const showUserMsg = ref(false)
const showTyping = ref(false)
const showAiResponse = ref(false)

let timeouts: ReturnType<typeof setTimeout>[] = []

function clearAllTimeouts() {
  timeouts.forEach(clearTimeout)
  timeouts = []
}

function runAutoPlay() {
  clearAllTimeouts()
  chatOpen.value = false
  showGreeting.value = false
  showUserMsg.value = false
  showTyping.value = false
  showAiResponse.value = false

  timeouts.push(setTimeout(() => { chatOpen.value = true }, 600))
  timeouts.push(setTimeout(() => { showGreeting.value = true }, 1000))
  timeouts.push(setTimeout(() => { showUserMsg.value = true }, 2200))
  timeouts.push(setTimeout(() => { showTyping.value = true }, 3000))
  timeouts.push(setTimeout(() => { showAiResponse.value = true }, 4200))
}

watch(() => props.autoPlay, (val) => {
  if (val) runAutoPlay()
  else clearAllTimeouts()
})

onMounted(() => {
  if (props.autoPlay !== false) {
    runAutoPlay()
  }
})

onBeforeUnmount(() => {
  clearAllTimeouts()
})
</script>

<style scoped>
.typing-dot {
  display: inline-block;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #9ca3af;
  animation: typing-bounce 1.2s infinite ease-in-out;
}
.typing-dot.delay-1 {
  animation-delay: 0.15s;
}
.typing-dot.delay-2 {
  animation-delay: 0.3s;
}

@keyframes typing-bounce {
  0%, 60%, 100% {
    transform: translateY(0);
    opacity: 0.4;
  }
  30% {
    transform: translateY(-4px);
    opacity: 1;
  }
}
</style>
