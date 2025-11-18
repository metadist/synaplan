export interface ChatResponse {
  success: boolean
  messageId: number
  responseId: number
  response: string
  metadata: {
    provider: string
    model: string
    tokens?: {
      prompt: number
      completion: number
      total: number
    }
  }
}

const imageResponses = [
  'https://picsum.photos/800/600?random=1',
  'https://picsum.photos/800/600?random=2',
  'https://picsum.photos/800/600?random=3',
]

const responses: Record<string, string> = {
  default: 'I can help you with that! What would you like to know?',
  hello: 'Hello! How can I assist you today?',
  image: `Here's the image you requested:\n\n![Generated Image](${imageResponses[0]})`,
  code: '```javascript\nfunction hello() {\n  console.log("Hello World!");\n}\n```',
  long: `This is a longer response that demonstrates multi-paragraph content.\n\nI can help with various tasks including:\n- Answering questions\n- Generating code\n- Creating images\n- Analyzing files\n\nWhat would you like me to help you with?`,
}

export function mockChatResponse(message: string): ChatResponse {
  let response = responses.default
  const lowerMsg = message.toLowerCase()
  
  if (lowerMsg.includes('hello') || lowerMsg.includes('hi')) {
    response = responses.hello
  } else if (lowerMsg.includes('image') || lowerMsg.includes('picture') || lowerMsg.includes('bild')) {
    response = responses.image
  } else if (lowerMsg.includes('code') || lowerMsg.includes('function')) {
    response = responses.code
  } else if (message.length > 100) {
    response = responses.long
  }

  return {
    success: true,
    messageId: Math.floor(Math.random() * 10000),
    responseId: Math.floor(Math.random() * 10000),
    response,
    metadata: {
      provider: 'test',
      model: 'test-model-1.0',
      tokens: {
        prompt: Math.floor(message.length / 4),
        completion: Math.floor(response.length / 4),
        total: Math.floor((message.length + response.length) / 4)
      }
    }
  }
}

export function mockStreamingResponse(message: string, callback: (data: any) => void) {
  const steps = [
    { status: 'created', messageId: 12345, timestamp: Date.now() },
    { status: 'pre_processing', message: 'Processing message...', timestamp: Date.now() },
    { status: 'classified', classification: { topic: 'GENERAL', language: 'EN', intent: 'chat' }, timestamp: Date.now() },
    { status: 'generating', message: 'Generating response...', timestamp: Date.now() },
  ]

  const fullResponse = mockChatResponse(message)
  
  let index = 0
  const interval = setInterval(() => {
    if (index < steps.length) {
      callback(steps[index])
      index++
    } else {
      clearInterval(interval)
      callback({
        status: 'complete',
        ...fullResponse,
        timestamp: Date.now()
      })
    }
  }, 200)
}

