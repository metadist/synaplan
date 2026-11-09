<template>
  <p
    class="mt-[21px] -mb-[3px] sm:mt-6 sm:mb-0 text-center text-sm txt-secondary"
    data-testid="login-registration-closed"
  >
    {{ paused ? $t('auth.signupBlocked.paused') : $t('auth.registrationClosed') }}
  </p>
</template>

<script setup lang="ts">
defineProps<{ paused?: boolean }>()
</script>
