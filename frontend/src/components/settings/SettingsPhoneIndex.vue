<template>
  <nav class="space-y-3" :aria-label="$t('settings.title')" data-testid="nav-phone-settings">
    <div class="surface-card flex flex-col gap-0.5 p-2">
      <SidebarNavLink
        v-for="item in preferenceLinks"
        :key="item.slug"
        :to="item.to"
        :label="item.label"
        :icon="item.icon"
        :active="false"
        :test-id="`link-phone-settings-${item.slug}`"
      />
    </div>

    <div v-if="deleteLink" class="surface-card flex flex-col gap-0.5 p-2">
      <SidebarNavLink
        :to="deleteLink.to"
        :label="deleteLink.label"
        :icon="deleteLink.icon"
        danger
        :active="false"
        test-id="link-phone-settings-delete"
      />
    </div>
  </nav>
</template>

<script setup lang="ts">
import SidebarNavLink from '@/components/sidebar/SidebarNavLink.vue'
import { useSettingsSectionLinks } from '@/composables/useSettingsSections'

const { preferenceLinks, deleteLink } = useSettingsSectionLinks()
</script>
