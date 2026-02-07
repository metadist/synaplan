# Piper TTS Provider Integration

**Phase 1 of Voice Conversations**

## Overview

Register the self-hosted `synaplan-tts` Piper service as a TTS provider in Synaplan's AI system. Free, local, multi-language — no API key required.

## Service Details

| Property | Value |
|----------|-------|
| URL | `http://host.docker.internal:10200` (from Docker) |
| Endpoint | `POST /api/tts` (JSON body → WAV audio) |
| Languages | en, de, es, tr, ru, fa |
| Output | `audio/wav` (22050 Hz, 16-bit) |
| Max text | 5000 chars |
| Cost | Free (self-hosted) |

## Files to Create/Modify

### 1. New: `backend/src/AI/Provider/PiperProvider.php`

Implements `TextToSpeechProviderInterface` + `ProviderMetadataInterface`.

```php
final class PiperProvider implements TextToSpeechProviderInterface
{
    private const DEFAULT_URL = 'http://host.docker.internal:10200';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $uploadDir,
        private string $piperUrl = self::DEFAULT_URL,
    ) {}

    public function getName(): string { return 'piper'; }

    public function synthesize(string $text, array $options = []): string
    {
        // 1. POST JSON to Piper: { text, language, voice? }
        // 2. Receive WAV bytes
        // 3. Convert WAV → MP3 via ffmpeg (shell exec)
        // 4. Save to $uploadDir/tts_{uniqid}.mp3
        // 5. Return filename
    }

    public function getVoices(): array
    {
        // GET /api/voices → return voice list
    }
}
```

**Key implementation details:**
- Language mapping: detect from `$options['language']` or default `en`
- WAV→MP3 conversion: `ffmpeg -i input.wav -codec:a libmp3lame -qscale:a 4 output.mp3`
- File is saved to `$uploadDir` root; `AiFacade::moveToUserPath()` relocates it
- Timeout: 30s (long texts may take time on CPU)

### 2. Modify: `backend/config/services.yaml`

Register PiperProvider with DI tags:

```yaml
App\AI\Provider\PiperProvider:
    arguments:
        $uploadDir: '%env(UPLOAD_DIR)%'
        $piperUrl: '%env(default::PIPER_TTS_URL)%'
    tags:
        - { name: 'app.ai.text_to_speech_provider' }
```

### 3. New: BMODELS entry (fixture or SQL)

```sql
INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BDESCRIPTION, BJSON)
VALUES (
    'Piper', 'piper-multi', 'text2sound', 1, 'piper-multi',
    0, 'free', 0, 'free',
    7, 0.8, 1, 1,
    'Self-hosted Piper TTS. Multi-language (en, de, es, tr, ru, fa). Free, no API key.',
    '{"description":"Self-hosted Piper TTS via synaplan-tts","params":{"voices":["en_US-lessac-medium","de_DE-thorsten-medium","es_ES-davefx-medium","tr_TR-dfki-medium","ru_RU-irina-medium","fa_IR-reza_ibrahim-medium"]},"features":["multilingual","self-hosted","free"]}'
);
```

### 4. Modify: `ModelFixtures.php`

Add Piper entry to the fixtures array (same data as SQL above).

### 5. Environment: `.env` / `docker-compose.yml`

```env
# Use service name 'synaplan-tts' if in same network, else 'host.docker.internal'
PIPER_TTS_URL=http://host.docker.internal:10200
```

In `docker-compose.yml`, add `extra_hosts: ["host.docker.internal:host-gateway"]` to the backend service (likely already present).

## Security & Validation

- **Input Validation:** `synthesize` method must validate `$options['language']` against a whitelist to prevent any potential injection or invalid API calls.
- **Path Traversal:** Ensure `ffmpeg` input/output paths are strictly controlled using `uniqid()` and absolute paths within `$uploadDir`.
- **Rate Limiting:** The provider itself doesn't limit, but callers (`StreamController`, `WhatsAppService`) MUST enforce `AUDIOS` rate limits before calling `synthesize`.

## WAV→MP3 Conversion

Piper outputs WAV. WhatsApp and browsers prefer MP3. Conversion via ffmpeg:

```php
private function convertWavToMp3(string $wavPath): string
{
    $mp3Path = preg_replace('/\.wav$/', '.mp3', $wavPath);

    $cmd = sprintf(
        'ffmpeg -i %s -codec:a libmp3lame -qscale:a 4 -y %s 2>&1',
        escapeshellarg($wavPath),
        escapeshellarg($mp3Path)
    );

    exec($cmd, $output, $exitCode);

    if (0 !== $exitCode) {
        throw new \RuntimeException('ffmpeg WAV→MP3 failed: '.implode("\n", $output));
    }

    // Clean up WAV
    @unlink($wavPath);

    return $mp3Path;
}
```

ffmpeg is already available in the backend Docker image (used for video processing).

## Language Detection

The provider should pick the right Piper voice based on the conversation language:

```php
private function resolveLanguage(array $options): string
{
    $lang = $options['language'] ?? 'en';

    // Map to Piper language codes
    $map = [
        'en' => 'en', 'de' => 'de', 'es' => 'es',
        'tr' => 'tr', 'ru' => 'ru', 'fa' => 'fa',
    ];

    return $map[$lang] ?? 'en';
}
```

The message's detected language (`BLANG` column) should be passed through `$options['language']` by the caller.

## Health Check

```php
public function isAvailable(): bool
{
    try {
        $response = $this->httpClient->request('GET', $this->piperUrl.'/health');
        $data = $response->toArray();
        return 'ok' === ($data['status'] ?? '');
    } catch (\Throwable) {
        return false;
    }
}
```

## Fallback Strategy

In `AiFacade::synthesize()`, if Piper fails and OpenAI TTS is configured, the `CircuitBreaker` will open. The caller should catch and optionally retry with a different provider. For v1, just log the error and skip audio — the text response is always delivered.

## Verification Steps

```bash
# 1. Check Piper is reachable from backend container
docker compose exec backend curl -s http://host.docker.internal:10200/health

# 2. Test synthesis
docker compose exec backend curl -X POST http://host.docker.internal:10200/api/tts \
  -H 'Content-Type: application/json' \
  -d '{"text":"Hello world","language":"en"}' \
  -o /tmp/test.wav && echo "OK: $(stat -c%s /tmp/test.wav) bytes"

# 3. Test ffmpeg conversion
docker compose exec backend ffmpeg -i /tmp/test.wav -codec:a libmp3lame -qscale:a 4 -y /tmp/test.mp3

# 4. Check model is in DB
docker compose exec backend php bin/console dbal:run-sql "SELECT * FROM BMODELS WHERE BSERVICE='Piper'"
```
