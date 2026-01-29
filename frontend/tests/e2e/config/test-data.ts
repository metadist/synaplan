/**
 * Test Data
 *
 * Centralized test texts, prompts, and test data.
 */

// Test prompts
export const PROMPTS = {
  SMOKE_TEST: 'Ai, this is a smoke test. Answer with "success" add nothing else',
  FIRST_MESSAGE: 'First message',
  SECOND_MESSAGE: 'Second message',
  CORS_TEST: 'Test CORS message',
} as const

// Widget names (with unique suffix helper)
export const WIDGET_NAMES = {
  FULL_FLOW: 'Test Widget Full Flow',
  CONFIG: 'Test Widget Config',
  NOT_WHITELISTED: 'Test Widget Not Whitelisted',

  /**
   * Generate unique widget name with timestamp and random suffix
   */
  unique(baseName: string): string {
    const suffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    return `${baseName} ${suffix}`
  },
} as const

// Default widget config values (for assertions)
// These match the backend defaults in WidgetService.php
// Tests should explicitly set values they depend on to be resilient against default changes
export const WIDGET_DEFAULTS = {
  POSITION: 'bottom-right',
  PRIMARY_COLOR: '#007bff',
  ICON_COLOR: '#ffffff',
  DEFAULT_THEME: 'light',
  AUTO_OPEN: false,
  ALLOW_FILE_UPLOAD: false,
  IS_ACTIVE: true,
  AUTO_MESSAGE: 'Hello! How can I help you today?',
  MESSAGE_LIMIT: 50,
  MAX_FILE_SIZE: 10,
  FILE_UPLOAD_LIMIT: 3,
} as const

// Widget test messages
export const WIDGET_MESSAGES = {
  AUTO_MESSAGE: 'Hello! This is a test welcome message.',
  TASK_PROMPT_MESSAGE: 'Test message for task prompt',
} as const
