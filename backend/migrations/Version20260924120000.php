<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Author Google Gemini Flash and Mistral cached-input rates and correct xAI
 * grok-4.7 cached-input / long-context pricing on existing installs (#2160).
 *
 * Verified 2026-09-24 against the official pages:
 *
 *   - Google (https://ai.google.dev/gemini-api/docs/pricing, "Context caching
 *     price" row, text/image/video, Standard paid tier):
 *       gemini-2.5-flash       $0.03 / 1M
 *       gemini-2.5-flash-lite  $0.01 / 1M
 *       gemini-3.1-flash-lite  $0.025 / 1M
 *       gemini-3.5-flash       $0.15 / 1M
 *       gemini-3-flash-preview $0.05 / 1M
 *     These ten rows authored no `cache_read_price_per_1M`, so
 *     CostCalculationService fell back to CACHE_READ_DISCOUNT_DEFAULT (0.5x
 *     input) and overcharged every cache hit 5x.
 *
 *   - Mistral (https://docs.mistral.ai/inference/pricing, "Cached input"
 *     column, Standard tier):
 *       Mistral Medium 3.5 (mistral-medium-latest, BIDs 244/248)  $0.15 / 1M
 *       Mistral Large 3    (mistral-large-latest, BID 245)        $0.05 / 1M
 *     Same missing key and the same 5x overcharge as the Gemini rows.
 *
 *   - xAI (https://docs.x.ai/docs/models, Text API pricing table):
 *       grok-4.7 (<200k)  input $2.00, cached input $0.50, output $6.00
 *       grok-4.7 (>=200k) input $4.00, cached input $1.00, output $12.00
 *     BIDs 367/368 authored cache_read $2.00 (the plain input rate) — a 4x
 *     overcharge on every cache hit. The >=200k tier was missing from
 *     CONTEXT_PRICING entirely (undercharge on long prompts); that map is
 *     code-only and needs no migration.
 *
 * A catalog edit alone does NOT reach existing installs while ModelSeeder still
 * recognises the row as unedited — and a bare JSON_SET without refreshing
 * `BJSON.__catalog_fingerprint` freezes the row out of every future catalog
 * update ({@see Version20260907120000}). Writing the full catalog snapshot with
 * a matching fingerprint keeps the row under catalog management.
 *
 * Guarded on the OLD state so an operator who deliberately re-priced a row in
 * the admin UI keeps their value, exactly as a re-seed would leave it:
 *   - Gemini and Mistral: BJSON still has no `cache_read_price_per_1M` (the
 *     overcharging fallback state).
 *   - grok-4.7: `cache_read_price_per_1M` is still the wrong 2.00.
 *   - All: BPRICEIN / BPRICEOUT still equal the catalog rate. The snapshot
 *     writes every catalog-owned column, so without this an operator's own
 *     input/output price would be reset to the catalog value.
 *
 * Idempotent and Galera-safe: raw addSql, no Schema API access (see AGENTS.md —
 * the DBAL comparator throws on that cluster).
 *
 * @see \App\Model\ModelCatalog
 */
final class Version20260924120000 extends AbstractMigration
{
    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    /** Wrong cache-read rate authored on grok-4.7 when the row was added. */
    private const GROK_47_OLD_CACHE_READ = 2.00;

    public function getDescription(): string
    {
        return 'Author Gemini Flash and Mistral cached-input rates and correct grok-4.7 '
            .'cached-input pricing (BIDs 170/171/191/192/223/224/225/226/227/237/244/245/248/367/368) '
            .'with matching BJSON fingerprints (#2160).';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->correctedRows() as $model) {
            $json = $model['json'];
            $json['__catalog_fingerprint'] = $this->fingerprint($model);

            // BSELECTABLE / BACTIVE / BISDEFAULT / BSHOWWHENFREE stay out of the SET
            // clause: they are operator-owned, so an admin's visibility choice survives
            // this correction exactly as it survives a re-seed. Every other
            // catalog-owned column IS written, because the fingerprint hashes all of
            // them — repairing only the cache key would leave the row mismatched again.
            if ('xAI' === $model['service']) {
                $this->addSql(<<<'SQL'
                    UPDATE BMODELS
                       SET BSERVICE = :service,
                           BNAME = :name,
                           BTAG = :tag,
                           BPROVID = :providerId,
                           BPRICEIN = :priceIn,
                           BINUNIT = :inUnit,
                           BPRICEOUT = :priceOut,
                           BOUTUNIT = :outUnit,
                           BQUALITY = :quality,
                           BRATING = :rating,
                           BJSON = :json
                     WHERE BID = :id
                       AND BPROVID = :providerId
                       AND ABS(BPRICEIN - :priceIn) < 0.000001
                       AND ABS(BPRICEOUT - :priceOut) < 0.000001
                       AND ABS(CAST(JSON_UNQUOTE(JSON_EXTRACT(BJSON, '$.cache_read_price_per_1M')) AS DECIMAL(20, 10)) - :oldCacheRead) < 0.000001
                    SQL, [
                    'service' => $model['service'],
                    'name' => $model['name'],
                    'tag' => $model['tag'],
                    'providerId' => $model['providerId'],
                    'priceIn' => $model['priceIn'],
                    'inUnit' => $model['inUnit'],
                    'priceOut' => $model['priceOut'],
                    'outUnit' => $model['outUnit'],
                    'quality' => $model['quality'],
                    'rating' => $model['rating'],
                    'json' => json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                    'id' => $model['id'],
                    'oldCacheRead' => self::GROK_47_OLD_CACHE_READ,
                ]);

                continue;
            }

            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BSERVICE = :service,
                       BNAME = :name,
                       BTAG = :tag,
                       BPROVID = :providerId,
                       BPRICEIN = :priceIn,
                       BINUNIT = :inUnit,
                       BPRICEOUT = :priceOut,
                       BOUTUNIT = :outUnit,
                       BQUALITY = :quality,
                       BRATING = :rating,
                       BJSON = :json
                 WHERE BID = :id
                   AND BPROVID = :providerId
                   AND ABS(BPRICEIN - :priceIn) < 0.000001
                   AND ABS(BPRICEOUT - :priceOut) < 0.000001
                   AND JSON_EXTRACT(BJSON, '$.cache_read_price_per_1M') IS NULL
                SQL, [
                'service' => $model['service'],
                'name' => $model['name'],
                'tag' => $model['tag'],
                'providerId' => $model['providerId'],
                'priceIn' => $model['priceIn'],
                'inUnit' => $model['inUnit'],
                'priceOut' => $model['priceOut'],
                'outUnit' => $model['outUnit'],
                'quality' => $model['quality'],
                'rating' => $model['rating'],
                'json' => json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
                'id' => $model['id'],
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Reinstating the missing Gemini cache keys or the
        // grok-4.7 $2.00 cache rate would restore a multi-x overcharge on rates
        // the providers no longer (or never) billed that way, and re-break the
        // fingerprint.
    }

    /**
     * Snapshots of the fifteen rows exactly as authored in ModelCatalog on
     * 2026-09-24 — values AND json key order, because the fingerprint hashes
     * the encoded payload.
     *
     * @return list<array<string, mixed>>
     */
    private function correctedRows(): array
    {
        return [
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
                    'features' => ['reasoning', 'vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.03,
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
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.03,
                ],
            ],
            [
                'id' => 191,
                'service' => 'Google',
                'name' => 'Gemini 3.1 Flash-Lite',
                'tag' => 'chat',
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
                    'description' => 'Google Gemini 3.1 Flash-Lite - most cost-efficient model, optimized for high-volume agentic tasks, translation, and data processing. 1M token context, multimodal input.',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3.1-flash-lite'],
                    'features' => ['vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.025,
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
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.025,
                    'meta' => ['supports_images' => true, 'supports_video' => true],
                ],
            ],
            [
                'id' => 237,
                'service' => 'Google',
                'name' => 'Gemini 3.5 Flash',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.5-flash',
                'priceIn' => 1.50,
                'inUnit' => 'per1M',
                'priceOut' => 9.00,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.5 Flash - flagship Flash chat tier with 1M token context, reasoning, vision, audio. Opt-in successor to Gemini 2.5 Flash (BID 170).',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3.5-flash'],
                    'features' => ['reasoning', 'vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.15,
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
                'priceIn' => 1.50,
                'inUnit' => 'per1M',
                'priceOut' => 9.00,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.5 Flash for image analysis, video understanding, and multimodal tasks.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gemini-3.5-flash'],
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.15,
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
                'priceIn' => 0.50,
                'inUnit' => 'per1M',
                'priceOut' => 3.00,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3 Flash (preview) - frontier-level performance at a fraction of the cost of larger models. 1M token context, reasoning, vision, audio.',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3-flash-preview'],
                    'features' => ['reasoning', 'vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.05,
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
                'priceIn' => 0.50,
                'inUnit' => 'per1M',
                'priceOut' => 3.00,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3 Flash for image analysis and vision tasks (preview).',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gemini-3-flash-preview'],
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.05,
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
                    'features' => ['vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.01,
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
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.01,
                    'meta' => ['supports_images' => true, 'supports_video' => true],
                ],
            ],
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
                    'cache_read_price_per_1M' => 0.15,
                    'meta' => ['context_window' => '262144', 'max_output' => '8192'],
                    'features' => ['tool_use'],
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
                    'cache_read_price_per_1M' => 0.05,
                    'meta' => ['context_window' => '262144', 'max_output' => '8192'],
                    'features' => ['tool_use'],
                ],
            ],
            [
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
                    'cache_read_price_per_1M' => 0.15,
                ],
            ],
            [
                'id' => 367,
                'service' => 'xAI',
                'name' => 'Grok 4.7',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'grok-4.7',
                'priceIn' => 2.00,
                'inUnit' => 'per1M',
                'priceOut' => 6.00,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'xAI Grok 4.7 - flagship model for coding, agents and professional work with a 500K context window. Text and image input. Reasoning depth is configurable (low, medium, high, xhigh).',
                    'max_tokens' => 32768,
                    'params' => ['model' => 'grok-4.7'],
                    'features' => ['vision', 'reasoning', 'tool_use', 'code', 'multilingual'],
                    'cache_read_price_per_1M' => 0.50,
                    'reasoning_effort_default' => 'high',
                    'meta' => [
                        'context_window' => '500000',
                        'max_output' => '32768',
                        'regions' => 'us-east-1, us-west-2',
                    ],
                ],
            ],
            [
                'id' => 368,
                'service' => 'xAI',
                'name' => 'Grok 4.7 (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'grok-4.7',
                'priceIn' => 2.00,
                'inUnit' => 'per1M',
                'priceOut' => 6.00,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'xAI Grok 4.7 image understanding - describe images and extract text (OCR-style) via the chat endpoint. Max 20 MiB per image, JPEG/PNG only.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'grok-4.7'],
                    'features' => ['vision', 'ocr', 'multilingual'],
                    'cache_read_price_per_1M' => 0.50,
                    'meta' => [
                        'supports_images' => true,
                        'max_image_bytes' => '20971520',
                    ],
                ],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time, so the
     * migration stays self-contained (migrations must not import app code that can
     * drift). Same contract as Version20260907120000 / Version20260910130000.
     *
     * @param array<string, mixed> $row
     */
    private function fingerprint(array $row): string
    {
        $payload = [
            'service' => (string) $row['service'],
            'name' => (string) $row['name'],
            'tag' => (string) $row['tag'],
            'providerId' => (string) $row['providerId'],
            'priceIn' => round((float) $row['priceIn'], self::FINGERPRINT_FLOAT_PRECISION),
            'inUnit' => (string) $row['inUnit'],
            'priceOut' => round((float) $row['priceOut'], self::FINGERPRINT_FLOAT_PRECISION),
            'outUnit' => (string) $row['outUnit'],
            'quality' => round((float) $row['quality'], self::FINGERPRINT_FLOAT_PRECISION),
            'rating' => round((float) $row['rating'], self::FINGERPRINT_FLOAT_PRECISION),
            'json' => $row['json'],
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
        );
    }
}
