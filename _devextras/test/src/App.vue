<template>
  <div>
    <h1>Widget Test Page</h1>
    <p>This is a simple test page. The widget should appear in the bottom-right corner.</p>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

onMounted(() => {
  const script = document.createElement('script')
  script.src = 'http://localhost:8000/widget.js'
  script.onload = () => {
    if (typeof window.SynaplanWidget !== 'undefined') {
      SynaplanWidget.init({
    widgetId: 'wdg_b4546a675290d9fd3cb4e2efc6374c4d',
    position: 'bottom-right',
    primaryColor: '#007bff',
    iconColor: '#ffffff',
    defaultTheme: 'light',
    autoOpen: false,
    autoMessage: 'Hello! How can I help you today?',
    messageLimit: 50,
    maxFileSize: 10,
    allowFileUpload: true,
    fileUploadLimit: 3,
    apiUrl: 'http://localhost:8000'
  });
    }
  }
  document.head.appendChild(script)
})
</script>

<style scoped>
div {
  padding: 40px;
  max-width: 800px;
  margin: 0 auto;
}

h1 {
  margin-bottom: 20px;
}
</style>

