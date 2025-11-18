import type { Part } from '@/stores/history'
import { commandsData } from '@/stores/commands'
import { parseCommand, normalizeUrl } from './parse'
import { mockImages, mockVideo, mockLinks, mockDocs, mockCodeSamples, mockTranslations } from '@/mocks/data'

function delay(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms))
}

function randomDelay(): Promise<void> {
  return delay(300 + Math.random() * 300)
}

// Streaming helper für Text-Antworten
export async function* streamText(text: string, charsPerChunk: number = 3): AsyncGenerator<string> {
  let currentText = ''
  for (let i = 0; i < text.length; i += charsPerChunk) {
    currentText += text.slice(i, i + charsPerChunk)
    yield currentText
    await delay(20 + Math.random() * 20)
  }
  return currentText
}

async function executeList(): Promise<Part[]> {
  await randomDelay()
  return [{
    type: 'commandList',
    items: commandsData.map(c => ({
      title: c.name,
      url: c.usage,
      desc: c.description,
    })),
  }]
}

async function executePic(args: string[]): Promise<Part[]> {
  await randomDelay()
  const description = args.join(' ')
  const image = mockImages[Math.floor(Math.random() * mockImages.length)]
  return [
    { type: 'text', content: `Generated image for: "${description}"` },
    { type: 'image', url: image.url, alt: description || image.alt },
  ]
}

async function executeVid(args: string[]): Promise<Part[]> {
  await randomDelay()
  const description = args.join(' ')
  return [
    { type: 'text', content: `Generated video for: "${description}"` },
    { type: 'video', url: mockVideo.url, poster: mockVideo.poster },
  ]
}

async function executeSearch(args: string[]): Promise<Part[]> {
  await randomDelay()
  const query = args.join(' ')
  return [
    { type: 'text', content: `Search results for: "${query}"` },
    { type: 'links', items: mockLinks },
  ]
}

async function executeLang(args: string[]): Promise<Part[]> {
  await randomDelay()
  const [langCode, ...textParts] = args
  const text = textParts.join(' ')
  const lang = langCode.toLowerCase()

  const translations = mockTranslations[lang] || {}
  const result = translations[text.toLowerCase()] || `[Mock translation to ${lang}]: ${text}`

  return [{
    type: 'translation',
    lang,
    content: text,
    result,
  }]
}

async function executeWeb(args: string[]): Promise<Part[]> {
  await randomDelay()
  const url = normalizeUrl(args[0])
  const screenshot = mockImages[0]

  return [
    { type: 'text', content: `Screenshot captured from: ${url}` },
    { type: 'screenshot', url, imageUrl: screenshot.url },
  ]
}

async function executeDocs(args: string[]): Promise<Part[]> {
  await randomDelay()
  const query = args.join(' ')
  return [
    { type: 'text', content: `Documentation search results for: "${query}"` },
    { type: 'docs', matches: mockDocs },
  ]
}

async function executeLink(): Promise<Part[]> {
  await randomDelay()
  const expiresAt = new Date(Date.now() + 3600000).toISOString()
  return [{
    type: 'link',
    url: 'https://example.com/login/abc123def456',
    expiresAt,
  }]
}

async function executeTestPic(): Promise<Part[]> {
  await randomDelay()
  return [
    { type: 'image', url: mockImages[1].url, alt: mockImages[1].alt },
    { type: 'text', content: 'This is a test image with a caption below.' },
  ]
}

async function executeTestVideo(): Promise<Part[]> {
  await randomDelay()
  return [
    { type: 'text', content: 'Testing video renderer with a sample video.' },
    { type: 'video', url: mockVideo.url, poster: mockVideo.poster },
  ]
}

async function executeTestCode(): Promise<Part[]> {
  await randomDelay()
  const sample = mockCodeSamples[0]
  return [{
    type: 'code',
    language: sample.language,
    filename: sample.filename,
    content: sample.content,
  }]
}

async function executeTestCombo(): Promise<Part[]> {
  await randomDelay()
  return [
    { type: 'text', content: 'This is a mixed content response demonstrating all renderer types.' },
    { type: 'image', url: mockImages[2].url, alt: mockImages[2].alt },
    { type: 'code', language: 'python', filename: 'script.py', content: mockCodeSamples[1].content },
    { type: 'links', items: mockLinks.slice(0, 3) },
  ]
}

async function executeTestMix(): Promise<Part[]> {
  await randomDelay()
  return [
    { type: 'text', content: 'Complete format test: Text, code, images, video, links, and more!' },
    { type: 'code', language: 'typescript', filename: 'example.ts', content: mockCodeSamples[0].content },
    { type: 'image', url: mockImages[0].url, alt: 'Test Image 1' },
    { type: 'video', url: mockVideo.url, poster: mockVideo.poster },
    { type: 'links', items: mockLinks },
    { type: 'text', content: 'All renderers tested successfully!' },
  ]
}

export async function executeCommand(input: string): Promise<Part[]> {
  const parsed = parseCommand(input)

  if (!parsed) {
    // Normale Text-Nachricht - wird gestreamt
    return [{ type: 'text', content: input }]
  }

  const { command, args } = parsed

  switch (command) {
    case 'list':
      return executeList()
    case 'pic':
      return executePic(args)
    case 'vid':
      return executeVid(args)
    case 'search':
      return executeSearch(args)
    case 'lang':
      return executeLang(args)
    case 'web':
      return executeWeb(args)
    case 'docs':
      return executeDocs(args)
    case 'link':
      return executeLink()
    case 'testpic':
      return executeTestPic()
    case 'testcode':
      return executeTestCode()
    case 'testvideo':
      return executeTestVideo()
    case 'testcombo':
      return executeTestCombo()
    case 'testmix':
      return executeTestMix()
    default:
      return [{ type: 'text', content: `Unknown command: /${command}` }]
  }
}

// Generiert eine Mock-AI-Antwort mit Streaming
export function generateMockResponse(userMessage: string): string {
  const responses = [
    `Das ist eine interessante Frage zu "${userMessage}". Ich werde versuchen, Ihnen eine umfassende Antwort zu geben.\n\nZunächst einmal sollten wir die Grundlagen betrachten. Dies hängt von verschiedenen Faktoren ab, die wir einzeln durchgehen können.\n\nErstens: Die Kontextinformationen sind wichtig. Zweitens: Wir müssen die verschiedenen Perspektiven berücksichtigen. Und drittens: Eine praktische Umsetzung ist entscheidend.\n\nIch hoffe, das hilft Ihnen weiter!`,
    `Zu Ihrer Frage "${userMessage}" kann ich Folgendes sagen:\n\n1. Es gibt mehrere Ansätze, die man verfolgen kann\n2. Jeder Ansatz hat seine eigenen Vor- und Nachteile\n3. Die beste Lösung hängt von Ihren spezifischen Anforderungen ab\n\nMöchten Sie mehr Details zu einem bestimmten Aspekt erfahren?`,
    `Interessante Anfrage: "${userMessage}"\n\nLassen Sie mich das für Sie aufschlüsseln. Im Wesentlichen geht es darum, die richtige Balance zu finden zwischen verschiedenen Faktoren. Hier sind einige wichtige Punkte zu beachten:\n\n• Performance und Effizienz\n• Benutzerfreundlichkeit\n• Wartbarkeit\n• Skalierbarkeit\n\nAll diese Aspekte spielen eine wichtige Rolle bei der Entscheidungsfindung.`,
  ]
  
  return responses[Math.floor(Math.random() * responses.length)]
}
