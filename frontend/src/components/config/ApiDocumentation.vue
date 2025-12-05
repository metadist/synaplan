<template>
  <div class="space-y-6" data-testid="page-api-documentation">
    <div class="mb-8" data-testid="section-header">
      <h1 class="text-2xl font-semibold txt-primary mb-2">
        {{ $t('config.apiDocumentation.title') }}
      </h1>
      <p class="txt-secondary">
        {{ $t('config.apiDocumentation.description') }}
      </p>
    </div>

    <div v-if="loading" class="surface-card p-8 text-center">
      <p class="txt-secondary">{{ $t('config.apiDocumentation.loading') }}</p>
    </div>

    <div v-else-if="error" class="surface-card p-6">
      <p class="txt-primary text-red-500">{{ error }}</p>
    </div>

    <div v-else-if="apiSpec" class="space-y-6">
      <!-- API Info -->
      <div class="surface-card p-6">
        <h2 class="text-xl font-semibold txt-primary mb-2">{{ apiSpec.info?.title || 'API Documentation' }}</h2>
        <p class="txt-secondary mb-4">{{ apiSpec.info?.description || '' }}</p>
        <div class="flex items-center gap-4 text-sm">
          <span class="txt-secondary">Version:</span>
          <span class="pill pill--active">{{ apiSpec.info?.version || '1.0.0' }}</span>
        </div>
      </div>

      <!-- Endpoints by Tag -->
      <div v-for="tag in filteredTags" :key="tag" class="space-y-4">
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <CommandLineIcon class="w-5 h-5 text-purple-500" />
          {{ tag }}
        </h3>

        <div v-for="path in getPathsByTag(tag)" :key="path.path + path.method" class="surface-card p-6">
          <div class="flex items-start justify-between gap-4 mb-4">
            <div class="flex-1">
              <div class="flex items-center gap-3 mb-2">
                <span 
                  :class="[
                    'px-3 py-1 rounded text-xs font-semibold',
                    getMethodColorClass(path.method)
                  ]"
                >
                  {{ path.method.toUpperCase() }}
                </span>
                <code class="text-sm font-mono txt-primary">{{ path.path }}</code>
              </div>
              <p class="txt-secondary text-sm mt-2">{{ path.summary || path.description || '' }}</p>
            </div>
            <button
              @click="toggleExpand(path.path + path.method)"
              class="icon-ghost"
            >
              <component :is="isExpanded(path.path + path.method) ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4" />
            </button>
          </div>

          <!-- Expanded Details -->
          <div v-if="isExpanded(path.path + path.method)" class="mt-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20 space-y-4">
            <!-- Parameters -->
            <div v-if="path.parameters && path.parameters.length > 0">
              <h4 class="text-sm font-semibold txt-primary mb-3">Parameters</h4>
              <div class="space-y-2">
                <div
                  v-for="param in path.parameters"
                  :key="param.name"
                  class="p-3 surface-chip rounded-lg"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="flex-1">
                      <code class="text-sm font-mono txt-primary font-semibold">{{ param.name }}</code>
                      <span v-if="param.required" class="ml-2 text-xs text-red-500">required</span>
                      <p class="text-xs txt-secondary mt-1">{{ param.description || '' }}</p>
                      <span class="text-xs txt-tertiary mt-1 inline-block">
                        Type: <code>{{ getTypeString(param.schema) }}</code>
                        <span v-if="param.in"> in {{ param.in }}</span>
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Request Body -->
            <div v-if="path.requestBody">
              <h4 class="text-sm font-semibold txt-primary mb-3">{{ $t('config.apiDocumentation.requestBody') }}</h4>
              <div v-if="path.requestBody.required" class="mb-2">
                <span class="text-xs text-red-500">required</span>
              </div>
              <div v-if="path.requestBody.description" class="mb-3">
                <p class="text-sm txt-secondary">{{ path.requestBody.description }}</p>
              </div>
              <div v-if="path.requestBody.content" class="space-y-3">
                <div v-for="(content, contentType) in path.requestBody.content" :key="contentType" class="space-y-2">
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold txt-primary">Content-Type:</span>
                    <code class="text-xs font-mono txt-primary">{{ contentType }}</code>
                  </div>
                  <div v-if="content.schema" class="code-block p-4 font-mono text-xs overflow-x-auto">
                    <pre>{{ formatSchemaDetailed(content.schema, apiSpec) }}</pre>
                  </div>
                  <div v-if="content.example" class="code-block p-4 font-mono text-xs overflow-x-auto">
                    <div class="text-xs txt-secondary mb-1">Example:</div>
                    <pre>{{ JSON.stringify(content.example, null, 2) }}</pre>
                  </div>
                </div>
              </div>
            </div>

            <!-- Responses -->
            <div v-if="path.responses">
              <h4 class="text-sm font-semibold txt-primary mb-3">{{ $t('config.apiDocumentation.responses') }}</h4>
              <div class="space-y-3">
                <div
                  v-for="(response, statusCode) in path.responses"
                  :key="statusCode"
                  class="p-4 surface-chip rounded-lg"
                >
                  <div class="flex items-center gap-2 mb-3">
                    <span class="px-2 py-1 rounded text-xs font-semibold" :class="getStatusCodeColorClass(statusCode)">
                      {{ statusCode }}
                    </span>
                    <span class="text-sm txt-secondary">{{ response.description || '' }}</span>
                  </div>
                  <div v-if="response.content" class="space-y-3">
                    <div v-for="(content, contentType) in response.content" :key="contentType" class="space-y-2">
                      <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold txt-primary">Content-Type:</span>
                        <code class="text-xs font-mono txt-primary">{{ contentType }}</code>
                      </div>
                      <div v-if="content.schema" class="code-block p-4 font-mono text-xs overflow-x-auto">
                        <pre>{{ formatSchemaDetailed(content.schema, apiSpec) }}</pre>
                      </div>
                      <div v-if="content.example" class="code-block p-4 font-mono text-xs overflow-x-auto">
                        <div class="text-xs txt-secondary mb-1">Example:</div>
                        <pre>{{ JSON.stringify(content.example, null, 2) }}</pre>
                      </div>
                    </div>
                  </div>
                  <div v-if="response.headers" class="mt-3">
                    <div class="text-xs font-semibold txt-primary mb-2">Headers:</div>
                    <div class="space-y-1">
                      <div v-for="(header, headerName) in response.headers" :key="headerName" class="text-xs">
                        <code class="font-mono txt-primary">{{ headerName }}</code>
                        <span class="txt-secondary ml-2">{{ header.description || '' }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Security -->
            <div v-if="path.security && path.security.length > 0">
              <h4 class="text-sm font-semibold txt-primary mb-3">{{ $t('config.apiDocumentation.authentication') }}</h4>
              <div class="p-3 surface-chip rounded-lg space-y-2">
                <p class="text-sm txt-secondary">
                  This endpoint requires authentication:
                </p>
                <div class="flex flex-wrap gap-2">
                  <span v-for="(sec, idx) in path.security" :key="idx">
                    <span v-for="(_, name) in sec" :key="name" class="pill pill--active text-xs">
                      {{ name }}
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import {
  CommandLineIcon,
  ChevronDownIcon,
  ChevronUpIcon
} from '@heroicons/vue/24/outline'
import { useConfigStore } from '@/stores/config'

interface OpenAPISpec {
  info?: {
    title?: string
    description?: string
    version?: string
  }
  paths?: Record<string, Record<string, any>>
  tags?: Array<{ name: string; description?: string }>
  components?: {
    schemas?: Record<string, any>
    securitySchemes?: Record<string, any>
  }
}

interface EndpointPath {
  path: string
  method: string
  summary?: string
  description?: string
  parameters?: any[]
  requestBody?: any
  responses?: Record<string, any>
  security?: any[]
  tags?: string[]
  expandable?: boolean
}

const loading = ref(true)
const error = ref<string | null>(null)
const apiSpec = ref<OpenAPISpec | null>(null)
const expandedEndpoints = ref<Set<string>>(new Set())

// Check if we're in development mode
const isDevelopment = computed(() => {
  const env = import.meta.env as any
  return env.DEV || env.MODE === 'development'
})

// Filter out authentication-related endpoints (only in production)
const excludedPaths = computed(() => {
  if (isDevelopment.value) {
    // In dev mode, show everything
    return []
  }
  // In production, exclude auth endpoints
  return [
    '/api/v1/auth/login',
    '/api/v1/auth/register',
    '/api/v1/auth/forgot-password',
    '/api/v1/auth/reset-password',
    '/api/v1/auth/verify-email',
    '/api/v1/auth/refresh'
  ]
})

const excludedTags = computed(() => {
  if (isDevelopment.value) {
    // In dev mode, show all tags
    return []
  }
  // In production, exclude Authentication tag
  return ['Authentication']
})

const loadApiSpec = async () => {
  try {
    loading.value = true
    error.value = null

    // Fetch OpenAPI JSON from backend
    const config = useConfigStore()
    const response = await fetch(`${config.apiBaseUrl}/doc.json`)
    
    if (!response.ok) {
      throw new Error(`Failed to load API documentation: ${response.statusText}`)
    }
    
    const spec = await response.json()
    
    // Filter out excluded paths
    if (spec.paths) {
      const filteredPaths: Record<string, any> = {}
      for (const [path, methods] of Object.entries(spec.paths)) {
        if (!excludedPaths.value.some((excluded: string) => path.startsWith(excluded))) {
          filteredPaths[path] = methods
        }
      }
      spec.paths = filteredPaths
    }
    
    apiSpec.value = spec
  } catch (err: any) {
    console.error('Failed to load API spec:', err)
    error.value = err.message || 'Failed to load API documentation'
  } finally {
    loading.value = false
  }
}

const filteredTags = computed(() => {
  if (!apiSpec.value?.paths) return []
  
  const tagSet = new Set<string>()
  
  for (const methods of Object.values(apiSpec.value.paths)) {
    for (const endpoint of Object.values(methods as Record<string, any>)) {
      if (endpoint.tags) {
      for (const tag of endpoint.tags) {
        if (!excludedTags.value.includes(tag)) {
          tagSet.add(tag)
        }
      }
      }
    }
  }
  
  return Array.from(tagSet).sort()
})

const getPathsByTag = (tag: string): EndpointPath[] => {
  if (!apiSpec.value?.paths) return []
  
  const endpoints: EndpointPath[] = []
  
  for (const [path, methods] of Object.entries(apiSpec.value.paths)) {
    for (const [method, endpoint] of Object.entries(methods as Record<string, any>)) {
      if (endpoint.tags && endpoint.tags.includes(tag)) {
        endpoints.push({
          path,
          method,
          summary: endpoint.summary,
          description: endpoint.description,
          parameters: endpoint.parameters,
          requestBody: endpoint.requestBody,
          responses: endpoint.responses,
          security: endpoint.security,
          tags: endpoint.tags,
          expandable: true // Always expandable
        })
      }
    }
  }
  
  return endpoints.sort((a, b) => {
    const methodOrder: Record<string, number> = { get: 1, post: 2, put: 3, patch: 4, delete: 5 }
    return (methodOrder[a.method.toLowerCase()] || 99) - (methodOrder[b.method.toLowerCase()] || 99)
  })
}

const toggleExpand = (key: string) => {
  if (expandedEndpoints.value.has(key)) {
    expandedEndpoints.value.delete(key)
  } else {
    expandedEndpoints.value.add(key)
  }
}

const isExpanded = (key: string): boolean => {
  return expandedEndpoints.value.has(key)
}

const getMethodColorClass = (method: string): string => {
  const colors: Record<string, string> = {
    get: 'bg-blue-500 text-white',
    post: 'bg-green-500 text-white',
    put: 'bg-yellow-500 text-white',
    patch: 'bg-orange-500 text-white',
    delete: 'bg-red-500 text-white'
  }
  return colors[method.toLowerCase()] || 'bg-gray-500 text-white'
}

const getStatusCodeColorClass = (statusCode: string): string => {
  const code = parseInt(statusCode)
  if (code >= 200 && code < 300) return 'bg-green-500 text-white'
  if (code >= 300 && code < 400) return 'bg-blue-500 text-white'
  if (code >= 400 && code < 500) return 'bg-yellow-500 text-white'
  if (code >= 500) return 'bg-red-500 text-white'
  return 'bg-gray-500 text-white'
}

const getTypeString = (schema: any): string => {
  if (!schema) return 'unknown'
  if (schema.type) {
    if (schema.type === 'array' && schema.items) {
      return `array<${getTypeString(schema.items)}>`
    }
    return schema.type
  }
  if (schema.$ref) {
    return schema.$ref.split('/').pop() || 'object'
  }
  return 'object'
}

const formatSchema = (schema: any): any => {
  if (!schema) return {}
  
  if (schema.type === 'object' && schema.properties) {
    const obj: any = {}
    for (const [key, prop] of Object.entries(schema.properties as Record<string, any>)) {
      obj[key] = formatSchema(prop)
    }
    return obj
  }
  
  if (schema.type === 'array' && schema.items) {
    return [formatSchema(schema.items)]
  }
  
  if (schema.example !== undefined) {
    return schema.example
  }
  
  if (schema.type === 'string') return 'string'
  if (schema.type === 'number') return 0
  if (schema.type === 'integer') return 0
  if (schema.type === 'boolean') return true
  if (schema.type === 'array') return []
  
  return {}
}

const formatSchemaDetailed = (schema: any, apiSpec: OpenAPISpec | null): string => {
  if (!schema) return '{}'
  
  // Handle $ref references
  if (schema.$ref) {
    const refPath = schema.$ref.replace('#/components/schemas/', '')
    if (apiSpec?.components?.schemas?.[refPath]) {
      return formatSchemaDetailed(apiSpec.components.schemas[refPath], apiSpec)
    }
    return `Reference: ${refPath}`
  }
  
  // Handle allOf, anyOf, oneOf
  if (schema.allOf) {
    const combined = schema.allOf.map((s: any) => formatSchemaDetailed(s, apiSpec))
    return combined.join('\n\n--- Combined ---\n\n')
  }
  
  if (schema.anyOf || schema.oneOf) {
    const options = (schema.anyOf || schema.oneOf).map((s: any) => formatSchemaDetailed(s, apiSpec))
    return options.map((opt: string, idx: number) => `Option ${idx + 1}:\n${opt}`).join('\n\n--- OR ---\n\n')
  }
  
  // Handle object with properties
  if (schema.type === 'object' || schema.properties) {
    const properties = schema.properties || {}
    const required = schema.required || []
    const result: any = {}
    
    for (const [key, prop] of Object.entries(properties as Record<string, any>)) {
      const propSchema = prop as any
      let value: any
      
      if (propSchema.$ref) {
        value = `Reference: ${propSchema.$ref.replace('#/components/schemas/', '')}`
      } else if (propSchema.type === 'object' && propSchema.properties) {
        value = formatSchemaDetailed(propSchema, apiSpec)
      } else if (propSchema.type === 'array') {
        value = propSchema.items ? [formatSchemaDetailed(propSchema.items, apiSpec)] : []
      } else if (propSchema.example !== undefined) {
        value = propSchema.example
      } else if (propSchema.type === 'string') {
        value = propSchema.enum ? `enum: ${propSchema.enum.join(' | ')}` : 'string'
      } else if (propSchema.type === 'number' || propSchema.type === 'integer') {
        value = propSchema.example !== undefined ? propSchema.example : 0
      } else if (propSchema.type === 'boolean') {
        value = propSchema.example !== undefined ? propSchema.example : true
      } else {
        value = propSchema.type || 'any'
      }
      
      result[key] = value
      if (required.includes(key)) {
        result[`${key} (required)`] = result[key]
        delete result[key]
      }
    }
    
    return JSON.stringify(result, null, 2)
  }
  
  // Handle array
  if (schema.type === 'array' && schema.items) {
    return JSON.stringify([formatSchemaDetailed(schema.items, apiSpec)], null, 2)
  }
  
  // Handle simple types
  if (schema.example !== undefined) {
    return JSON.stringify(schema.example, null, 2)
  }
  
  if (schema.type === 'string') {
    if (schema.enum) {
      return `enum: ${schema.enum.join(' | ')}`
    }
    return 'string'
  }
  
  if (schema.type === 'number' || schema.type === 'integer') {
    return schema.example !== undefined ? String(schema.example) : '0'
  }
  
  if (schema.type === 'boolean') {
    return schema.example !== undefined ? String(schema.example) : 'true'
  }
  
  return JSON.stringify({}, null, 2)
}

onMounted(() => {
  loadApiSpec()
})
</script>

