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
        // PLAN: multi-task router model (gpt-oss-120b on Groq). Tuned
        // independently of SORT; resolves to a chat-tagged model.
        'PLAN' => 'chat',
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
     * BJSON key that stores the catalog fingerprint of the last successful seed
     * write. ModelSeeder uses it to detect manual UI edits and skip them on
     * future runs (see fingerprint() and ModelSeeder::seed()).
     */
    public const FINGERPRINT_KEY = '__catalog_fingerprint';

    /**
     * Number of decimals used to normalise float fields before fingerprinting.
     * Catalog prices are authored with at most 4 decimals (e.g. 0.092); 6 leaves
     * comfortable headroom and shields the hash from float-string round-trips
     * via Doctrine DBAL.
     */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    /**
     * Insert or update a model row via `INSERT … ON DUPLICATE KEY UPDATE`.
     *
     * Field ownership rules:
     *   - **Catalog-owned** (always overwritten on UPDATE):
     *     BSERVICE, BNAME, BTAG, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT,
     *     BQUALITY, BRATING, BJSON. Truth lives in this class; deploy = update.
     *   - **Operator-owned** (only set on INSERT, NEVER overwritten):
     *     BSELECTABLE, BACTIVE, BISDEFAULT, BSHOWWHENFREE. These can be toggled
     *     by admins via the AdminModelsService UI; container restarts must not
     *     wipe those choices. They are seeded from the catalog on INSERT only
     *     (so a FRESH install reflects the catalog default) and are absent from
     *     the ON DUPLICATE KEY UPDATE clause (so an admin override on an EXISTING
     *     row survives every restart). For BSHOWWHENFREE the catalog default is 0;
     *     a catalog row may opt in with `showWhenFree => 1` (e.g. the free,
     *     self-hosted Ollama bge-m3 embedding model that must stay visible despite
     *     having no per-token price). Bringing existing installs to a new
     *     visibility default is a migration's job, not the seeder's.
     *
     * Every write embeds the catalog fingerprint into BJSON under
     * self::FINGERPRINT_KEY. ModelSeeder reads this back to detect manual UI edits
     * and only re-applies catalog values to rows whose values still match the
     * previously-seeded fingerprint.
     *
     * Returns the MySQL/MariaDB affected-rows value so callers can distinguish
     * inserts from updates:
     *   - 1 → row was inserted
     *   - 2 → existing row was updated (values differed)
     *   - 0 → existing row was unchanged (values identical)
     */
    public static function upsert(Connection $connection, array $model, bool $system = false): int
    {
        $json = is_array($model['json'] ?? null) ? $model['json'] : [];
        $json[self::FINGERPRINT_KEY] = self::fingerprint($model);

        return (int) $connection->executeStatement(
            'INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                (int) ($model['showWhenFree'] ?? 0),
                json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /**
     * Compute a stable fingerprint of a model's catalog-owned fields.
     *
     * The hash deliberately ignores operator-owned columns (BSELECTABLE, BACTIVE,
     * BISDEFAULT, BSHOWWHENFREE) and the fingerprint key itself, so that:
     *   - admins toggling enabled/active flags do NOT shift the fingerprint, and
     *   - the value we write into BJSON.__catalog_fingerprint can be regenerated
     *     deterministically from the row we read back later.
     *
     * Floats are rounded to FINGERPRINT_FLOAT_PRECISION decimals to neutralise the
     * float→string→float round trip performed by the DBAL float type.
     *
     * @param array<string, mixed> $row catalog or DB-shaped row (see ModelSeeder::loadExistingRowsById)
     */
    public static function fingerprint(array $row): string
    {
        $json = is_array($row['json'] ?? null) ? $row['json'] : [];
        unset($json[self::FINGERPRINT_KEY]);

        $payload = [
            'service' => (string) ($row['service'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'tag' => (string) ($row['tag'] ?? ''),
            'providerId' => (string) ($row['providerId'] ?? ''),
            'priceIn' => round((float) ($row['priceIn'] ?? 0.0), self::FINGERPRINT_FLOAT_PRECISION),
            'inUnit' => (string) ($row['inUnit'] ?? ''),
            'priceOut' => round((float) ($row['priceOut'] ?? 0.0), self::FINGERPRINT_FLOAT_PRECISION),
            'outUnit' => (string) ($row['outUnit'] ?? ''),
            'quality' => round((float) ($row['quality'] ?? 0.0), self::FINGERPRINT_FLOAT_PRECISION),
            'rating' => round((float) ($row['rating'] ?? 0.0), self::FINGERPRINT_FLOAT_PRECISION),
            'json' => $json,
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
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
            'name' => 'bge-m3 (Ollama, self-hosted)',
            'tag' => 'vectorize',
            // Selectable in the admin "switch embedding model" dropdown so
            // operators running a private Ollama / GPU server can pin RAG
            // to their own bge-m3 deployment instead of paying
            // per-token to Cloudflare or OpenAI. Same 1024-dim vector space as
            // the Cloudflare bge-m3 (BID 187), so switching between the two
            // is a "free" change from the collection's point of view.
            'selectable' => 1,
            'active' => 1,
            // This is the default VECTORIZE model for self-hosted / local-dev
            // installs (see DefaultModelConfigSeeder). It has no per-token price,
            // so without this opt-in `isHiddenBecauseFree()` would strip it from
            // the user-facing model list at /config/ai-models even though RAG
            // actually depends on it. Keep it visible.
            'showWhenFree' => 1,
            'providerId' => 'bge-m3',
            'priceIn' => 0,
            'inUnit' => 'free',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'BAAI/bge-m3 multilingual embeddings (1024-dim) running on the operator\'s own Ollama server. Free at point of use - ideal when a local GPU is available. Same vector space as Cloudflare bge-m3 (BID 187).',
                'params' => [
                    'model' => 'bge-m3',
                    'input' => [],
                ],
                'features' => ['embedding', 'multilingual'],
                'meta' => ['dimensions' => 1024, 'context_window' => '8192', 'provider' => 'ollama'],
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
        [
            // Snapshot 2026-05-27 (https://console.groq.com/docs/models).
            'id' => 236,
            'service' => 'Groq',
            'name' => 'Llama 3.1 8B Instant',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'llama-3.1-8b-instant',
            'priceIn' => 0.05,
            'inUnit' => 'per1M',
            'priceOut' => 0.08,
            'outUnit' => 'per1M',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'Groq Llama 3.1 8B Instant - fastest production-grade chat model on Groq (~560 t/s). 131K context, best for high-throughput / low-cost routing.',
                'max_tokens' => 32768,
                'params' => [
                    'model' => 'llama-3.1-8b-instant',
                    'reasoning_format' => 'hidden',
                    'messages' => [],
                ],
                // max_output mirrors max_tokens (32768) — the model accepts
                // 131K context in total but caps generated output at 32K.
                'meta' => ['context_window' => '131072', 'max_output' => '32768'],
            ],
        ],
        // Phase 2d: dedicated MEM-tagged models for backgrounded memory
        // extraction. The MEM tag keeps these out of the user-facing chat
        // model picker so picking a heavy chat model (Gemini 3 Pro, Claude
        // Opus, etc.) never cascades into post-stream extraction latency.
        // MemoryExtractionService resolves via
        // ModelConfigService::getDefaultModel('MEM', $userId), which reads
        // BCONFIG.DEFAULTMODEL/MEM (seeded by DefaultModelConfigSeeder via
        // findBidByKey lookup, so the mapping is BID-agnostic at the call
        // site).
        //
        // Three options seeded by default — pick whichever the operator's
        // setup makes cheapest/fastest:
        //   - 220: Groq gpt-oss-120b      (~200 ms TTFT, $0.15/$0.75 per 1M tokens)
        //   - 221: Local Ollama gpt-oss:120b (free, latency depends on the GPU box)
        //   - 222: Anthropic Claude Opus 4.6 (highest quality, slowest)
        [
            'id' => 220,
            'service' => 'Groq',
            'name' => 'gpt-oss-120b',
            'tag' => 'mem',
            'selectable' => 0,
            'active' => 1,
            'providerId' => 'openai/gpt-oss-120b',
            'priceIn' => 0.15,
            'inUnit' => 'per1M',
            'priceOut' => 0.75,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 4,
            'json' => [
                'description' => 'Groq-hosted gpt-oss-120b for memory extraction. Sub-200 ms TTFT — recommended default for the post-stream memory pipeline.',
                'max_tokens' => 4096,
                'is_system' => true,
                'params' => ['model' => 'openai/gpt-oss-120b'],
                'meta' => ['context_window' => '131072', 'max_output' => '16384', 'license' => 'Apache-2.0', 'quantization' => 'TruePoint Numerics'],
            ],
        ],
        [
            'id' => 221,
            'service' => 'Ollama',
            'name' => 'gpt-oss:120b',
            'tag' => 'mem',
            'selectable' => 0,
            'active' => 1,
            'providerId' => 'gpt-oss:120b',
            'priceIn' => 0.05,
            'inUnit' => 'per1M',
            'priceOut' => 0.25,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Local Ollama gpt-oss:120b for memory extraction. Same weights as the Groq option; runs on the operator\'s GPU box (zero per-token cost, latency depends on hardware).',
                'max_tokens' => 4096,
                'is_system' => true,
                'params' => ['model' => 'gpt-oss:120b'],
                'meta' => ['context_window' => '128000', 'max_output' => '16384', 'license' => 'Apache-2.0', 'quantization' => 'MXFP4'],
            ],
        ],
        [
            'id' => 222,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.6',
            'tag' => 'mem',
            'selectable' => 0,
            'active' => 1,
            'providerId' => 'claude-opus-4-6',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Anthropic Claude Opus 4.6 for memory extraction. Highest extraction quality; significantly more expensive than the Groq/Ollama gpt-oss options — pick this only when extraction accuracy matters more than latency.',
                'max_tokens' => 4096,
                'is_system' => true,
                'params' => ['model' => 'claude-opus-4-6'],
                'meta' => ['context_window' => '1000000', 'max_output' => '128000'],
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
            // OpenAI tts-1 is flat $0.015 per 1000 characters → $0.000015
            // per character. Mirrors live BMODELS BID 41.
            'priceIn' => 0.000015,
            'inUnit' => 'perChar',
            'priceOut' => 0,
            'outUnit' => 'perChar',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI\'s text to speech, defaulting on voice NOVA.',
                'pricing_mode' => 'per_character',
                'mode_prices' => [
                    'input_cost_per_character' => 0.000015,
                ],
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
            // OpenAI tts-1-hd is flat $0.03 per 1000 characters → $0.00003
            // per character. Mirrors live BMODELS BID 83.
            'priceIn' => 0.00003,
            'inUnit' => 'perChar',
            'priceOut' => 0,
            'outUnit' => 'perChar',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI high-quality text-to-speech.',
                'pricing_mode' => 'per_character',
                'mode_prices' => [
                    'input_cost_per_character' => 0.00003,
                ],
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
            'id' => 204,
            'service' => 'OpenAI',
            'name' => 'GPT-5.5',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.5',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 30,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.5 - frontier model for complex professional work, coding, long-context retrieval, and tool-heavy agents. 1.05M context, 128K max output, configurable reasoning effort.',
                'max_tokens' => 128000,
                'params' => ['model' => 'gpt-5.5'],
                'features' => ['reasoning', 'vision'],
                'meta' => [
                    'api' => 'responses',
                    'context_window' => '1050000',
                    'max_output' => '128000',
                    'knowledge_cutoff' => '2025-12-01',
                    'reasoning_effort_default' => 'medium',
                ],
            ],
        ],
        [
            'id' => 205,
            'service' => 'OpenAI',
            'name' => 'GPT-5.5 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.5',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 30,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.5 for image analysis and vision tasks. Preserves high visual detail by default and supports large multimodal contexts.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gpt-5.5'],
                'features' => ['reasoning', 'vision'],
                'meta' => [
                    'api' => 'responses',
                    'supports_images' => true,
                    'context_window' => '1050000',
                    'max_output' => '128000',
                    'knowledge_cutoff' => '2025-12-01',
                ],
            ],
        ],
        [
            'id' => 206,
            'service' => 'OpenAI',
            'name' => 'GPT-5.5 Pro',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.5-pro',
            'priceIn' => 30,
            'inUnit' => 'per1M',
            'priceOut' => 180,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.5 Pro - deeper-compute variant for the hardest professional, coding, and reasoning tasks. 1.05M context, 128K max output. Streaming is not supported by the API.',
                'max_tokens' => 128000,
                'params' => ['model' => 'gpt-5.5-pro'],
                'features' => ['reasoning', 'vision'],
                'supportsStreaming' => false,
                'meta' => [
                    'api' => 'responses',
                    'context_window' => '1050000',
                    'max_output' => '128000',
                    'knowledge_cutoff' => '2025-12-01',
                    'reasoning_effort_default' => 'medium',
                ],
            ],
        ],
        [
            'id' => 207,
            'service' => 'OpenAI',
            'name' => 'GPT-5.5 Pro (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.5-pro',
            'priceIn' => 30,
            'inUnit' => 'per1M',
            'priceOut' => 180,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.5 Pro for difficult image analysis and vision tasks that benefit from deeper reasoning.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gpt-5.5-pro'],
                'features' => ['reasoning', 'vision'],
                'supportsStreaming' => false,
                'meta' => [
                    'api' => 'responses',
                    'supports_images' => true,
                    'context_window' => '1050000',
                    'max_output' => '128000',
                    'knowledge_cutoff' => '2025-12-01',
                ],
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
        // ----------------------------------------------------------------
        // GPT-5.4 mini / nano (snapshot 2026-05-27, probed live before
        // seeding via https://developers.openai.com/api/docs/models).
        // ----------------------------------------------------------------
        [
            'id' => 232,
            'service' => 'OpenAI',
            'name' => 'GPT-5.4 mini',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.4-mini',
            'priceIn' => 0.75,
            'inUnit' => 'per1M',
            'priceOut' => 4.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.4 mini - the strongest mini model yet for coding, computer use, and subagents. 400K context, low latency, configurable reasoning effort.',
                'max_tokens' => 128000,
                'params' => ['model' => 'gpt-5.4-mini'],
                'features' => ['reasoning', 'vision'],
                'meta' => [
                    'api' => 'responses',
                    'context_window' => '400000',
                    'max_output' => '128000',
                    'knowledge_cutoff' => '2025-08-31',
                ],
            ],
        ],
        [
            'id' => 233,
            'service' => 'OpenAI',
            'name' => 'GPT-5.4 mini (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.4-mini',
            'priceIn' => 0.75,
            'inUnit' => 'per1M',
            'priceOut' => 4.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.4 mini for image analysis and vision tasks. Cost-efficient multimodal option.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gpt-5.4-mini'],
                'features' => ['reasoning', 'vision'],
                'meta' => [
                    'api' => 'responses',
                    'supports_images' => true,
                    'context_window' => '400000',
                    'max_output' => '128000',
                ],
            ],
        ],
        [
            'id' => 234,
            'service' => 'OpenAI',
            'name' => 'GPT-5.4 nano',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gpt-5.4-nano',
            // Tier-baselined against the published gpt-5.4-mini price
            // (mini is $0.75/$4.50 per1M); nano is the smaller/cheaper
            // sibling. SyncModelPricesCommand will normalise on next run.
            'priceIn' => 0.20,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'OpenAI GPT-5.4 nano - lowest-latency, lowest-cost OpenAI tier for narrow, well-defined tasks.',
                'max_tokens' => 128000,
                'params' => ['model' => 'gpt-5.4-nano'],
                'features' => ['reasoning'],
                'meta' => [
                    'api' => 'responses',
                    'context_window' => '400000',
                    'max_output' => '128000',
                    'knowledge_cutoff' => '2025-08-31',
                ],
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
            'providerId' => 'claude-haiku-4-5-20251001',
            'priceIn' => 1,
            'inUnit' => 'per1M',
            'priceOut' => 5,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Haiku 4.5 - fastest model with near-frontier intelligence. 200K context, 64K output.',
                'max_tokens' => 64000,
                'params' => ['model' => 'claude-haiku-4-5-20251001'],
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
        [
            'id' => 238,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.8',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-opus-4-8',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Opus 4.8 - Anthropic\'s most capable model for complex reasoning, long-horizon agentic coding, and high-autonomy work. Adaptive thinking. 1M context, 128K max output.',
                'max_tokens' => 128000,
                'params' => ['model' => 'claude-opus-4-8'],
                'features' => ['vision', 'reasoning'],
                'meta' => ['context_window' => '1000000', 'max_output' => '128000', 'knowledge_cutoff' => '2026-01-31'],
            ],
        ],
        [
            'id' => 239,
            'service' => 'Anthropic',
            'name' => 'Claude Opus 4.8 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-opus-4-8',
            'priceIn' => 5,
            'inUnit' => 'per1M',
            'priceOut' => 25,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Opus 4.8 for image analysis and vision tasks. Most capable Anthropic vision model.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-opus-4-8'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            // Snapshot 2026-06-09 (https://platform.claude.com/docs/en/about-claude/models/overview).
            // Claude Fable 5 — Anthropic's most capable widely released model.
            // Generally available on the Claude API since 2026-06-09.
            'id' => 240,
            'service' => 'Anthropic',
            'name' => 'Claude Fable 5',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-fable-5',
            'priceIn' => 10,
            'inUnit' => 'per1M',
            'priceOut' => 50,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Fable 5 - Anthropic\'s most capable widely released model for the most demanding reasoning and long-horizon agentic work. Adaptive thinking (always on). 1M context, 128K max output.',
                'max_tokens' => 128000,
                'params' => ['model' => 'claude-fable-5'],
                'features' => ['vision', 'reasoning'],
                'meta' => ['context_window' => '1000000', 'max_output' => '128000'],
            ],
        ],
        [
            'id' => 241,
            'service' => 'Anthropic',
            'name' => 'Claude Fable 5 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-fable-5',
            'priceIn' => 10,
            'inUnit' => 'per1M',
            'priceOut' => 50,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Fable 5 for image analysis and vision tasks. Anthropic\'s most capable widely released vision model.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-fable-5'],
                'meta' => ['supports_images' => true],
            ],
        ],
        [
            // Snapshot 2026-05-27 (https://platform.claude.com/docs/en/about-claude/models/overview).
            'id' => 235,
            'service' => 'Anthropic',
            'name' => 'Claude Haiku 4.5 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'claude-haiku-4-5-20251001',
            'priceIn' => 1,
            'inUnit' => 'per1M',
            'priceOut' => 5,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Claude Haiku 4.5 for image analysis and vision tasks. Fastest Anthropic vision tier with near-frontier intelligence.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'claude-haiku-4-5-20251001'],
                'meta' => ['supports_images' => true, 'context_window' => '200000'],
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
            'name' => 'Veo 3.1 Standard',
            'tag' => 'text2vid',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'veo-3.1-generate-preview',
            'priceIn' => 0,
            'inUnit' => '-',
            // priceOut = headline / fallback per-second rate. Resolution-specific
            // prices in json.resolution_prices override priceOut at billing time
            // (see CostCalculationService::lookupResolutionPrice). The headline
            // matches the cheapest supported tier so list views show the
            // best-case price; actual cost depends on default_resolution.
            'priceOut' => 0.40,
            'outUnit' => 'persec',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Veo 3.1 Standard - highest-quality 4/6/8 second videos with audio. 720p/1080p: $0.40/sec, 4K: $0.60/sec.',
                'params' => ['model' => 'veo-3.1-generate-preview'],
                'pricing_mode' => 'per_second',
                'allowed_resolutions' => ['720p', '1080p', '4K'],
                'default_resolution' => '1080p',
                'resolution_prices' => [
                    '720p' => 0.40,
                    '1080p' => 0.40,
                    '4K' => 0.60,
                ],
            ],
        ],
        [
            'id' => 195,
            'service' => 'Google',
            'name' => 'Veo 3.1 Fast',
            'tag' => 'text2vid',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'veo-3.1-fast-generate-preview',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.10,
            'outUnit' => 'persec',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Veo 3.1 Fast - quicker generations with audio. 720p: $0.10/sec, 1080p: $0.12/sec, 4K: $0.30/sec.',
                'params' => ['model' => 'veo-3.1-fast-generate-preview'],
                'pricing_mode' => 'per_second',
                'allowed_resolutions' => ['720p', '1080p', '4K'],
                'default_resolution' => '1080p',
                'resolution_prices' => [
                    '720p' => 0.10,
                    '1080p' => 0.12,
                    '4K' => 0.30,
                ],
            ],
        ],
        [
            'id' => 196,
            'service' => 'Google',
            'name' => 'Veo 3.1 Lite',
            'tag' => 'text2vid',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'veo-3.1-lite-generate-preview',
            'priceIn' => 0,
            'inUnit' => '-',
            'priceOut' => 0.05,
            'outUnit' => 'persec',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'Google Veo 3.1 Lite - cheapest tier with audio. 720p: $0.05/sec, 1080p: $0.08/sec. 4K not supported.',
                'params' => ['model' => 'veo-3.1-lite-generate-preview'],
                'pricing_mode' => 'per_second',
                'allowed_resolutions' => ['720p', '1080p'],
                'default_resolution' => '1080p',
                'resolution_prices' => [
                    '720p' => 0.05,
                    '1080p' => 0.08,
                ],
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
            // Imagen 4.0 is a flat per-image-fee model in production
            // ($0.04/image standard quality). Mirrors live BMODELS BID 115:
            // priceIn=0 (no input cost), priceOut=0.04 in `perImage` units.
            'priceIn' => 0,
            'inUnit' => 'perImage',
            'priceOut' => 0.04,
            'outUnit' => 'perImage',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Imagen 4.0 image generation',
                'pricing_mode' => 'per_image',
                'mode_prices' => [
                    'output_cost_per_image' => 0.04,
                ],
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
            // BID 170 stays pinned to Gemini 2.5 Flash. Existing widgets that
            // reference BID 170 should keep talking to 2.5 Flash; the newer
            // 3.5 Flash flagship is published as a separate slot (see BID 237
            // below) so consumers opt in instead of being silently swapped.
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
            // BID 171 is the vision twin of BID 170 (Gemini 2.5 Flash). The
            // 3.5 Flash vision pair lives at BID 223 below.
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
            // Google retired the `-preview` alias once 3.1 Flash-Lite went GA;
            // calling it now returns HTTP 404 "no longer available". Use the
            // stable id so the chat dropdown actually round-trips.
            'providerId' => 'gemini-3.1-flash-lite',
            'priceIn' => 0.25,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Flash-Lite - most cost-efficient model, optimized for high-volume agentic tasks, translation, and data processing. 1M token context, multimodal input.',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-3.1-flash-lite'],
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
            'providerId' => 'gemini-3.1-flash-lite',
            'priceIn' => 0.25,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Flash-Lite for image analysis and vision tasks. Cost-efficient multimodal model.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-3.1-flash-lite'],
                'meta' => ['supports_images' => true, 'supports_video' => true],
            ],
        ],
        // ----------------------------------------------------------------
        // Google Gemini 3 Flash family + new image / TTS / Imagen variants
        // surfaced on https://ai.google.dev/gemini-api/docs/models (snapshot
        // 2026-05-19). 3.5 Flash itself gets a dedicated pair at BID 237
        // (chat) + BID 223 (vision) so existing BID 170 / 171 references
        // keep resolving to 2.5 Flash. Prices are tier-baselined against
        // existing siblings; `SyncModelPricesCommand` will normalise them
        // against the live Google price endpoint at next run.
        // ----------------------------------------------------------------
        [
            // 3.5 Flash chat — opt-in upgrade over BID 170 (2.5 Flash). Same
            // price tier and feature surface; chosen by the user via the
            // model picker, never silently swapped.
            'id' => 237,
            'service' => 'Google',
            'name' => 'Gemini 3.5 Flash',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.5-flash',
            'priceIn' => 0.30,
            'inUnit' => 'per1M',
            'priceOut' => 2.50,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.5 Flash - flagship Flash chat tier with 1M token context, reasoning, vision, audio. Opt-in successor to Gemini 2.5 Flash (BID 170).',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-3.5-flash'],
                'features' => ['reasoning', 'vision', 'audio'],
                'meta' => ['context_window' => '1000000', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 223,
            'service' => 'Google',
            'name' => 'Gemini 3.5 Flash (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.5-flash',
            'priceIn' => 0.30,
            'inUnit' => 'per1M',
            'priceOut' => 2.50,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.5 Flash for image analysis, video understanding, and multimodal tasks.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-3.5-flash'],
                'meta' => ['supports_images' => true, 'supports_video' => true],
            ],
        ],
        [
            'id' => 224,
            'service' => 'Google',
            'name' => 'Gemini 3 Flash',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3-flash-preview',
            'priceIn' => 0.30,
            'inUnit' => 'per1M',
            'priceOut' => 2.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3 Flash (preview) - frontier-level performance at a fraction of the cost of larger models. 1M token context, reasoning, vision, audio.',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-3-flash-preview'],
                'features' => ['reasoning', 'vision', 'audio'],
                'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 225,
            'service' => 'Google',
            'name' => 'Gemini 3 Flash (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3-flash-preview',
            'priceIn' => 0.30,
            'inUnit' => 'per1M',
            'priceOut' => 2.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3 Flash for image analysis and vision tasks (preview).',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-3-flash-preview'],
                'meta' => ['supports_images' => true, 'supports_video' => true],
            ],
        ],
        [
            'id' => 226,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Flash-Lite',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-flash-lite',
            'priceIn' => 0.10,
            'inUnit' => 'per1M',
            'priceOut' => 0.40,
            'outUnit' => 'per1M',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 2.5 Flash-Lite - fastest and cheapest multimodal model in the 2.5 family. Good for high-volume agentic / classification tasks.',
                'max_tokens' => 65536,
                'params' => ['model' => 'gemini-2.5-flash-lite'],
                'features' => ['vision', 'audio'],
                'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
            ],
        ],
        [
            'id' => 227,
            'service' => 'Google',
            'name' => 'Gemini 2.5 Flash-Lite (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-2.5-flash-lite',
            'priceIn' => 0.10,
            'inUnit' => 'per1M',
            'priceOut' => 0.40,
            'outUnit' => 'per1M',
            'quality' => 7,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 2.5 Flash-Lite for image analysis - cheapest multimodal option in the 2.5 family.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'gemini-2.5-flash-lite'],
                'meta' => ['supports_images' => true, 'supports_video' => true],
            ],
        ],
        [
            'id' => 228,
            'service' => 'Google',
            'name' => 'Nano Banana Pro',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            // Gemini-native image model — GoogleProvider routes anything
            // matching /^gemini-.*-image/ via generateImageWithGemini(); for
            // nano-banana-pro-preview the routing lookup uses the catalog's
            // json.api override below.
            'providerId' => 'nano-banana-pro-preview',
            'priceIn' => 0,
            'inUnit' => 'perImage',
            'priceOut' => 0.08,
            'outUnit' => 'perImage',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Nano Banana Pro - professional design engine with a reasoning core for 4K studio-quality visuals, complex layouts, and precise text rendering.',
                'pricing_mode' => 'per_image',
                'mode_prices' => [
                    'output_cost_per_image' => 0.08,
                ],
                'api' => 'gemini_native',
                'params' => ['model' => 'nano-banana-pro-preview'],
                'features' => ['image', 'pic2pic', 'text_rendering'],
            ],
        ],
        [
            'id' => 229,
            'service' => 'Google',
            'name' => 'Gemini 3.1 Flash TTS',
            'tag' => 'text2sound',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'gemini-3.1-flash-tts-preview',
            'priceIn' => 0.10,
            'inUnit' => 'per1M',
            'priceOut' => 0.40,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Gemini 3.1 Flash TTS (preview) - powerful low-latency speech generation with expressive audio tags and natural prosody.',
                'params' => ['model' => 'gemini-3.1-flash-tts-preview', 'voice' => 'Kore'],
                'features' => ['tts', 'audio'],
            ],
        ],
        [
            'id' => 230,
            'service' => 'Google',
            'name' => 'Imagen 4.0 Fast',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'imagen-4.0-fast-generate-001',
            'priceIn' => 0,
            'inUnit' => 'perImage',
            'priceOut' => 0.02,
            'outUnit' => 'perImage',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'Google Imagen 4.0 Fast - quicker, cheaper Imagen 4 tier optimised for high-volume image generation.',
                'pricing_mode' => 'per_image',
                'mode_prices' => [
                    'output_cost_per_image' => 0.02,
                ],
                'params' => ['model' => 'imagen-4.0-fast-generate-001'],
                'features' => ['image'],
            ],
        ],
        [
            'id' => 231,
            'service' => 'Google',
            'name' => 'Imagen 4.0 Ultra',
            'tag' => 'text2pic',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'imagen-4.0-ultra-generate-001',
            'priceIn' => 0,
            'inUnit' => 'perImage',
            'priceOut' => 0.06,
            'outUnit' => 'perImage',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Google Imagen 4.0 Ultra - top-quality Imagen 4 tier with exceptional clarity up to 2K resolution.',
                'pricing_mode' => 'per_image',
                'mode_prices' => [
                    'output_cost_per_image' => 0.06,
                ],
                'params' => ['model' => 'imagen-4.0-ultra-generate-001'],
                'features' => ['image'],
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
        [
            'id' => 200,
            'service' => 'HuggingFace',
            'name' => 'Kimi K2.5',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'moonshotai/Kimi-K2.5',
            'priceIn' => 0.383,
            'inUnit' => 'per1M',
            'priceOut' => 1.72,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Kimi K2.5 via HuggingFace - multimodal MoE model with 256K context, native vision, and thinking/non-thinking modes. Routed through HF Inference Providers.',
                'max_tokens' => 16384,
                'params' => ['model' => 'moonshotai/Kimi-K2.5'],
                'features' => ['vision', 'reasoning', 'tool_use'],
                'meta' => ['context_window' => '262144', 'max_output' => '16384', 'routed_via' => 'huggingface'],
            ],
        ],
        [
            'id' => 201,
            'service' => 'HuggingFace',
            'name' => 'Kimi K2.5 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'moonshotai/Kimi-K2.5',
            'priceIn' => 0.383,
            'inUnit' => 'per1M',
            'priceOut' => 1.72,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Kimi K2.5 via HuggingFace for image analysis and vision tasks. Native multimodal with strong OCR and chart reading.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'moonshotai/Kimi-K2.5'],
                'meta' => ['supports_images' => true, 'routed_via' => 'huggingface'],
            ],
        ],
        [
            'id' => 202,
            'service' => 'HuggingFace',
            'name' => 'Kimi K2.6',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'moonshotai/Kimi-K2.6',
            'priceIn' => 0.60,
            'inUnit' => 'per1M',
            'priceOut' => 3.00,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Kimi K2.6 via HuggingFace - 1T parameter MoE (32B active) flagship for long-horizon coding, agentic workflows, and reasoning. 262K context. Routed through HF Inference Providers.',
                'max_tokens' => 16384,
                'params' => ['model' => 'moonshotai/Kimi-K2.6'],
                'features' => ['vision', 'reasoning', 'tool_use'],
                'meta' => ['context_window' => '262144', 'max_output' => '16384', 'routed_via' => 'huggingface'],
            ],
        ],
        [
            'id' => 203,
            'service' => 'HuggingFace',
            'name' => 'Kimi K2.6 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'moonshotai/Kimi-K2.6',
            'priceIn' => 0.60,
            'inUnit' => 'per1M',
            'priceOut' => 3.00,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Kimi K2.6 via HuggingFace for image analysis and vision tasks. Flagship multimodal Kimi model.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'moonshotai/Kimi-K2.6'],
                'meta' => ['supports_images' => true, 'routed_via' => 'huggingface'],
            ],
        ],
        [
            // Snapshot 2026-06-12 (https://huggingface.co/moonshotai/Kimi-K2.7-Code).
            'id' => 242,
            'service' => 'HuggingFace',
            'name' => 'Kimi K2.7 Code',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'moonshotai/Kimi-K2.7-Code',
            'priceIn' => 0.95,
            'inUnit' => 'per1M',
            'priceOut' => 4.00,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Kimi K2.7 Code via HuggingFace - coding-focused 1T MoE (32B active) with 256K context. Always thinks; 30% fewer reasoning tokens than K2.6. Routed through HF Inference Providers.',
                'max_tokens' => 32768,
                'params' => ['model' => 'moonshotai/Kimi-K2.7-Code'],
                'features' => ['vision', 'reasoning', 'tool_use'],
                'meta' => ['context_window' => '262144', 'max_output' => '32768', 'routed_via' => 'huggingface', 'forced_thinking' => true],
            ],
        ],
        [
            'id' => 243,
            'service' => 'HuggingFace',
            'name' => 'Kimi K2.7 Code (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'moonshotai/Kimi-K2.7-Code',
            'priceIn' => 0.95,
            'inUnit' => 'per1M',
            'priceOut' => 4.00,
            'outUnit' => 'per1M',
            'quality' => 10,
            'rating' => 1,
            'json' => [
                'description' => 'Kimi K2.7 Code via HuggingFace for image analysis and vision tasks. Coding-optimised Kimi with MoonViT vision encoder.',
                'prompt' => 'Describe the image in detail. Extract any text you see.',
                'params' => ['model' => 'moonshotai/Kimi-K2.7-Code'],
                'meta' => ['supports_images' => true, 'routed_via' => 'huggingface', 'forced_thinking' => true],
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

        // ==================== CLOUDFLARE WORKERS AI ====================
        [
            'id' => 187,
            'service' => 'Cloudflare',
            'name' => 'bge-m3',
            'tag' => 'vectorize',
            'selectable' => 1,
            'active' => 1,
            'providerId' => '@cf/baai/bge-m3',
            'priceIn' => 0.012,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 1,
            'json' => [
                'description' => 'BAAI/bge-m3 via Cloudflare Workers AI edge network. Fast, low-cost multilingual embeddings (1024-dim). 10k neurons/day free tier.',
                'params' => ['model' => '@cf/baai/bge-m3'],
                'features' => ['embedding', 'multilingual'],
                'meta' => ['dimensions' => 1024, 'context_window' => '60000', 'provider' => 'cloudflare'],
            ],
        ],
        [
            'id' => 188,
            'service' => 'Cloudflare',
            'name' => 'Qwen3-Embedding-0.6B',
            'tag' => 'vectorize',
            'selectable' => 1,
            'active' => 1,
            'providerId' => '@cf/qwen/qwen3-embedding-0.6b',
            'priceIn' => 0.012,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 9,
            'rating' => 1,
            'json' => [
                'description' => 'Qwen3 Embedding 0.6B via Cloudflare Workers AI. Instruction-aware multilingual embeddings (1024-dim). Superior cross-language retrieval for topic routing.',
                'params' => ['model' => '@cf/qwen/qwen3-embedding-0.6b'],
                'features' => ['embedding', 'multilingual', 'instruction-aware'],
                'meta' => ['dimensions' => 1024, 'context_window' => '8192', 'provider' => 'cloudflare'],
            ],
        ],
        // ==================== MISTRAL MODELS ====================
        [
            'id' => 244,
            'service' => 'Mistral',
            'name' => 'Mistral Medium 3.5',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'mistral-medium-latest',
            'priceIn' => 1.50,
            'inUnit' => 'per1M',
            'priceOut' => 7.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 3,
            'json' => [
                'description' => 'Mistral Medium 3.5 - frontier-class multimodal model optimised for agentic and coding use cases. OpenAI-compatible chat endpoint.',
                'max_tokens' => 8192,
                'params' => ['model' => 'mistral-medium-latest'],
                'meta' => ['context_window' => '262144', 'max_output' => '8192'],
            ],
        ],
        [
            'id' => 245,
            'service' => 'Mistral',
            'name' => 'Mistral Large 3',
            'tag' => 'chat',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'mistral-large-latest',
            'priceIn' => 0.50,
            'inUnit' => 'per1M',
            'priceOut' => 1.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 3,
            'json' => [
                'description' => 'Mistral Large 3 - state-of-the-art, open-weight, general-purpose multimodal model. OpenAI-compatible chat endpoint.',
                'max_tokens' => 8192,
                'params' => ['model' => 'mistral-large-latest'],
                'meta' => ['context_window' => '262144', 'max_output' => '8192'],
            ],
        ],
        [
            'id' => 246,
            'service' => 'Mistral',
            'name' => 'Voxtral Mini Transcribe',
            'tag' => 'sound2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'voxtral-mini-latest',
            // Voxtral Mini Transcribe V2 is billed per minute of input audio.
            'priceIn' => 0.003,
            'inUnit' => 'permin',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 2,
            'json' => [
                'description' => 'Voxtral Mini Transcribe - efficient speech-to-text via Mistral /v1/audio/transcriptions. ~4% WER on FLEURS, 13 languages, up to 3h audio per request.',
                'params' => ['model' => 'voxtral-mini-latest'],
                'features' => ['multilingual', 'diarization', 'timestamps'],
            ],
        ],
        [
            'id' => 247,
            'service' => 'Mistral',
            'name' => 'Voxtral TTS',
            'tag' => 'text2sound',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'voxtral-mini-tts-2603',
            // Voxtral TTS is billed per input token (the text to synthesise).
            'priceIn' => 16.0,
            'inUnit' => 'per1M',
            'priceOut' => 0,
            'outUnit' => '-',
            'quality' => 8,
            'rating' => 2,
            'json' => [
                'description' => 'Voxtral TTS - expressive text-to-speech with zero-shot voice cloning and 9-language support via Mistral /v1/audio/speech.',
                'params' => ['model' => 'voxtral-mini-tts-2603'],
                'features' => ['voice-cloning', 'multilingual', 'streaming'],
            ],
        ],
        [
            // Vision variant of Mistral Medium 3.5 (same upstream model id as
            // BID 244). Routed through the OpenAI-compatible chat endpoint with
            // image_url content for image understanding / OCR-style extraction.
            'id' => 248,
            'service' => 'Mistral',
            'name' => 'Mistral Medium 3.5 (Vision)',
            'tag' => 'pic2text',
            'selectable' => 1,
            'active' => 1,
            'providerId' => 'mistral-medium-latest',
            'priceIn' => 1.50,
            'inUnit' => 'per1M',
            'priceOut' => 7.50,
            'outUnit' => 'per1M',
            'quality' => 9,
            'rating' => 2,
            'json' => [
                'description' => 'Mistral Medium 3.5 multimodal vision - describe images and extract text (OCR-style) via the chat endpoint.',
                'max_tokens' => 2048,
                'params' => ['model' => 'mistral-medium-latest'],
                'features' => ['vision', 'ocr', 'multilingual'],
            ],
        ],
    ];
}
