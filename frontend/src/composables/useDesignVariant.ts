import { ref, watchEffect, computed } from 'vue'

export type DesignVariant = 'v1' | 'v2'

const variant = ref<DesignVariant>(
  (localStorage.getItem('design-variant') as DesignVariant) || 'v1'
)

watchEffect(() => {
  document.documentElement.classList.toggle('design-v2', variant.value === 'v2')
  localStorage.setItem('design-variant', variant.value)
})

export function useDesignVariant() {
  const isV2 = computed(() => variant.value === 'v2')

  return {
    variant,
    isV2,
    setVariant: (v: DesignVariant) => (variant.value = v),
    toggleVariant: () => (variant.value = variant.value === 'v1' ? 'v2' : 'v1'),
  }
}
