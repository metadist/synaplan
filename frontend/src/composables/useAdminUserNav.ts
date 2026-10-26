import type { Component } from 'vue'
import { ChartBarIcon, UsersIcon } from '@heroicons/vue/24/outline'

interface AdminUserNavEntry {
  key: string
  path: string
  label: string
  icon: Component
}

/**
 * The two admin user lists in Operate — People (accounts) and Usage (the
 * cost board) — in a module of their own like the capacity entry, so the
 * nav builder does not grow with them.
 */
export function adminUserNavEntries(labels: {
  people: string
  usage: string
}): AdminUserNavEntry[] {
  return [
    { key: 'admin-people', path: '/admin/people', label: labels.people, icon: UsersIcon },
    { key: 'admin-usage', path: '/admin/usage', label: labels.usage, icon: ChartBarIcon },
  ]
}
