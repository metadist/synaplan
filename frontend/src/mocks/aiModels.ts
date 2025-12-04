import type { ModelOption } from '@/composables/useModelSelection'

export type ModelPurpose = 'chat' | 'pic2text' | 'sound2text' | 'text2pic' | 'text2sound' | 'text2vid' | 'vectorize' | 'sort' | 'summarize'

export interface AIModel {
  id: number
  purpose: ModelPurpose
  service: string
  name: string
  description: string
  isSystemModel?: boolean
}

export interface DefaultModelConfig {
  chat: string
  pic2text: string
  sort: string
  sound2text: string
  summarize: string
  text2pic: string
  text2sound: string
  text2vid: string
  vectorize: string
}

export const mockAvailableModels: AIModel[] = [
  {
    id: 69,
    purpose: 'chat',
    service: 'Anthropic',
    name: 'Claude Opus 4',
    description: 'Claude Opus 4 of Anthropic as the alternative chat method.'
  },
  {
    id: 74,
    purpose: 'chat',
    service: 'Anthropic',
    name: 'Claude Sonnet 4',
    description: 'Anthropic Claude Sonnet 4 mode. Mid-tier reasoning and coding performance with large context window. Balanced between quality and cost.'
  },
  {
    id: 61,
    purpose: 'chat',
    service: 'Google',
    name: 'Gemini 2.5 Pro',
    description: 'Googles Answer to the other LLM models'
  },
  {
    id: 3,
    purpose: 'chat',
    service: 'Groq',
    name: 'Llama 3.3 70b versatile',
    description: 'Fast API service via groq'
  },
  {
    id: 49,
    purpose: 'chat',
    service: 'Groq',
    name: 'llama-4-maverick-17b-128e-instruct',
    description: 'Groq Llama4 128e processing and text extraction'
  },
  {
    id: 1,
    purpose: 'chat',
    service: 'OpenAI',
    name: 'gpt-S (OpenAI)',
    description: 'OpenAI GPT-S model for chat'
  },
  {
    id: 75,
    purpose: 'pic2text',
    service: 'Groq',
    name: 'llama-4-scout-17b-16e-instruct',
    description: 'Groq Llama4 16e vision model for image analysis'
  },
  {
    id: 76,
    purpose: 'pic2text',
    service: 'OpenAI',
    name: 'gpt-4-vision',
    description: 'OpenAI GPT-4 Vision for image understanding'
  },
  {
    id: 77,
    purpose: 'sound2text',
    service: 'Groq',
    name: 'whisper-large-v3',
    description: 'Groq Whisper v3 for audio transcription'
  },
  {
    id: 78,
    purpose: 'sound2text',
    service: 'OpenAI',
    name: 'whisper-1',
    description: 'OpenAI Whisper for speech to text'
  },
  {
    id: 79,
    purpose: 'text2pic',
    service: 'OpenAI',
    name: 'gpt-image-1',
    description: 'OpenAI DALL-E image generation'
  },
  {
    id: 80,
    purpose: 'text2pic',
    service: 'Stability',
    name: 'stable-diffusion-xl',
    description: 'Stability AI image generation model'
  },
  {
    id: 81,
    purpose: 'text2sound',
    service: 'OpenAI',
    name: 'tts-1 with Nova',
    description: 'OpenAI text-to-speech with Nova voice'
  },
  {
    id: 82,
    purpose: 'text2sound',
    service: 'ElevenLabs',
    name: 'eleven-multilingual-v2',
    description: 'ElevenLabs multilingual TTS'
  },
  {
    id: 83,
    purpose: 'text2vid',
    service: 'Google',
    name: 'Veo 2.0',
    description: 'Google Veo 2.0 video generation'
  },
  {
    id: 84,
    purpose: 'text2vid',
    service: 'RunwayML',
    name: 'Gen-3',
    description: 'RunwayML Gen-3 video generation'
  },
  {
    id: 2,
    purpose: 'vectorize',
    service: 'Ollama',
    name: 'bge-m3',
    description: 'BGE-M3 embedding model for RAG',
    isSystemModel: true
  },
  {
    id: 85,
    purpose: 'vectorize',
    service: 'OpenAI',
    name: 'text-embedding-3-large',
    description: 'OpenAI embedding model',
    isSystemModel: true
  },
  {
    id: 86,
    purpose: 'sort',
    service: 'Groq',
    name: 'Llama 3.3 70b versatile',
    description: 'Fast API service for sorting tasks'
  },
  {
    id: 87,
    purpose: 'summarize',
    service: 'Groq',
    name: 'Llama 3.3 70b versatile',
    description: 'Fast API service for summarization'
  },
  {
    id: 88,
    purpose: 'summarize',
    service: 'OpenAI',
    name: 'gpt-4-turbo',
    description: 'OpenAI GPT-4 Turbo for summarization'
  }
]

export const mockDefaultConfig: DefaultModelConfig = {
  chat: 'gpt-S (OpenAI)',
  pic2text: 'llama-4-scout-17b-16e-instruct (Groq)',
  sort: 'Llama 3.3 70b versatile (Groq)',
  sound2text: 'whisper-large-v3 (Groq)',
  summarize: 'Llama 3.3 70b versatile (Groq)',
  text2pic: 'gpt-image-1 (OpenAI)',
  text2sound: 'tts-1 with Nova (OpenAI)',
  text2vid: 'Veo 2.0 (Google)',
  vectorize: 'bge-m3 (Ollama) [System Model]'
}

export const purposeLabels: Record<ModelPurpose, string> = {
  chat: 'CHAT',
  pic2text: 'PIC2TEXT',
  sound2text: 'SOUND2TEXT',
  text2pic: 'TEXT2PIC',
  text2sound: 'TEXT2SOUND',
  text2vid: 'TEXT2VID',
  vectorize: 'VECTORIZE',
  sort: 'SORT',
  summarize: 'SUMMARIZE'
}

export const serviceColors: Record<string, string> = {
  'Anthropic': 'bg-orange-500',
  'Google': 'bg-blue-500',
  'Groq': 'bg-cyan-500',
  'OpenAI': 'bg-green-500',
  'Ollama': 'bg-purple-500',
  'Stability': 'bg-pink-500',
  'ElevenLabs': 'bg-indigo-500',
  'RunwayML': 'bg-yellow-500'
}

export const mockModelOptions: ModelOption[] = [
  { provider: 'OpenAI', model: 'gpt-4', label: 'GPT-4', id: 1, quality: 90, rating: 95, description: 'Most capable GPT-4 model' },
  { provider: 'OpenAI', model: 'gpt-4-turbo', label: 'GPT-4 Turbo', id: 2, quality: 85, rating: 90, description: 'Faster GPT-4 variant' },
  { provider: 'OpenAI', model: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo', id: 3, quality: 75, rating: 80, description: 'Fast and efficient' },
  { provider: 'Anthropic', model: 'claude-3-opus', label: 'Claude 3 Opus', id: 4, quality: 95, rating: 97, description: 'Most capable Claude model' },
  { provider: 'Anthropic', model: 'claude-3-sonnet', label: 'Claude 3 Sonnet', id: 5, quality: 85, rating: 88, description: 'Balanced performance' },
  { provider: 'Google', model: 'gemini-pro', label: 'Gemini Pro', id: 6, quality: 80, rating: 85, description: null },
]
