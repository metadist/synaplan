<template>
  <div
    class="my-3 surface-card border border-light-border/30 dark:border-dark-border/20 overflow-hidden"
    data-testid="comp-message-code"
  >
    <div
      class="flex items-center justify-between px-4 py-2.5 border-b border-light-border/30 dark:border-dark-border/20 bg-black/5 dark:bg-white/5"
    >
      <div class="flex items-center gap-2">
        <span v-if="language" class="text-xs font-semibold txt-primary uppercase tracking-wide">{{
          language
        }}</span>
        <span v-if="filename" class="text-xs txt-secondary">{{ filename }}</span>
      </div>
      <button
        class="text-xs px-3 py-1.5 rounded-lg hover-surface transition-all txt-secondary font-medium flex items-center gap-1.5"
        :aria-label="$t('commands.copyCode')"
        data-testid="btn-copy-code"
        @click="copyCode"
      >
        <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
          />
        </svg>
        <svg
          v-else
          class="w-4 h-4 text-green-500"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M5 13l4 4L19 7"
          />
        </svg>
        {{ copied ? $t('commands.copied') : $t('commands.copy') }}
      </button>
    </div>
    <pre
      class="p-4 overflow-x-auto text-sm scroll-thin"
    ><code ref="codeRef" class="hljs font-mono" v-html="highlightedCode"></code></pre>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import hljs from 'highlight.js/lib/core'
import javascript from 'highlight.js/lib/languages/javascript'
import typescript from 'highlight.js/lib/languages/typescript'
import python from 'highlight.js/lib/languages/python'
import java from 'highlight.js/lib/languages/java'
import cpp from 'highlight.js/lib/languages/cpp'
import csharp from 'highlight.js/lib/languages/csharp'
import php from 'highlight.js/lib/languages/php'
import ruby from 'highlight.js/lib/languages/ruby'
import go from 'highlight.js/lib/languages/go'
import rust from 'highlight.js/lib/languages/rust'
import sql from 'highlight.js/lib/languages/sql'
import bash from 'highlight.js/lib/languages/bash'
import json from 'highlight.js/lib/languages/json'
import xml from 'highlight.js/lib/languages/xml'
import css from 'highlight.js/lib/languages/css'
import yaml from 'highlight.js/lib/languages/yaml'
import markdown from 'highlight.js/lib/languages/markdown'
import 'highlight.js/styles/atom-one-dark.css'

hljs.registerLanguage('javascript', javascript)
hljs.registerLanguage('typescript', typescript)
hljs.registerLanguage('python', python)
hljs.registerLanguage('java', java)
hljs.registerLanguage('cpp', cpp)
hljs.registerLanguage('c++', cpp)
hljs.registerLanguage('csharp', csharp)
hljs.registerLanguage('c#', csharp)
hljs.registerLanguage('php', php)
hljs.registerLanguage('ruby', ruby)
hljs.registerLanguage('go', go)
hljs.registerLanguage('rust', rust)
hljs.registerLanguage('sql', sql)
hljs.registerLanguage('bash', bash)
hljs.registerLanguage('shell', bash)
hljs.registerLanguage('json', json)
hljs.registerLanguage('xml', xml)
hljs.registerLanguage('html', xml)
hljs.registerLanguage('css', css)
hljs.registerLanguage('yaml', yaml)
hljs.registerLanguage('yml', yaml)
hljs.registerLanguage('markdown', markdown)
hljs.registerLanguage('md', markdown)

interface Props {
  content: string
  language?: string
  filename?: string
}

const props = defineProps<Props>()

const copied = ref(false)
const codeRef = ref<HTMLElement | null>(null)

const highlightedCode = computed(() => {
  if (props.language) {
    try {
      const result = hljs.highlight(props.content, {
        language: props.language.toLowerCase(),
        ignoreIllegals: true,
      })
      return result.value
    } catch (e) {
      return hljs.highlightAuto(props.content).value
    }
  }
  return hljs.highlightAuto(props.content).value
})

const copyCode = async () => {
  try {
    await navigator.clipboard.writeText(props.content)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}
</script>

<style scoped>
:deep(.hljs) {
  background: transparent !important;
  padding: 0;
}
</style>
