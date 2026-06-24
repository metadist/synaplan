# Frontend Bundle Splitting Strategy

## Overview

Analysis of the current frontend build reveals a large monolithic bundle. This document outlines a strategy to split the application into logical chunks to improve load times, caching, and performance.

## Current State Analysis

The following dependencies are the primary contributors to the large bundle size:

1.  **`highlight.js`**: Used for syntax highlighting in Markdown and code blocks. It includes definitions for many languages and is currently statically imported, making it a significant part of the main bundle.
2.  **`chart.js` / `vue-chartjs`**: Used only in Admin and Statistics views (`RegistrationChart.vue`, `UsageChart.vue`).
3.  **`three`**: A large 3D library used exclusively in `MemoryGraph3DView.vue`.
4.  **`mermaid` & `katex`**: These are already dynamically imported in composables (`useMarkdownMermaid.ts`, `useMarkdownKatex.ts`), which is good practice.

## Proposed Splitting Strategy

We will utilize Vite's `manualChunks` configuration to separate these dependencies into distinct cacheable files.

### 1. Vendor Core
**Libraries**: `vue`, `vue-router`, `pinia`, `vue-i18n`, `zod`
**Purpose**: These are the fundamental frameworks of the application. They change infrequently. Separating them ensures that updates to application code do not invalidate the cache for these core libraries.

### 2. Markdown & Highlighting
**Libraries**: `marked`, `dompurify`, `highlight.js`
**Purpose**: These are heavy text-processing libraries used primarily in the Chat interface. Grouping them keeps the main bundle lighter for pages that don't require extensive markdown rendering (like Login or simple dashboards).

### 3. Charts
**Libraries**: `chart.js`, `vue-chartjs`
**Purpose**: These are only needed for specific Admin and Statistics pages. Regular users (non-admins) or users just chatting should not need to download this code.

### 4. 3D Graphics
**Libraries**: `three`
**Purpose**: Used solely for the Memory Graph visualization. This is a very large library and should definitely be lazy-loaded.

### 5. Icons
**Libraries**: `@heroicons/vue`, `@iconify/vue`, `lucide-vue-next`
**Purpose**: Shared UI assets that can be cached separately.

## Implementation Details

Modify `frontend/vite.config.ts` to include the following `rollupOptions`:

```typescript
// frontend/vite.config.ts

export default defineConfig(({ mode }) => {
  // ... existing config ...

  return {
    // ... existing config ...
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      rollupOptions: {
        output: {
          manualChunks: {
            'vendor-core': ['vue', 'vue-router', 'pinia', 'vue-i18n', 'zod'],
            'vendor-markdown': ['marked', 'dompurify', 'highlight.js'],
            'vendor-charts': ['chart.js', 'vue-chartjs'],
            'vendor-three': ['three'],
            'vendor-icons': ['@heroicons/vue', '@iconify/vue', 'lucide-vue-next'],
          },
        },
      },
    },
    // ...
  }
})
```

## Future Optimizations

-   **Dynamic Import for Highlight.js**: Refactor `useMarkdown.ts` and `MessageCode.vue` to import `highlight.js` dynamically (similar to how Mermaid is handled). This would move `highlight.js` out of the `vendor-markdown` chunk and load it only when a code block is actually detected.
-   **Route-Based Splitting**: Vue Router already supports lazy loading of components (which is being used). Ensuring that heavy components like `MemoryGraph3DView` are only imported asynchronously will naturally leverage the `vendor-three` chunking.
