<template>
  <div class="flex h-dvh overflow-hidden" data-testid="comp-main-layout">
    <SidebarV2 />

    <div class="flex-1 flex flex-col min-w-0" data-testid="section-main-shell">
      <main
        class="flex-1 min-h-0 overflow-y-auto overscroll-contain"
        data-testid="section-primary-content"
      >
        <slot />
      </main>

      <!-- §4.3 #2: primary navigation on small screens (hidden ≥ md) -->
      <MobileNav />
    </div>

    <!-- Help system host -->
    <HelpHost />

    <!-- Native-only floating back button (iOS has no hardware back). -->
    <MobileBackButton />
  </div>
</template>

<script setup lang="ts">
import SidebarV2 from './SidebarV2.vue'
import MobileNav from './MobileNav.vue'
import HelpHost from './help/HelpHost.vue'
import MobileBackButton from './MobileBackButton.vue'
</script>
