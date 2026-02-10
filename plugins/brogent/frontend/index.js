/**
 * BroGent Plugin Frontend
 *
 * This file registers the plugin's UI components with Synaplan's frontend.
 * Currently a placeholder - full Vue components will be added.
 */

export default {
  name: 'brogent',
  displayName: 'BroGent Browser Agent',

  /**
   * Initialize the plugin frontend
   */
  init(app, store) {
    console.log('[BroGent] Plugin frontend initialized')
  },

  /**
   * Plugin menu items (shown in sidebar when plugin is enabled)
   */
  menuItems: [
    {
      label: 'Browser Agent',
      icon: 'mdi-robot',
      path: '/plugins/brogent',
      children: [
        {
          label: 'Devices',
          path: '/plugins/brogent/devices',
          icon: 'mdi-devices',
        },
        {
          label: 'Tasks',
          path: '/plugins/brogent/tasks',
          icon: 'mdi-format-list-checks',
        },
        {
          label: 'Runs',
          path: '/plugins/brogent/runs',
          icon: 'mdi-play-circle-outline',
        },
      ],
    },
  ],

  /**
   * Plugin routes
   */
  routes: [
    {
      path: '/plugins/brogent',
      component: () => import('./views/BrogentDashboard.vue'),
      meta: { title: 'Browser Agent' },
    },
    {
      path: '/plugins/brogent/devices',
      component: () => import('./views/BrogentDevices.vue'),
      meta: { title: 'Paired Devices' },
    },
    {
      path: '/plugins/brogent/tasks',
      component: () => import('./views/BrogentTasks.vue'),
      meta: { title: 'Tasks' },
    },
    {
      path: '/plugins/brogent/runs',
      component: () => import('./views/BrogentRuns.vue'),
      meta: { title: 'Runs' },
    },
  ],
}
