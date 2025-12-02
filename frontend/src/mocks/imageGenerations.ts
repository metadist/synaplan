export interface ImageGeneration {
  id: number
  prompt: string
  imageUrl: string
  provider: string
  model: string
  createdAt: Date
  metadata: {
    size: string
    quality: string
    style?: string
  }
}

export const mockImageGenerations: ImageGeneration[] = [
  {
    id: 1,
    prompt: 'A serene mountain landscape at sunset',
    imageUrl: 'https://picsum.photos/1024/768?random=10',
    provider: 'dalle',
    model: 'dall-e-3',
    createdAt: new Date(Date.now() - 3600000),
    metadata: {
      size: '1024x768',
      quality: 'hd',
      style: 'natural'
    }
  },
  {
    id: 2,
    prompt: 'Futuristic city with flying cars',
    imageUrl: 'https://picsum.photos/1024/768?random=11',
    provider: 'midjourney',
    model: 'midjourney-v6',
    createdAt: new Date(Date.now() - 7200000),
    metadata: {
      size: '1024x768',
      quality: 'standard',
      style: 'cinematic'
    }
  },
  {
    id: 3,
    prompt: 'Abstract art with vibrant colors',
    imageUrl: 'https://picsum.photos/1024/768?random=12',
    provider: 'stable-diffusion',
    model: 'sdxl-1.0',
    createdAt: new Date(Date.now() - 10800000),
    metadata: {
      size: '1024x768',
      quality: 'standard'
    }
  }
]

export function mockGenerateImage(prompt: string): Promise<ImageGeneration> {
  return new Promise((resolve) => {
    setTimeout(() => {
      resolve({
        id: Math.floor(Math.random() * 10000),
        prompt,
        imageUrl: `https://picsum.photos/1024/768?random=${Math.floor(Math.random() * 100)}`,
        provider: 'test',
        model: 'test-image-gen-1.0',
        createdAt: new Date(),
        metadata: {
          size: '1024x768',
          quality: 'standard'
        }
      })
    }, 1500)
  })
}

