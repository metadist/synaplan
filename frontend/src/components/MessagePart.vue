<template>
  <component :is="componentType" v-bind="componentProps" />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { Part } from '../stores/history'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import MessageText from './MessageText.vue'
import MessageImage from './MessageImage.vue'
import MessageVideo from './MessageVideo.vue'
import MessageAudio from './MessageAudio.vue'
import MessageCode from './MessageCode.vue'
import MessageLinks from './MessageLinks.vue'
import MessageDocs from './MessageDocs.vue'
import MessageScreenshot from './MessageScreenshot.vue'
import MessageTranslation from './MessageTranslation.vue'
import MessageLink from './MessageLink.vue'
import MessageCommandList from './MessageCommandList.vue'
import MessageThinking from './MessageThinking.vue'
import MessageTtsLoading from './MessageTtsLoading.vue'

interface Props {
  part: Part
  isStreaming?: boolean
  memories?: UserMemory[] | null // Full memory objects (resolved from IDs)
}

const props = defineProps<Props>()

const componentType = computed(() => {
  switch (props.part.type) {
    case 'text':
      return MessageText
    case 'image':
      return MessageImage
    case 'video':
      return MessageVideo
    case 'audio':
      return MessageAudio
    case 'code':
      return MessageCode
    case 'links':
      return MessageLinks
    case 'docs':
      return MessageDocs
    case 'screenshot':
      return MessageScreenshot
    case 'translation':
      return MessageTranslation
    case 'link':
      return MessageLink
    case 'commandList':
      return MessageCommandList
    case 'thinking':
      return MessageThinking
    case 'tts_loading':
      return MessageTtsLoading
    default:
      return MessageText
  }
})

const componentProps = computed(() => {
  switch (props.part.type) {
    case 'text':
      return {
        content: props.part.content || '',
        isStreaming: props.isStreaming,
        memories: props.memories,
      }
    case 'image':
      return { url: props.part.url || '', alt: props.part.alt }
    case 'video':
      return { url: props.part.url || '', poster: props.part.poster }
    case 'audio':
      return { url: props.part.url || '', autoplay: props.part.autoplay }
    case 'code':
      return {
        content: props.part.content || '',
        language: props.part.language,
        filename: props.part.filename,
      }
    case 'links':
      return { items: props.part.items || [] }
    case 'docs':
      return { matches: props.part.matches || [] }
    case 'screenshot':
      return { url: props.part.url || '', imageUrl: props.part.imageUrl || props.part.url || '' }
    case 'translation':
      return {
        content: props.part.content || '',
        lang: props.part.lang || '',
        result: props.part.result || '',
      }
    case 'link':
      return {
        url: props.part.url || '',
        expiresAt: props.part.expiresAt || '',
      }
    case 'commandList':
      return { items: props.part.items || [] }
    case 'thinking':
      return {
        content: props.part.content || '',
        thinkingTime: props.part.thinkingTime,
      }
    default:
      return { content: props.part.content || '', memories: props.memories }
  }
})
</script>
