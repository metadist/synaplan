/**
 * Test Data
 *
 * Centralized test texts, prompts, and test data.
 */

// Test prompts
export const PROMPTS = {
  SMOKE_TEST: 'Ai, this is a smoke test. Answer with "success" add nothing else',
  CHAT_SMOKE: 'Ai, this is a smoke test. Reply with one short sentence.',
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
  LAZY_LOAD: true,
  AUTO_OPEN: false,
  ALLOW_FILE_UPLOAD: false,
  IS_ACTIVE: true,
  AUTO_MESSAGE: 'Hello! How can I help you today?',
  MESSAGE_LIMIT: 50,
  MAX_FILE_SIZE: 10,
  FILE_UPLOAD_LIMIT: 3,
} as const

export const WIDGET_MESSAGES = {
  AUTO_MESSAGE: 'Hello! This is a test welcome message.',
  TASK_PROMPT_MESSAGE: 'Test message for task prompt',
} as const

/** Task prompt for widget test that references uploaded file content (most_important_thing.txt) */
export const WIDGET_TASK_PROMPT_KNOWLEDGE_BASE = `You are a helpful assistant.

When users ask about the most important thing in the world, you should reference the information from the uploaded files in the knowledge base. Be helpful and provide accurate answers based on the information available in the knowledge base.`

/** User question matching the task prompt test (expects answer from knowledge base) */
export const WIDGET_TASK_PROMPT_QUESTION = 'What is the most important thing in the world?'

export const WIDGET_TEST_URLS = {
  EXAMPLE_DOMAIN: 'https://example.com',
} as const

/** Paths to test fixture files (relative to e2e dir). Use with path.join(e2eDir, path). */
export const FIXTURE_PATHS = {
  VISION_PATTERN_64: 'test_data/vision-pattern-64.png',
  /** RAG smoke: upload and search with this phrase. */
  RAG_MOST_IMPORTANT: 'test_data/most_important_thing.txt',
} as const
