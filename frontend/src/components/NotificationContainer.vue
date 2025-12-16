<template>
  <Teleport to="body">
    <div
      class="fixed top-4 right-4 z-[9999] flex flex-col gap-3 pointer-events-none"
      data-testid="comp-notification-container"
    >
      <TransitionGroup
        name="notification"
        tag="div"
        class="flex flex-col gap-3"
        data-testid="section-notification-list"
      >
        <div
          v-for="notification in notifications"
          :key="notification.id"
          class="pointer-events-auto"
        >
          <NotificationItem :notification="notification" @close="remove(notification.id)" />
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import NotificationItem from '@/components/NotificationItem.vue'
import { useNotification } from '@/composables/useNotification'

const { notifications, remove } = useNotification()
</script>

<style scoped>
.notification-enter-active {
  transition: all 0.3s ease-out;
}

.notification-leave-active {
  transition: all 0.2s ease-in;
}

.notification-enter-from {
  opacity: 0;
  transform: translateX(100px);
}

.notification-leave-to {
  opacity: 0;
  transform: translateX(100px) scale(0.9);
}

.notification-move {
  transition: transform 0.3s ease;
}
</style>
