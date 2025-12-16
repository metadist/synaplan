export interface TaskPrompt {
  id: string
  name: string
  description: string
  rules: string
  aiModel: string
  availableTools: AvailableTool[]
  content: string
  isDefault: boolean
}

export type AvailableTool =
  | 'internet-search'
  | 'files-search'
  | 'url-screenshot'
  | 'image-generation'
  | 'video-generation'

export const mockTaskPrompts: TaskPrompt[] = [
  {
    id: 'default-mediamaker',
    name: '(default) mediamaker - The user asks for generation of ...',
    description:
      'The user asks for generation of images, videos or sounds (or just one). Not for any other file types. The user wants an image, video or an audio file. Direct the request here. This handles the connection to media generation AIs.',
    rules:
      'The user asks for generation of images, videos or sounds (or just one). Not for any other file types. The user wants an image, video or an audio file. Direct the request here. This handles the connection to media generation AIs.',
    aiModel: 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
    availableTools: ['internet-search'],
    content: `# Media generation
You receive a media generation request in a JSON object. The user has requested to generation of an image, video or an audio file.

The incoming object does look like:

\`\`\`json
{
  "BDATETIME": "20250314182858",
  "BFILEPATH": "",
  "BTOPIC": "mediamaker"
}
\`\`\``,
    isDefault: true,
  },
  {
    id: 'default-general',
    name: '(default) general - All requests by users go here by...',
    description: 'General chat prompt for all standard user requests',
    rules: 'All requests by users go here by default unless specific conditions are met',
    aiModel: 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
    availableTools: ['internet-search', 'files-search', 'url-screenshot'],
    content: `# General Assistant
You are a helpful AI assistant. Respond to user queries professionally and accurately.`,
    isDefault: true,
  },
  {
    id: 'custom-analyzefile',
    name: '(custom) analyzefile - The user asks to analyze any typ...',
    description: 'File analysis prompt',
    rules: 'The user asks to analyze any type of file',
    aiModel: 'Claude Sonnet 4 (Anthropic)',
    availableTools: ['files-search'],
    content: `# File Analysis
Analyze the provided file and give detailed insights.`,
    isDefault: false,
  },
  {
    id: 'default-officemaker',
    name: '(default) officemaker - The user asks for the generation...',
    description: 'Office document generation',
    rules: 'The user asks for the generation of office documents',
    aiModel: 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
    availableTools: [],
    content: `# Office Document Generation
Generate professional office documents based on user requirements.`,
    isDefault: true,
  },
]

export const availableToolsList = [
  { value: 'internet-search', label: 'Internet Search' },
  { value: 'files-search', label: 'Files Search' },
  { value: 'url-screenshot', label: 'URL Screenshot' },
  { value: 'image-generation', label: 'Image Generation' },
  { value: 'video-generation', label: 'Video Generation' },
]
