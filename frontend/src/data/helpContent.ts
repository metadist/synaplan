/**
 * Help Content
 * 
 * Contains help documentation and guides for the application
 */

export interface HelpSection {
  id: string;
  title: string;
  content: string;
  category: 'general' | 'features' | 'account' | 'api' | 'troubleshooting';
}

export const helpContent: HelpSection[] = [
  // General
  {
    id: 'getting-started',
    title: 'Getting Started',
    content: `
      Welcome to Synaplan! This AI-powered platform helps you manage conversations, 
      analyze documents, and automate workflows. Start by creating a new chat or 
      uploading documents for analysis.
    `,
    category: 'general'
  },
  {
    id: 'navigation',
    title: 'Navigation',
    content: `
      Use the sidebar to access different features:
      - Chat: Start conversations with AI
      - Documents: Upload and analyze files
      - Statistics: View usage statistics
      - Settings: Configure your account
    `,
    category: 'general'
  },

  // Features
  {
    id: 'chat-basics',
    title: 'Chat Basics',
    content: `
      The chat feature allows you to have conversations with AI models.
      You can select different models, share chats, and organize conversations.
      Use @ to mention specific context or # for topics.
    `,
    category: 'features'
  },
  {
    id: 'model-selection',
    title: 'Model Selection',
    content: `
      Choose from different AI models based on your needs:
      - GPT-4: Best for complex reasoning and analysis
      - Claude: Great for detailed explanations
      - Ollama: Local, private processing
      
      Each model has different capabilities and pricing.
    `,
    category: 'features'
  },
  {
    id: 'file-upload',
    title: 'File Upload',
    content: `
      Upload documents, images, or other files to analyze them with AI.
      Supported formats: PDF, DOCX, TXT, PNG, JPG, and more.
      Maximum file size: 10MB per file.
    `,
    category: 'features'
  },
  {
    id: 'chat-sharing',
    title: 'Chat Sharing',
    content: `
      Share your conversations with others by enabling the share option.
      You'll get a public link that anyone can view (read-only).
      You can disable sharing at any time.
    `,
    category: 'features'
  },

  // Account
  {
    id: 'account-settings',
    title: 'Account Settings',
    content: `
      Manage your account settings:
      - Profile information
      - Language preferences
      - Email notifications
      - API keys for integrations
      - Usage limits and subscriptions
    `,
    category: 'account'
  },
  {
    id: 'api-keys',
    title: 'API Keys',
    content: `
      Create API keys to integrate Synaplan into your applications.
      Each key can have specific permissions (scopes) for security.
      Keep your API keys secure and don't share them publicly.
    `,
    category: 'account'
  },
  {
    id: 'usage-limits',
    title: 'Usage Limits',
    content: `
      Your account has usage limits based on your subscription tier:
      - Free: Limited messages per day
      - Pro: Higher limits and priority support
      - Enterprise: Unlimited usage
      
      Check your current usage in the Statistics page.
    `,
    category: 'account'
  },

  // API
  {
    id: 'api-documentation',
    title: 'API Documentation',
    content: `
      Access the full API documentation at /api/doc for detailed
      information about all available endpoints, authentication,
      and integration examples.
    `,
    category: 'api'
  },
  {
    id: 'webhooks',
    title: 'Webhooks',
    content: `
      Set up webhooks to receive notifications about events:
      - New messages
      - Chat completions
      - File processing status
      - Usage limit warnings
    `,
    category: 'api'
  },

  // Troubleshooting
  {
    id: 'common-issues',
    title: 'Common Issues',
    content: `
      If you encounter problems:
      1. Clear your browser cache
      2. Check your internet connection
      3. Verify your API keys are valid
      4. Check usage limits haven't been exceeded
      5. Contact support if the issue persists
    `,
    category: 'troubleshooting'
  },
  {
    id: 'rate-limits',
    title: 'Rate Limits',
    content: `
      If you see "Rate limit exceeded" errors:
      - Wait for the limit to reset (shown in the error)
      - Upgrade your plan for higher limits
      - Optimize your API usage
      - Implement caching in your application
    `,
    category: 'troubleshooting'
  },
  {
    id: 'error-messages',
    title: 'Error Messages',
    content: `
      Common error messages and solutions:
      - 401 Unauthorized: Check your authentication
      - 429 Too Many Requests: Rate limit reached
      - 500 Server Error: Try again or contact support
      - 404 Not Found: Check the resource URL
    `,
    category: 'troubleshooting'
  }
];

/**
 * Get help content by ID
 */
export function getHelpById(id: string): HelpSection | undefined {
  return helpContent.find(section => section.id === id);
}

/**
 * Get help content by category
 */
export function getHelpByCategory(category: HelpSection['category']): HelpSection[] {
  return helpContent.filter(section => section.category === category);
}

/**
 * Search help content
 */
export function searchHelp(query: string): HelpSection[] {
  const lowercaseQuery = query.toLowerCase();
  return helpContent.filter(section => 
    section.title.toLowerCase().includes(lowercaseQuery) ||
    section.content.toLowerCase().includes(lowercaseQuery)
  );
}

/**
 * Get all categories
 */
export function getHelpCategories(): Array<{ key: HelpSection['category']; label: string }> {
  return [
    { key: 'general', label: 'General' },
    { key: 'features', label: 'Features' },
    { key: 'account', label: 'Account' },
    { key: 'api', label: 'API' },
    { key: 'troubleshooting', label: 'Troubleshooting' }
  ];
}
