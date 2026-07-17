<template>
  <textarea
    ref="textareaRef"
    :value="modelValue"
    :placeholder="placeholder"
    :rows="rows"
    class="chat-textarea block w-full bg-transparent resize-none overflow-hidden min-h-[40px] leading-6 text-[16px] txt-primary border-0 px-0 py-2 focus:outline-none focus:ring-0 placeholder:txt-secondary"
    data-testid="input-textarea"
    @input="handleInput"
    @focus="emit('focus')"
    @blur="emit('blur')"
  />
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue'

interface Props {
  modelValue: string
  placeholder?: string
  rows?: number
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: '',
  rows: 1,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  focus: []
  blur: []
}>()

const textareaRef = ref<HTMLTextAreaElement | null>(null)

const adjustHeight = () => {
  const el = textareaRef.value
  if (!el) return

  el.style.height = 'auto'
  // With `box-sizing: border-box`, scrollHeight (content + padding, no border)
  // is NOT the value to assign to `height`: the border eats into the content
  // box, leaving it shorter than one line-height. The single line then top-clips
  // and the caret detaches from the placeholder baseline — on iOS WKWebView this
  // shows up as a caret pinned to the top-left while the placeholder sits lower.
  // Add the border back so the content box always fits a full line. No-op when
  // there is no border.
  const style = getComputedStyle(el)
  const borderY =
    'border-box' === style.boxSizing
      ? parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth)
      : 0
  el.style.height = `${el.scrollHeight + borderY}px`
}

const handleInput = (event: Event) => {
  const target = event.target as HTMLTextAreaElement
  emit('update:modelValue', target.value)
  adjustHeight()
}

watch(
  () => props.modelValue,
  async () => {
    await nextTick()
    adjustHeight()
  }
)

onMounted(() => {
  adjustHeight()
})

// Expose focus method for parent components
const focus = () => {
  textareaRef.value?.focus()
}

defineExpose({
  focus,
  textareaRef,
})
</script>
