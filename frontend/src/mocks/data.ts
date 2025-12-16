export const mockImages = [
  {
    url: 'https://images.pexels.com/photos/1108099/pexels-photo-1108099.jpeg?auto=compress&cs=tinysrgb&w=800',
    alt: 'Mountain landscape at sunset',
  },
  {
    url: 'https://images.pexels.com/photos/1571460/pexels-photo-1571460.jpeg?auto=compress&cs=tinysrgb&w=800',
    alt: 'Cat sitting on a windowsill',
  },
  {
    url: 'https://images.pexels.com/photos/1591447/pexels-photo-1591447.jpeg?auto=compress&cs=tinysrgb&w=800',
    alt: 'City skyline at night',
  },
]

export const mockVideo = {
  url: 'https://www.w3schools.com/html/mov_bbb.mp4',
  poster:
    'https://images.pexels.com/photos/1595385/pexels-photo-1595385.jpeg?auto=compress&cs=tinysrgb&w=800',
}

export const mockLinks = [
  {
    title: 'Vue 3 Documentation',
    url: 'https://vuejs.org/guide/introduction.html',
    desc: 'The official Vue.js documentation with comprehensive guides and API reference.',
    host: 'vuejs.org',
  },
  {
    title: 'Tailwind CSS - Rapidly build modern websites',
    url: 'https://tailwindcss.com/',
    desc: 'A utility-first CSS framework for rapidly building custom user interfaces.',
    host: 'tailwindcss.com',
  },
  {
    title: 'TypeScript: Documentation',
    url: 'https://www.typescriptlang.org/docs/',
    desc: 'TypeScript extends JavaScript by adding types to the language.',
    host: 'typescriptlang.org',
  },
  {
    title: 'Pinia - The Vue Store',
    url: 'https://pinia.vuejs.org/',
    desc: 'Intuitive, type safe and flexible Store for Vue.',
    host: 'pinia.vuejs.org',
  },
  {
    title: 'Vite - Next Generation Frontend Tooling',
    url: 'https://vitejs.dev/',
    desc: 'Get ready for a development environment that can finally catch up with you.',
    host: 'vitejs.dev',
  },
]

export const mockDocs = [
  {
    filename: 'components/ChatInput.vue',
    snippet:
      'Component for handling user input in the chat interface. Supports text input, attachments, and command detection.',
  },
  {
    filename: 'stores/commands.ts',
    snippet:
      'Command registry and state management. Contains all available slash commands with validation logic.',
  },
  {
    filename: 'README.md',
    snippet:
      'Project documentation including setup instructions, available features, and development guidelines.',
  },
]

export const mockCodeSamples = [
  {
    language: 'typescript',
    filename: 'example.ts',
    content: `interface User {
  id: string
  email: string
  name: string
  createdAt: Date
}

async function fetchUser(id: string): Promise<User> {
  const response = await fetch(\`/api/users/\${id}\`)
  return response.json()
}`,
  },
  {
    language: 'python',
    filename: 'main.py',
    content: `def calculate_fibonacci(n: int) -> list[int]:
    """Generate Fibonacci sequence up to n numbers."""
    if n <= 0:
        return []
    elif n == 1:
        return [0]

    fib = [0, 1]
    for i in range(2, n):
        fib.append(fib[i-1] + fib[i-2])

    return fib`,
  },
]

export const mockTranslations: Record<string, Record<string, string>> = {
  en: {
    'good morning': 'good morning',
    'hello world': 'hello world',
    'thank you': 'thank you',
  },
  de: {
    'good morning': 'guten Morgen',
    'hello world': 'hallo Welt',
    'thank you': 'danke schön',
  },
  fr: {
    'good morning': 'bonjour',
    'hello world': 'bonjour le monde',
    'thank you': 'merci',
  },
  it: {
    'good morning': 'buongiorno',
    'hello world': 'ciao mondo',
    'thank you': 'grazie',
  },
  es: {
    'good morning': 'buenos días',
    'hello world': 'hola mundo',
    'thank you': 'gracias',
  },
  pt: {
    'good morning': 'bom dia',
    'hello world': 'olá mundo',
    'thank you': 'obrigado',
  },
  nl: {
    'good morning': 'goedemorgen',
    'hello world': 'hallo wereld',
    'thank you': 'dank je',
  },
}
