export interface HelpStep {
  title: string
  content: string
  selector?: string // CSS selector for element to highlight
}

export interface HelpContent {
  title: string
  description: string
  steps: HelpStep[]
}

export const helpContent: Record<string, HelpContent> = {
  'tools.introduction': {
    title: 'Available Commands',
    description: 'Learn how to use powerful commands in your chats',
    steps: [
      {
        title: 'Welcome to Commands',
        content: 'Here you can discover all available commands to enhance your chat experience.',
        selector: 'h1'
      },
      {
        title: 'Search Commands',
        content: 'Use the search bar to quickly find commands by name or description.',
        selector: 'input[type="text"]'
      },
      {
        title: 'Command Cards',
        content: 'Click any command card to expand and see detailed usage examples and parameters.',
        selector: '.surface-card'
      },
      {
        title: 'Use in Chat',
        content: 'Copy the command syntax and type it directly in your chat to activate features like /pic for images or /search for web search.'
      }
    ]
  },
  'tools.chatWidget': {
    title: 'Chat Widget Setup',
    description: 'Create a customizable chat widget for your website',
    steps: [
      {
        title: 'Create Widget',
        content: 'Click "Create New Widget" to start building your chat widget.',
        selector: '.btn-primary'
      },
      {
        title: 'Widget List',
        content: 'All your created widgets are displayed here. Click on any widget card to edit it.',
        selector: '.surface-card'
      },
      {
        title: 'Configuration Tabs',
        content: 'Use these tabs to configure Appearance, Behavior, and Advanced settings for your widget.',
        selector: '[class*="border-b"]'
      },
      {
        title: 'Save Changes',
        content: 'After customizing your widget, click Save to apply your changes.'
      }
    ]
  },
  'tools.docSummary': {
    title: 'Document Summary',
    description: 'Generate AI-powered summaries of your documents',
    steps: [
      {
        title: 'Quick Presets',
        content: 'Select Invoice, Contract, or Generic preset to automatically configure the best settings for your document type.',
        selector: '[data-help="presets"]'
      },
      {
        title: 'Drag & Drop',
        content: 'Drag and drop a PDF, DOCX, or TXT file (max 10MB) into the highlighted area.',
        selector: '[data-help="drag-drop"]'
      },
      {
        title: 'Text Input',
        content: 'Or paste your text directly into the textarea below for instant processing.',
        selector: '[data-help="textarea"]'
      },
      {
        title: 'Generate Summary',
        content: 'Click "Generate Summary" to process your document with AI. Processing may take a few seconds depending on document size.',
        selector: '[data-help="generate-btn"]'
      }
    ]
  },
  'tools.mailHandler': {
    title: 'Mail Handler Setup',
    description: 'Configure automatic email processing and routing',
    steps: [
      {
        title: 'Wizard Steps',
        content: 'The setup is divided into 3 easy steps. Follow the progress bar at the top to track your configuration.',
        selector: '.surface-card:first-of-type'
      },
      {
        title: 'Step 1: Connection',
        content: 'Enter your mail server details (IMAP/POP3), port, username, and password. All fields are required to proceed to the next step.',
        selector: 'input[type="text"]:first-of-type'
      },
      {
        title: 'Step 2: Departments',
        content: 'Add email addresses for routing. At least one department must be set as default. Click "Add Department" to create more routing rules.',
        selector: 'button:has-text("Add")'
      },
      {
        title: 'Step 3: Test & Save',
        content: 'Review your configuration summary and run a connection test. Once successful, click "Save Configuration" to complete the setup.'
      }
    ]
  }
}

