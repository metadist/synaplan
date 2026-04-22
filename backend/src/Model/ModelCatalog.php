<?php

declare(strict_types=1);

namespace App\Model;

use Doctrine\DBAL\Connection;

/**
 * Built-in catalog of AI models.
 *
 * Each model has a lookup key in the format "service:providerId" (lowercased).
 * When multiple models share the same key (e.g. chat + vision variants),
 * they are grouped and enabled/disabled together.
 *
 * To target a specific variant, append the tag: "service:providerId:tag"
 *
 * Usage:
 *   ModelCatalog::find('groq:llama-3.3-70b-versatile')  → [model]
 *   ModelCatalog::find('openai:gpt-4o')                  → [chat, pic2text]
 *   ModelCatalog::find('openai:gpt-4o:chat')             → [chat only]
 */
class ModelCatalog
{
    /** Maps DEFAULTMODEL capabilities to the model tag they require. */
    public const CAPABILITY_TAGS = [
        'CHAT' => 'chat',
        'TOOLS' => 'chat',
        'SORT' => 'chat',
        'SUMMARIZE' => 'chat',
        'ANALYZE' => 'chat',
        'TEXT2PIC' => 'text2pic',
        'PIC2PIC' => 'text2pic',
        'TEXT2VID' => 'text2vid',
        'TEXT2SOUND' => 'text2sound',
        'PIC2TEXT' => 'pic2text',
        'SOUND2TEXT' => 'sound2text',
        'VECTORIZE' => 'vectorize',
    ];

    /**
     * Insert or update a model row via `INSERT … ON DUPLICATE KEY UPDATE`.
     *
     * Field ownership rules:
     *   - **Catalog-owned** (always overwritten on UPDATE):
     *     BSERVICE, BNAME, BTAG, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT,
     *     BQUALITY, BRATING, BJSON. Truth lives in this class; deploy = update.
     *   - **Operator-owned** (only set on INSERT, NEVER overwritten):
     *     BSELECTABLE, BACTIVE, BISDEFAULT. These can be toggled by admins via
     *     the AdminModelsService UI; container restarts must not wipe those choices.
     *     Test fixtures that need to force a value should issue an explicit UPDATE
     *     after the upsert (see ModelSeeder::seed()).
     *
     * Returns the MySQL/MariaDB affected-rows value so callers can distinguish
     * inserts from updates:
     *   - 1 → row was inserted
     *   - 2 → existing row was updated (values differed)
     *   - 0 → existing row was unchanged (values identical)
     */
    public static function upsert(Connection $connection, array $model, bool $system = false): int
    {
        return (int) $connection->executeStatement(
            'INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BJSON)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                BSERVICE = VALUES(BSERVICE), BNAME = VALUES(BNAME), BTAG = VALUES(BTAG),
                BPROVID = VALUES(BPROVID), BPRICEIN = VALUES(BPRICEIN),
                BINUNIT = VALUES(BINUNIT), BPRICEOUT = VALUES(BPRICEOUT),
                BOUTUNIT = VALUES(BOUTUNIT), BQUALITY = VALUES(BQUALITY),
                BRATING = VALUES(BRATING),
                BJSON = VALUES(BJSON)',
            [
                $model['id'], $model['service'], $model['name'], $model['tag'],
                $model['selectable'], $model['active'], $model['providerId'],
                $model['priceIn'], $model['inUnit'], $model['priceOut'],
                $model['outUnit'], $model['quality'], $model['rating'],
                $system ? 1 : 0,
                json_encode($model['json']),
            ]
        );
    }

    /**
     * Resolve a catalog key like `service:providerId:tag` (or `service:providerId`
     * for unambiguous keys) to the BMODELS BID. Returns null if no unique match exists.
     *
     * Used by seed code that needs to bind config to a specific catalog entry without
     * hard-coding numeric IDs.
     */
    public static function findBidByKey(string $key): ?int
    {
        $matches = self::find($key);

        return 1 === count($matches) ? (int) $matches[0]['id'] : null;
    }

    /**
     * Delete a model from the database by its catalog ID.
     */
    public static function remove(Connection $connection, array $model): void
    {
        $connection->executeStatement('DELETE FROM BMODELS WHERE BID = ?', [$model['id']]);
    }

    /**
     * Find models matching the given key.
     *
     * @param string $key Format: "service:providerId" or "service:providerId:tag"
     *
     * @return array[] Matching model definitions
     */
    public static function find(string $key): array
    {
        $key = strtolower($key);
        $results = [];

        foreach (self::MODELS as $model) {
            $modelKey = self::modelKey($model);
            $modelKeyWithTag = $modelKey.':'.strtolower($model['tag']);

            if ($key === $modelKey || $key === $modelKeyWithTag) {
                $results[] = $model;
            }
        }

        return $results;
    }

    /**
     * Get all unique model keys (service:providerId).
     *
     * @return string[]
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::MODELS as $model) {
            $key = self::modelKey($model);
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * Get all model definitions.
     *
     * @return array[]
     */
    public static function all(): array
    {
        return self::MODELS;
    }

    /**
     * Compute the lookup key for a model: "service:providerId" (lowercased, colons in providerId replaced with dashes).
     */
    private static function modelKey(array $model): string
    {
        $service = strtolower($model['service']);
        $providerId = strtolower(str_replace(':', '-', $model['providerId']));

        return $service.':'.$providerId;
    }

    private const MODELS = [
        // ==================== OLLAMA MODELS ====================
        [
            'id' => 13,
            'service' => 'Ollama',
            'name' => 'bge-m3',
            'tag' => 'vectorize',
            'selectable' => 0,
            'active' => 1,
            'providerId' => 'bge-m3',
            'priceIn' => 0.19,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 6,
            'rating' => 1,
            'json' => [
                'description' => 'Vectorize text into synaplans MariaDB vector DB (local) for RAG',
                'params' => [
                    'model' => 'bge-m3',
                    'input' => [],
                ],
            ],
        ],
        [
            'id' => 78,
            'service' => 'Ollama',
            'name' => 'gpt-oss:20b',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-oss:20b',
            'priceIn' => 0.12,
            'inUnit' => 'per1M',
            'priceOut' => 0.60,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Local model on synaplans company server in Germany. OpenAI\'s open-weight GPT-OSS (20B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools/agentic use cases.',
                'max_tokens' => 16384,
                'params' => ['model' => 'gpt-oss:20b'],
                'meta' => ['context_window' => '128000', 'max_output' => '16384', 'license' => 'Apache-2.0', 'quantization' => 'MXFP4'],
            ],
        ],
        [
            'id' => 79,
            'service' => 'Ollama',
            'name' => 'gpt-oss:120b',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-oss:120b',
            'priceIn' => 0.05,
            'inUnit' => 'per1M',
            'priceOut' => 0.25,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Local model on synaplans company server in Germany. OpenAI\'s open-weight GPT-OSS (120B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools/agentic use cases.',
                'max_tokens' => 16384,
                'params' => ['model' => 'gpt-oss:120b'],
                'meta' => ['context_window' => '128000', 'max_output' => '16384', 'license' => 'Apache-2.0', 'quantization' => 'MXFP4'],
            ],
        ],
        [
            'id' => 124,
            'service' => 'Ollama',
            'name' => 'nemotron-3-nano',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'nemotron-3-nano',
            'priceIn' => 0.092,
            'inUnit' => 'per1M',
            'priceOut' => 0.46,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 8,
            'json' => [
                'description' => 'NVIDIA Nemotron 3 nano',
                'max_tokens' => 32768,
                'features' => ['reasoning'],
                'meta' => ['context_window' => '131072', 'max_output' => '32768'],
            ],
        ],
        [
            'id' => 172,
            'service' => 'Ollama',
            'name' => 'Qwen 3.5 35B',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'qwen3.5:35b',
            'priceIn' => 0.15,
            'inUnit' => 'per1M',
            'priceOut' => 0.4,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Local model on GPU server. Alibaba Qwen 3.5 35B - strong reasoning and coding.',
                'max_tokens' => 32768,
                'params' => ['model' => 'qwen3.5:35b'],
                'meta' => ['context_window' => '32768', 'max_output' => '32768'],
            ],
        ],
        // ==================== GROQ MODELS ====================
        [
            'id' => 9,
            'service' => 'Groq',
            'name' => 'Llama 3.3 70b versatile',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'llama-3.3-70b-versatile',
            'priceIn' => 0.59,
            'inUnit' => 'per1M',
            'priceOut' => 0.79,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Fast API service via groq',
                'max_tokens' => 32768,
                'params' => [
                    'model' => 'llama-3.3-70b-versatile',
                    'reasoning_format' => 'hidden',
                    'messages' => [],
                ],
                'meta' => ['context_window' => '131072', 'max_output' => '32768'],
            ],
        ],
        [
            'id' => 17,
            'service' => 'Groq',
            'name' => 'Llama 4 Scout Vision',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'priceIn' => 0.11,
            'inUnit' => 'per1M',
            'priceOut' => 0.34,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 0,
            'json' => [
                'description' => 'Groq Llama 4 Scout vision model - 128K context, up to 5 images, supports tool use and JSON mode',
                'params' => [
                    'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
                    'max_completion_tokens' => 1024,
                ],
            ],
        ],
        [
            'id' => 21,
            'service' => 'Groq',
            'name' => 'whisper-large-v3',
            'tag' => 'sound2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'whisper-large-v3',
            'priceIn' => 0.111,
            'inUnit' => 'perhour',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Groq Whisper Large V3 - Best accuracy for multilingual transcription and translation. Supports 50+ languages.',
                'params' => [
                    'file' => '*LOCALFILEPATH*',
                    'model' => 'whisper-large-v3',
                    'response_format' => 'verbose_json',
                ],
            ],
        ],
        [
            'id' => 50,
            'service' => 'Groq',
            'name' => 'whisper-large-v3-turbo',
            'tag' => 'sound2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'whisper-large-v3-turbo',
            'priceIn' => 0.04,
            'inUnit' => 'perhour',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'Groq Whisper Large V3 Turbo - Fast and cost-effective transcription. 3x cheaper than V3. No translation support.',
                'params' => [
                    'file' => '*LOCALFILEPATH*',
                    'model' => 'whisper-large-v3-turbo',
                    'response_format' => 'verbose_json',
                ],
            ],
        ],
        [
            'id' => 53,
            'service' => 'Groq',
            'name' => 'Qwen3 32B (Reasoning)',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'qwen/qwen3-32b',
            'priceIn' => 0.15,
            'inUnit' => 'per1M',
            'priceOut' => 0.60,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 5,
            'json' => [
                'description' => 'Groq Qwen3 32B mit Reasoning - 32B-Parameter Reasoning-Modell von Qwen. Zeigt Denkprozess mit <think> Tags. Optimiert für logisches Denken und Problemlösung. Sehr schnell durch Groq Hardware.',
                'max_tokens' => 32768,
                'params' => ['model' => 'qwen/qwen3-32b'],
                'features' => ['reasoning'],
                'meta' => ['context_window' => '131072', 'max_output' => '32768', 'reasoning_format' => 'raw'],
            ],
        ],
        [
            'id' => 75,
            'service' => 'Groq',
            'name' => 'gpt-oss-20b',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'openai/gpt-oss-20b',
            'priceIn' => 0.10,
            'inUnit' => 'per1M',
            'priceOut' => 0.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 3,
            'json' => [
                'description' => 'Groq GPT-OSS 20B - 21B-Parameter MoE-Modell. Optimiert für niedrige Latenz und schnelle Inferenz. Sehr schnell durch Groq Hardware.',
                'max_tokens' => 16384,
                'params' => ['model' => 'openai/gpt-oss-20b'],
                'meta' => ['context_window' => '131072', 'max_output' => '16384', 'license' => 'Apache-2.0', 'quantization' => 'TruePoint Numerics'],
            ],
        ],
        [
            'id' => 76,
            'service' => 'Groq',
            'name' => 'gpt-oss-120b',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'openai/gpt-oss-120b',
            'priceIn' => 0.15,
            'inUnit' => 'per1M',
            'priceOut' => 0.75,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 4,
            'json' => [
                'description' => 'Groq GPT-OSS 120B - 120B-Parameter MoE-Modell. Für anspruchsvolle agentische Anwendungen. Schnelle Inferenz dank Groq Hardware.',
                'max_tokens' => 16384,
                'params' => ['model' => 'openai/gpt-oss-120b'],
                'meta' => ['context_window' => '131072', 'max_output' => '16384', 'license' => 'Apache-2.0', 'quantization' => 'TruePoint Numerics'],
            ],
        ],
        // ==================== OPENAI MODELS ====================
        [
            'id' => 29,
            'service' => 'OpenAI',
            'name' => 'gpt-image-1',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-image-1',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 40,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI image generation model. Costs are 1:1 funneled.',
                'params' => ['model' => 'gpt-image-1'],
            ],
        ],
        [
            'id' => 41,
            'service' => 'OpenAI',
            'name' => 'tts-1 with Nova',
            'tag' => 'text2sound',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'tts-1',
            'priceIn' => 0.015,
            'inUnit' => 'per1000chars',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI\'s text to speech, defaulting on voice NOVA.',
                'params' => ['model' => 'tts-1', 'voice' => 'nova'],
            ],
        ],
        [
            'id' => 73,
            'service' => 'OpenAI',
            'name' => 'gpt-4o-mini',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-4o-mini',
            'priceIn' => 0.15,
            'inUnit' => 'per1M',
            'priceOut' => 0.6,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI lightweight GPT-4o-mini model for fast and cost-efficient chat tasks. Optimized for lower latency and cheaper throughput.',
                'max_tokens' => 16384,
                'params' => ['model' => 'gpt-4o-mini'],
                'meta' => ['context_window' => '128000', 'max_output' => '16384'],
            ],
        ],
        [
            'id' => 82,
            'service' => 'OpenAI',
            'name' => 'whisper-1',
            'tag' => 'sound2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'whisper-1',
            'priceIn' => 0.006,
            'inUnit' => 'permin',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI Whisper model for audio transcription. Supports 50+ languages.',
                'params' => ['model' => 'whisper-1', 'response_format' => 'verbose_json'],
                'features' => ['multilingual', 'translation'],
            ],
        ],
        [
            'id' => 83,
            'service' => 'OpenAI',
            'name' => 'tts-1-hd',
            'tag' => 'text2sound',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'tts-1-hd',
            'priceIn' => 0.03,
            'inUnit' => 'per1000chars',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI high-quality text-to-speech.',
                'params' => ['model' => 'tts-1-hd'],
            ],
        ],
        [
            'id' => 87,
            'service' => 'OpenAI',
            'name' => 'text-embedding-3-small',
            'tag' => 'vectorize',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'text-embedding-3-small',
            'priceIn' => 0.02,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI text embedding model (1536 dimensions) for RAG and semantic search.',
                'params' => ['model' => 'text-embedding-3-small'],
                'meta' => ['dimensions' => 1536],
            ],
        ],
        [
            'id' => 88,
            'service' => 'OpenAI',
            'name' => 'text-embedding-3-large',
            'tag' => 'vectorize',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'text-embedding-3-large',
            'priceIn' => 0.13,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI large text embedding model (3072 dimensions) for high-accuracy RAG.',
                'params' => ['model' => 'text-embedding-3-large'],
                'meta' => ['dimensions' => 3072],
            ],
        ],
        [
            'id' => 180,
            'service' => 'OpenAI',
            'name' => 'GPT-5.4',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.4',
            'priceIn' => 2.50,
            'inUnit' => 'per1M',
            'priceOut' => 15,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.4 - latest flagship model with improved reasoning, document workflows, agentic search, and coding. Configurable reasoning effort.',
                'max_tokens' => 16384,
                'params' => ['model' => 'gpt-5.4'],
                'features' => ['reasoning', 'vision'],
                'meta' => ['context_window' => '270000', 'max_output' => '16384'],
            ],
        ],
        [
            'id' => 181,
            'service' => 'OpenAI',
            'name' => 'GPT-5.4 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.4',
            'priceIn' => 2.50,
            'inUnit' => 'per1M',
            'priceOut' => 15,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.4 for image analysis and vision tasks.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gpt-5.4'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            'id' => 193,
            'service' => 'OpenAI',
            'name' => 'GPT-5.3',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.3',
            'priceIn' => 1.75,
            'inUnit' => 'per1M',
            'priceOut' => 14,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.3 - complex multi-step problem solving where accuracy matters more than speed. 128K context, vision, function calling, web search.',
                'max_tokens' => 16000,
                'params' => ['model' => 'gpt-5.3'],
                'features' => ['reasoning', 'vision'],
                'meta' => ['context_window' => '128000', 'max_output' => '16000'],
            ],
        ],
        [
            'id' => 194,
            'service' => 'OpenAI',
            'name' => 'GPT-5.3 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.3',
            'priceIn' => 1.75,
            'inUnit' => 'per1M',
            'priceOut' => 14,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.3 for image analysis and vision tasks.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gpt-5.3'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            'id' => 151,
            'service' => 'OpenAI',
            'name' => 'gpt-image-1.5',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-image-1.5',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 10,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT Image 1.5 - state-of-the-art image generation and editing. Supports pic2pic via Responses API.',
                'params' => ['model' => 'gpt-image-1.5'],
                'features' => ['image', 'pic2pic'],
                'meta' => ['api' => 'responses'],
            ],
        ],
        // ==================== ANTHROPIC MODELS ====================
        [
            'id' => 160,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.6',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-opus-4-6',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Opus 4.6 - Anthropic\'s most intelligent model for agents and coding. 1M context, 128K max output.',
                'max_tokens' => 128000,
                'params' => ['model' => 'claude-opus-4-6'],
                'features' => ['vision', 'reasoning'],
                'meta' => ['context_window' => '1000000', 'max_output' => '128000'],
            ],
        ],
        [
            'id' => 161,
            'service' => 'Anthropic',
            'name' => 'Claude Sonnet 4.6',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-sonnet-4-6',
            'priceIn' => 3,
            'inUnit' => 'per1M',
            'priceOut' => 15,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Sonnet 4.6 - best combination of speed and intelligence. 1M context, 64K output.',
                'max_tokens' => 64000,
                'params' => ['model' => 'claude-sonnet-4-6'],
                'features' => ['vision', 'reasoning'],
                'meta' => ['context_window' => '1000000', 'max_output' => '64000'],
            ],
        ],
        [
            'id' => 162,
            'service' => 'Anthropic',
            'name' => 'Claude Haiku 4.5',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-haiku-4-5',
            'priceIn' => 1,
            'inUnit' => 'per1M',
            'priceOut' => 5,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Haiku 4.5 - fastest model with near-frontier intelligence. 200K context, 64K output.',
                'max_tokens' => 64000,
                'params' => ['model' => 'claude-haiku-4-5'],
                'features' => ['vision', 'reasoning'],
                'meta' => ['context_window' => '200000', 'max_output' => '64000'],
            ],
        ],
        [
            'id' => 163,
            'service' => 'Anthropic',
            'name' => 'Claude Sonnet 4.6 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-sonnet-4-6',
            'priceIn' => 3,
            'inUnit' => 'per1M',
            'priceOut' => 15,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Sonnet 4.6 for image analysis and vision tasks.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-sonnet-4-6'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            'id' => 164,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.6 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-opus-4-6',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Opus 4.6 for image analysis and vision tasks. Most capable Anthropic vision model.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-opus-4-6'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            'id' => 165,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.7',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-opus-4-7',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Opus 4.7 - Anthropic\'s most capable model for advanced software engineering and complex reasoning. Self-verifies outputs and handles long-running tasks. 1M context, 128K max output.',
                'max_tokens' => 128000,
                'params' => ['model' => 'claude-opus-4-7'],
                'features' => ['vision', 'reasoning'],
                'meta' => ['context_window' => '1000000', 'max_output' => '128000'],
            ],
        ],
        [
            'id' => 166,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.7 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-opus-4-7',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Opus 4.7 for image analysis and vision tasks. Substantially enhanced vision capabilities, supports higher-resolution images (up to 2576px / 3.75MP).',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-opus-4-7'],
                'meta' => ['supports_images' => true],
            ],
        ],
        // ==================== GOOGLE MODELS ====================
        [
            'id' => 37,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Flash TTS',
            'tag' => 'text2sound',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-flash-preview-tts',
            'priceIn' => 0.1,
            'inUnit' => 'per1M',
            'priceOut' => 0.4,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 2.5 Flash Preview TTS (native speech generation)',
                'params' => ['model' => 'gemini-2.5-flash-preview-tts', 'voice' => 'Kore'],
                'features' => ['tts', 'audio'],
            ],
        ],
        [
            'id' => 45,
            'service' => 'Google',
            'name' => 'Veo 3.1',
            'tag' => 'text2vid',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'veo-3.1-generate-preview',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.35,
            'outUnit' => 'persec',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Video Generation model Veo 3.1 - 8 second videos with audio',
                'params' => ['model' => 'veo-3.1-generate-preview'],
            ],
        ],
        [
            'id' => 61,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Pro',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-pro',
            'priceIn' => 2.5,
            'inUnit' => 'per1M',
            'priceOut' => 15,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google\'s Answer to the other LLM models',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-2.5-pro'],
                'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 65,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Pro (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-pro',
            'priceIn' => 2.5,
            'inUnit' => 'per1M',
            'priceOut' => 15,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google\'s Powerhouse can also process images, not just text',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-2.5-pro'],
            ],
        ],
        [
            'id' => 115,
            'service' => 'Google',
            'name' => 'Imagen 4.0',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'imagen-4.0-generate-001',
            'priceIn' => 0.1,
            'inUnit' => 'per1M',
            'priceOut' => 0.4,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Imagen 4.0 image generation',
                'params' => ['model' => 'imagen-4.0-generate-001'],
                'features' => ['image'],
            ],
        ],
        [
            'id' => 118,
            'service' => 'Google',
            'name' => 'Nano Banana (Flash Image)',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-flash-image',
            'priceIn' => 0.1,
            'inUnit' => 'per1M',
            'priceOut' => 0.4,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Nano Banana gemini-2.5-flash-image',
                'params' => ['model' => 'gemini-2.5-flash-image'],
                'features' => ['image', 'pic2pic'],
            ],
        ],
        [
            'id' => 190,
            'service' => 'Google',
            'name' => 'Nano Banana 2 (3.1 Flash Image)',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.1-flash-image-preview',
            'priceIn' => 0.1,
            'inUnit' => 'per1M',
            'priceOut' => 0.4,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Nano Banana 2 - advanced image generation and editing. Up to 4K, 14 reference images, Google Search grounding.',
                'params' => ['model' => 'gemini-3.1-flash-image-preview'],
                'features' => ['image', 'pic2pic'],
                'meta' => ['max_reference_images' => 14],
            ],
        ],
        [
            'id' => 170,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Flash',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-flash',
            'priceIn' => 0.30,
            'inUnit' => 'per1M',
            'priceOut' => 2.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 2.5 Flash - best price-performance model, 1M token context, reasoning, vision, audio.',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-2.5-flash'],
                'features' => ['reasoning', 'vision', 'audio'],
                'meta' => ['context_window' => '1000000', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 171,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Flash (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-flash',
            'priceIn' => 0.30,
            'inUnit' => 'per1M',
            'priceOut' => 2.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 2.5 Flash for image analysis and vision tasks.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-2.5-flash'],
            ],
        ],
        [
            'id' => 185,
            'service' => 'Google',
            'name' => 'Gemini 3.1 Pro',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.1-pro-preview',
            'priceIn' => 2.0,
            'inUnit' => 'per1M',
            'priceOut' => 12,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Pro - most advanced reasoning model, 1M token context, tops 13 of 16 industry benchmarks. Excels at agentic workflows and software engineering.',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-3.1-pro-preview'],
                'features' => ['reasoning', 'vision', 'audio'],
                'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 186,
            'service' => 'Google',
            'name' => 'Gemini 3.1 Pro (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.1-pro-preview',
            'priceIn' => 2.0,
            'inUnit' => 'per1M',
            'priceOut' => 12,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Pro for image analysis, video understanding, and multimodal tasks.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-3.1-pro-preview'],
                'meta' => ['supports_images' => true, 'supports_video' => true],
            ],
        ],
        [
            'id' => 191,
            'service' => 'Google',
            'name' => 'Gemini 3.1 Flash-Lite',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.1-flash-lite-preview',
            'priceIn' => 0.25,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Flash-Lite - most cost-efficient model, optimized for high-volume agentic tasks, translation, and data processing. 1M token context, multimodal input.',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-3.1-flash-lite-preview'],
                'features' => ['vision', 'audio'],
                'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 192,
            'service' => 'Google',
            'name' => 'Gemini 3.1 Flash-Lite (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.1-flash-lite-preview',
            'priceIn' => 0.25,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Flash-Lite for image analysis and vision tasks. Cost-efficient multimodal model.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-3.1-flash-lite-preview'],
                'meta' => ['supports_images' => true, 'supports_video' => true],
            ],
        ],
        // ==================== ANTHROPIC MODELS ====================
        [
            'id' => 92,
            'service' => 'Anthropic',
            'name' => 'Claude 3 Haiku',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-3-haiku-20240307',
            'priceIn' => 0.25,
            'inUnit' => 'per1M',
            'priceOut' => 1.25,
            'outUnit' => 'per1M',
            'quality' => 7,
            'rating' => 2,
            'json' => [
                'description' => 'Claude 3 Haiku - Fast and cost-effective model for everyday tasks. Great for quick responses and simple queries.',
                'max_tokens' => 4096,
                'params' => ['model' => 'claude-3-haiku-20240307'],
                'features' => ['vision'],
                'meta' => ['context_window' => '200000', 'max_output' => '4096'],
            ],
        ],
        [
            'id' => 109,
            'service' => 'Anthropic',
            'name' => 'claude-sonnet-4.5 Vision',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-sonnet-4-5-20250929',
            'priceIn' => 3,
            'inUnit' => 'per1M',
            'priceOut' => 5,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude 4.5 Sonnet for image analysis and vision tasks. Excellent at understanding complex images, charts, diagrams, and extracting text.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-sonnet-4-5-20250929'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            'id' => 112,
            'service' => 'Anthropic',
            'name' => 'claude-sonnet-4.5',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-sonnet-4-5-20250929',
            'priceIn' => 3,
            'inUnit' => 'per1M',
            'priceOut' => 5,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude 4.5 Sonnet - a smart model for complex agents and coding',
                'max_tokens' => 8192,
                'params' => ['model' => 'claude-sonnet-4-5-20250929'],
                'meta' => ['context_window' => '200000', 'max_output' => '8192'],
            ],
        ],
        // ==================== THEHIVE MODELS ====================
        [
            'id' => 130,
            'service' => 'TheHive',
            'name' => 'Flux Schnell',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'flux-schnell',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.01,
            'outUnit' => 'perpic',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'TheHive Flux Schnell - Fast image generation for prototyping. Generates images quickly with good quality.',
                'params' => ['model' => 'flux-schnell', 'width' => 1024, 'height' => 1024],
            ],
        ],
        [
            'id' => 131,
            'service' => 'TheHive',
            'name' => 'Flux Schnell Enhanced',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'flux-schnell-enhanced',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.02,
            'outUnit' => 'perpic',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'TheHive Flux Schnell Enhanced - Photorealistic image generation with enhanced quality.',
                'params' => ['model' => 'flux-schnell-enhanced', 'width' => 1024, 'height' => 1024],
            ],
        ],
        [
            'id' => 132,
            'service' => 'TheHive',
            'name' => 'SDXL',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'sdxl',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.02,
            'outUnit' => 'perpic',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'TheHive SDXL - Stable Diffusion XL for general purpose high-quality image generation.',
                'params' => ['model' => 'sdxl', 'width' => 1024, 'height' => 1024],
            ],
        ],
        [
            'id' => 133,
            'service' => 'TheHive',
            'name' => 'SDXL Enhanced',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'sdxl-enhanced',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.05,
            'outUnit' => 'perpic',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'TheHive SDXL Enhanced - Premium quality image generation with enhanced details and photorealism.',
                'params' => ['model' => 'sdxl-enhanced', 'width' => 1024, 'height' => 1024],
            ],
        ],
        [
            'id' => 134,
            'service' => 'TheHive',
            'name' => 'Custom Emoji',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'emoji',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.01,
            'outUnit' => 'perpic',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'TheHive Emoji Model - Generate custom emojis with transparent backgrounds.',
                'params' => ['model' => 'emoji', 'width' => 512, 'height' => 512],
            ],
        ],
        // ==================== PIPER TTS ====================
        [
            'id' => 140,
            'service' => 'Piper',
            'name' => 'Piper Multi-Language',
            'tag' => 'text2sound',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'piper-multi',
            'priceIn' => 0,
            'inUnit' => 'free',
            'priceOut' => 0,
            'outUnit' => 'free',
            'quality' => 7,
            'rating' => 0.8,
            'json' => [
                'description' => 'Self-hosted Piper TTS via synaplan-tts. Multi-language (en, de, es, tr, ru, fa). Free, no API key required.',
                'params' => [
                    'voices' => ['en_US-lessac-medium', 'de_DE-thorsten-medium', 'es_ES-davefx-medium', 'tr_TR-dfki-medium', 'ru_RU-irina-medium', 'fa_IR-reza_ibrahim-medium'],
                ],
                'features' => ['multilingual', 'self-hosted', 'free'],
            ],
        ],
        // ==================== TRITON MODELS ====================
        [
            'id' => 100,
            'service' => 'triton',
            'name' => 'mistral-7b-instruct-v0.3',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'mistral-7b-instruct-v0.3',
            'priceIn' => 0,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => 'per1M',
            'quality' => 7,
            'rating' => 0.5,
            'json' => [
                'description' => 'Triton Inference Server with vLLM backend',
                'max_tokens' => 32768,
                'features' => ['streaming', 'gpu'],
                'supportsStreaming' => true,
            ],
        ],
        [
            'id' => 101,
            'service' => 'triton',
            'name' => 'gpt-oss-20b',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-oss-20b',
            'priceIn' => 0,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 0.7,
            'json' => [
                'description' => 'Triton Inference Server with vLLM backend',
                'max_tokens' => 16384,
                'features' => ['streaming', 'gpu'],
                'supportsStreaming' => true,
            ],
        ],
        [
            'id' => 102,
            'service' => 'triton',
            'name' => 'bge-m3',
            'tag' => 'vectorize',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'bge-m3',
            'priceIn' => 0,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 0.8,
            'json' => [
                'description' => 'BAAI/bge-m3 dense embeddings (1024-dim)',
                'features' => ['embedding', 'multilingual'],
            ],
        ],
    ];
}
