<template>
  <textarea
    ref="textareaRef"
    :value="modelValue"
    :placeholder="placeholder"
    :rows="rows"
    class="chat-textarea block w-full bg-transparent resize-none overflow-hidden min-h-[44px] leading-6 text-[16px] txt-primary border-0 px-0 py-[11px] focus:outline-none focus:ring-0 placeholder:txt-secondary"
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
  el.style.height = el.scrollHeight + 'px'
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
