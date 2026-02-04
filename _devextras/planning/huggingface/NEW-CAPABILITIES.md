# New Capabilities: pic2pic & pic2vid

> Extension of Synaplan's AI capabilities enabled by HuggingFace integration

## Overview

HuggingFace Inference Providers enables two new capability tags that are not currently available in Synaplan:

| New Tag | Task | Description |
|---------|------|-------------|
| `pic2pic` | image-to-image | Transform/edit images with AI |
| `pic2vid` | image-to-video | Generate videos from images |

---

## pic2pic: Image-to-Image

### Use Cases

- **Style Transfer**: Apply artistic styles to photos
- **Image Editing**: "Turn the cat into a tiger"
- **Upscaling**: Increase image resolution
- **Colorization**: Add color to black & white images
- **Re-lighting**: Change lighting conditions
- **Inpainting**: Fill in missing parts of images
- **Background Replacement**: Change image backgrounds

### Interface Definition

```php
<?php

declare(strict_types=1);

namespace App\AI\Interface;

interface ImageToImageProviderInterface extends ProviderMetadataInterface
{
    /**
     * Transform an image based on a text prompt
     *
     * @param string $imagePath Path to source image
     * @param string $prompt    Transformation prompt
     * @param array  $options   Additional options
     *   - width: Target width (optional)
     *   - height: Target height (optional)
     *   - guidance_scale: Prompt adherence (default: 7.5)
     *   - num_inference_steps: Quality vs speed (default: 30)
     *   - negative_prompt: What to avoid
     *   - seed: For reproducibility
     *
     * @return string Path to generated image
     */
    public function editImage(string $imagePath, string $prompt, array $options = []): string;
}
```

### AiFacade Integration

```php
// In AiFacade.php
public function editImage(string $imagePath, string $prompt, ?int $userId = null, array $options = []): string
{
    $provider = $this->modelConfigService->getDefaultProvider($userId, 'pic2pic');
    $model = $this->modelConfigService->getDefaultModel($userId, 'pic2pic');

    $imageToImageProvider = $this->providerRegistry->getImageToImageProvider($provider);

    return $this->circuitBreaker->call(
        "editImage_{$provider}",
        fn() => $imageToImageProvider->editImage($imagePath, $prompt, [
            ...$options,
            'model' => $model,
        ])
    );
}
```

### HuggingFace Implementation

```php
public function editImage(string $imagePath, string $prompt, array $options = []): string
{
    $model = $options['model'] ?? 'black-forest-labs/FLUX.1-Kontext-dev';
    $provider = $options['provider'] ?? 'fal-ai';

    // Read and encode source image
    $imageData = base64_encode(file_get_contents($imagePath));

    $response = $this->httpClient->request('POST',
        "https://router.huggingface.co/{$provider}/{$model}",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'inputs' => $imageData,
                'parameters' => [
                    'prompt' => $prompt,
                    'guidance_scale' => $options['guidance_scale'] ?? 7.5,
                    'num_inference_steps' => $options['steps'] ?? 30,
                    'negative_prompt' => $options['negative_prompt'] ?? null,
                    'seed' => $options['seed'] ?? null,
                    'target_size' => isset($options['width']) ? [
                        'width' => $options['width'],
                        'height' => $options['height'] ?? $options['width'],
                    ] : null,
                ],
            ],
        ]
    );

    return $this->saveGeneratedImage($response->getContent());
}
```

### Recommended Models

| Model | Best For | Provider |
|-------|----------|----------|
| `black-forest-labs/FLUX.1-Kontext-dev` | General editing | fal-ai |
| `kontext-community/relighting-kontext-dev-lora-v3` | Re-lighting | fal-ai |
| `fal/Qwen-Image-Edit-2511-Multiple-Angles-LoRA` | Multi-angle edits | fal-ai |

### API Endpoint Addition

```php
#[Route('/api/v1/user/{userId}/ai/edit-image', methods: ['POST'])]
#[OA\Post(
    summary: 'Edit an image using AI',
    tags: ['AI'],
)]
#[OA\RequestBody(
    required: true,
    content: new OA\MediaType(
        mediaType: 'multipart/form-data',
        schema: new OA\Schema(
            properties: [
                new OA\Property(property: 'image', type: 'file'),
                new OA\Property(property: 'prompt', type: 'string'),
                new OA\Property(property: 'guidance_scale', type: 'number'),
                new OA\Property(property: 'steps', type: 'integer'),
            ]
        )
    )
)]
public function editImage(
    int $userId,
    Request $request,
    AiFacade $aiFacade,
): JsonResponse {
    $uploadedFile = $request->files->get('image');
    $prompt = $request->request->get('prompt');

    $imagePath = $this->fileHandler->saveUploadedFile($uploadedFile, $userId);

    $resultPath = $aiFacade->editImage($imagePath, $prompt, $userId, [
        'guidance_scale' => $request->request->get('guidance_scale', 7.5),
        'steps' => $request->request->get('steps', 30),
    ]);

    return new JsonResponse([
        'success' => true,
        'image_url' => $this->fileHandler->getPublicUrl($resultPath),
    ]);
}
```

---

## pic2vid: Image-to-Video

### Use Cases

- **Image Animation**: Animate still photos
- **Cinematic Shots**: Create camera movements from stills
- **Character Animation**: Make portraits "come alive"
- **Product Showcases**: Create rotating/moving product videos

### Interface Definition

```php
<?php

declare(strict_types=1);

namespace App\AI\Interface;

interface ImageToVideoProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generate a video from a source image
     *
     * @param string $imagePath Path to source image
     * @param string $prompt    Motion/animation prompt
     * @param array  $options   Additional options
     *   - num_frames: Number of video frames (default: 48)
     *   - guidance_scale: Prompt adherence (default: 7.0)
     *   - fps: Frames per second (default: 24)
     *   - duration: Target duration in seconds
     *   - motion_type: Type of motion (zoom, pan, rotate, etc.)
     *
     * @return string Path to generated video
     */
    public function imageToVideo(string $imagePath, string $prompt, array $options = []): string;
}
```

### AiFacade Integration

```php
// In AiFacade.php
public function imageToVideo(string $imagePath, string $prompt, ?int $userId = null, array $options = []): string
{
    $provider = $this->modelConfigService->getDefaultProvider($userId, 'pic2vid');
    $model = $this->modelConfigService->getDefaultModel($userId, 'pic2vid');

    $imageToVideoProvider = $this->providerRegistry->getImageToVideoProvider($provider);

    return $this->circuitBreaker->call(
        "imageToVideo_{$provider}",
        fn() => $imageToVideoProvider->imageToVideo($imagePath, $prompt, [
            ...$options,
            'model' => $model,
        ])
    );
}
```

### HuggingFace Implementation

```php
public function imageToVideo(string $imagePath, string $prompt, array $options = []): string
{
    $model = $options['model'] ?? 'tencent/HunyuanVideo';
    $provider = $options['provider'] ?? 'fal-ai';

    // Read and encode source image
    $imageData = base64_encode(file_get_contents($imagePath));

    $response = $this->httpClient->request('POST',
        "https://router.huggingface.co/{$provider}/{$model}",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'inputs' => $imageData,
                'parameters' => [
                    'prompt' => $prompt,
                    'num_frames' => $options['frames'] ?? 48,
                    'guidance_scale' => $options['guidance_scale'] ?? 7.0,
                    'fps' => $options['fps'] ?? 24,
                ],
            ],
            'timeout' => 300, // Video generation can be slow
        ]
    );

    return $this->saveGeneratedVideo($response->getContent());
}
```

### Note on Availability

Image-to-Video is currently **limited** in HuggingFace Inference Providers. Most video models support text-to-video but not all support image-to-video input. This may need to be handled via:

1. **Workaround**: Use vision model to describe image, then text-to-video
2. **Alternative Provider**: Use TheHive or direct Replicate API for I2V
3. **Future**: Wait for broader HF support

---

## ProviderRegistry Updates

### New Provider Getters

```php
// In ProviderRegistry.php

public function getImageToImageProvider(string $providerName): ImageToImageProviderInterface
{
    return $this->getProvider($providerName, ImageToImageProviderInterface::class, 'pic2pic');
}

public function getImageToVideoProvider(string $providerName): ImageToVideoProviderInterface
{
    return $this->getProvider($providerName, ImageToVideoProviderInterface::class, 'pic2vid');
}
```

### Capability Mapping

```php
private const CAPABILITY_TAG_MAP = [
    'chat' => 'chat',
    'embedding' => 'vectorize',
    'vision' => 'pic2text',
    'image_generation' => 'text2pic',
    'video_generation' => 'text2vid',
    'speech_to_text' => 'sound2text',
    'text_to_speech' => 'text2sound',
    // New capabilities
    'image_to_image' => 'pic2pic',
    'image_to_video' => 'pic2vid',
];
```

---

## Database Schema Updates

### New BCONFIG Default Models

```sql
-- Add default model settings for new capabilities
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES
(0, 'DEFAULTMODEL', 'PIC2PIC', '100'),  -- ID of default pic2pic model
(0, 'DEFAULTMODEL', 'PIC2VID', '101');  -- ID of default pic2vid model
```

### New BMODELS Entries

```sql
-- Image-to-Image models
INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
(100, 'HuggingFace', 'FLUX.1 Kontext', 'pic2pic', 1, 'black-forest-labs/FLUX.1-Kontext-dev', 0, '-', 0.04, 'perpic', 10, 1, 1, 1, '{"description":"FLUX Kontext - AI image editing","params":{"model":"black-forest-labs/FLUX.1-Kontext-dev","provider":"fal-ai"}}'),
(102, 'HuggingFace', 'Re-lighting LoRA', 'pic2pic', 1, 'kontext-community/relighting-kontext-dev-lora-v3', 0, '-', 0.04, 'perpic', 9, 1, 0, 1, '{"description":"AI re-lighting model","params":{"model":"kontext-community/relighting-kontext-dev-lora-v3","provider":"fal-ai"}}');

-- Image-to-Video models (limited availability)
INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
(101, 'HuggingFace', 'HunyuanVideo I2V', 'pic2vid', 1, 'tencent/HunyuanVideo', 0, '-', 0.5, 'pervid', 9, 1, 1, 1, '{"description":"Image to Video generation (experimental)","params":{"model":"tencent/HunyuanVideo","provider":"fal-ai"}}');
```

---

## Rate Limiting Updates

### New Limit Categories

```sql
-- Add rate limits for new capabilities
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES
-- Anonymous users
(0, 'RATELIMITS_ANONYMOUS', 'IMAGE_EDITS_TOTAL', '1'),
(0, 'RATELIMITS_ANONYMOUS', 'IMAGE_TO_VIDEO_TOTAL', '0'),

-- New users
(0, 'RATELIMITS_NEW', 'IMAGE_EDITS_TOTAL', '3'),
(0, 'RATELIMITS_NEW', 'IMAGE_TO_VIDEO_TOTAL', '1'),

-- Pro users
(0, 'RATELIMITS_PRO', 'IMAGE_EDITS_MONTHLY', '30'),
(0, 'RATELIMITS_PRO', 'IMAGE_TO_VIDEO_MONTHLY', '5'),

-- Team users
(0, 'RATELIMITS_TEAM', 'IMAGE_EDITS_MONTHLY', '100'),
(0, 'RATELIMITS_TEAM', 'IMAGE_TO_VIDEO_MONTHLY', '20'),

-- Business users
(0, 'RATELIMITS_BUSINESS', 'IMAGE_EDITS_MONTHLY', '500'),
(0, 'RATELIMITS_BUSINESS', 'IMAGE_TO_VIDEO_MONTHLY', '100');
```

---

## Frontend Integration

### Vue Component: ImageEditor

Strictly following project rules (Tailwind, i18n, Composition API).

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAiStore } from '@/stores/ai'

const props = defineProps<{
  userId: number
}>()

const { t } = useI18n()
const aiStore = useAiStore()

const sourceImage = ref<File | null>(null)
const prompt = ref('')
const isLoading = ref(false)
const resultUrl = ref<string | null>(null)
const error = ref<string | null>(null)

async function handleFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files && input.files[0]) {
    sourceImage.value = input.files[0]
  }
}

async function editImage() {
  if (!sourceImage.value || !prompt.value) return

  isLoading.value = true
  error.value = null

  try {
    const result = await aiStore.editImage(
      props.userId,
      sourceImage.value,
      prompt.value
    )
    resultUrl.value = result.image_url
  } catch (e) {
    console.error(e)
    error.value = t('ai.errors.generation_failed')
  } finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div class="p-4 bg-white dark:bg-gray-800 rounded-lg shadow-md">
    <h3 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">
      {{ t('ai.tools.image_editor.title') }}
    </h3>

    <div class="space-y-4">
      <!-- File Input -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {{ t('ai.tools.image_editor.upload_label') }}
        </label>
        <input
          type="file"
          accept="image/*"
          @change="handleFileChange"
          class="block w-full text-sm text-gray-500 dark:text-gray-400
            file:mr-4 file:py-2 file:px-4
            file:rounded-full file:border-0
            file:text-sm file:font-semibold
            file:bg-blue-50 file:text-blue-700
            hover:file:bg-blue-100
            dark:file:bg-gray-700 dark:file:text-gray-200"
        />
      </div>

      <!-- Prompt Input -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {{ t('ai.tools.image_editor.prompt_label') }}
        </label>
        <input
          v-model="prompt"
          type="text"
          :placeholder="t('ai.tools.image_editor.prompt_placeholder')"
          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
        />
      </div>

      <!-- Action Button -->
      <button
        @click="editImage"
        :disabled="isLoading || !sourceImage || !prompt"
        class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <span v-if="isLoading">{{ t('common.processing') }}</span>
        <span v-else>{{ t('ai.tools.image_editor.submit') }}</span>
      </button>

      <!-- Error Message -->
      <div v-if="error" class="text-red-600 text-sm mt-2">
        {{ error }}
      </div>

      <!-- Result Image -->
      <div v-if="resultUrl" class="mt-4">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
          {{ t('ai.tools.image_editor.result') }}
        </p>
        <img
          :src="resultUrl"
          :alt="t('ai.tools.image_editor.result_alt')"
          class="w-full rounded-lg shadow-lg border border-gray-200 dark:border-gray-700"
        />
      </div>
    </div>
  </div>
</template>
```

### API Client with Zod Validation

```typescript
// services/api/ai.ts
import { z } from 'zod'
import { httpClient } from '@/services/httpClient'

// Zod Schemas
export const EditImageResponseSchema = z.object({
  success: z.boolean(),
  image_url: z.string().url(),
})

export const ImageToVideoResponseSchema = z.object({
  success: z.boolean(),
  video_url: z.string().url(),
})

// Types inferred from schemas
export type EditImageResponse = z.infer<typeof EditImageResponseSchema>
export type ImageToVideoResponse = z.infer<typeof ImageToVideoResponseSchema>

export async function editImage(
  userId: number,
  image: File,
  prompt: string,
  options?: { guidanceScale?: number; steps?: number }
): Promise<EditImageResponse> {
  const formData = new FormData()
  formData.append('image', image)
  formData.append('prompt', prompt)

  if (options?.guidanceScale) {
    formData.append('guidance_scale', String(options.guidanceScale))
  }
  if (options?.steps) {
    formData.append('steps', String(options.steps))
  }

  // Using the enhanced httpClient that supports Zod validation
  return httpClient.post(`/user/${userId}/ai/edit-image`, formData, {
    schema: EditImageResponseSchema
  })
}

export async function imageToVideo(
  userId: number,
  image: File,
  prompt: string,
  options?: { frames?: number; guidanceScale?: number }
): Promise<ImageToVideoResponse> {
  const formData = new FormData()
  formData.append('image', image)
  formData.append('prompt', prompt)

  if (options?.frames) {
    formData.append('frames', String(options.frames))
  }
  if (options?.guidanceScale) {
    formData.append('guidance_scale', String(options.guidanceScale))
  }

  return httpClient.post(`/user/${userId}/ai/image-to-video`, formData, {
    schema: ImageToVideoResponseSchema
  })
}
```

---

## Testing Checklist

### pic2pic Tests

- [ ] Upload image + edit prompt → receive modified image
- [ ] Test with various image formats (PNG, JPEG, WebP)
- [ ] Test with large images (>4K)
- [ ] Test guidance_scale parameter effects
- [ ] Test negative_prompt filtering
- [ ] Verify rate limiting enforcement
- [ ] Test error handling for invalid images

### pic2vid Tests

- [ ] Upload image + motion prompt → receive video
- [ ] Test video duration/frame count options
- [ ] Test with portrait vs landscape images
- [ ] Verify video format (MP4)
- [ ] Test timeout handling (video gen is slow)
- [ ] Test fallback when I2V not available

---

## Migration Notes

### From TheHive (if applicable)

If TheHive is currently providing image editing:

1. Add HuggingFace as alternative provider
2. Use TheHive as fallback for specific LoRAs
3. Gradually migrate users to HuggingFace

### Feature Flags

Consider adding feature flags for new capabilities:

```php
// In FeatureFlags or BCONFIG
'enable_pic2pic' => true,
'enable_pic2vid' => true, // false until more stable
```
