<template>
  <div>
    <h1>Test Page</h1>
    <p>Simple test page. Widget should appear in bottom-right corner.</p>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

onMounted(() => {
  // Widget wird über index.html geladen
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

