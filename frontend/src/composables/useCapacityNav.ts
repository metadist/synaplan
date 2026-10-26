import type { Component } from 'vue'
import { ServerStackIcon } from '@heroicons/vue/24/outline'
import { isModuleExplicitlyConfigured } from './useModuleFeature'

interface CapacityNavEntry {
  key: string
  path: string
  label: string
  icon: Component
}

/**
 * The Operate entry for Admin › System › Capacity (architecture update AU7).
 * Present only while the backend reports the capacity module as configured,
 * so an installation with the watchdog off never shows a control that can
 * only fail (UX contract U11). Empty list otherwise, for spreading.
 */
export function capacityNavEntries(label: string): CapacityNavEntry[] {
  if (!isModuleExplicitlyConfigured('capacity')) {
    return []
  }
  return [{ key: 'admin-capacity', path: '/admin/capacity', label, icon: ServerStackIcon }]
}
