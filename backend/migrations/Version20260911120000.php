<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the 2026-09-11 Google Gemini lineup that was missing from the catalog:
 * 3.6 / 3.7 / 3.8 Flash, 3.5 Flash-Lite, Nano Banana 2 Lite, Omni Flash video,
 * and Gemini 3.5 Transcribe.
 *
 * ModelSeeder inserts new BIDs on deploy, but only when the row is absent.
 * This migration inserts the same catalog snapshot so existing installs get
 * the rows even if seed is skipped. Operator flags are never updated
 * (INSERT ... WHERE NOT EXISTS). Raw SQL / parameterized writes only
 * (Galera-safe; no Schema API).
 *
 * IDs were live-probed against the Gemini Developer API on 2026-09-11.
 */
final class Version20260911120000 extends AbstractMigration
{
    /** @var list<int> */
    private const NEW_BIDS = [350, 351, 352, 353, 354, 355, 356, 357, 358, 359, 360];

    public function getDescription(): string
    {
        return 'Insert Gemini 3.6/3.7/3.8 Flash, 3.5 Flash-Lite, Nano Banana 2 Lite, Omni Flash, and Gemini 3.5 Transcribe catalog rows';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $byId = [
            350 => [
                'id' => 350,
                'service' => 'Google',
                'name' => 'Gemini 3.8 Flash',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.8-flash',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 3.75,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.8 Flash - most intelligent Flash workhorse for long-horizon coding, autonomous agents, and complex enterprise workflows. 1M token context. Promotional $0.75/$3.75 through 2026-12-31.',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3.8-flash'],
                    'features' => ['reasoning', 'vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.075,
                    'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
                ],
            ],
            351 => [
                'id' => 351,
                'service' => 'Google',
                'name' => 'Gemini 3.8 Flash (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.8-flash',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 3.75,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.8 Flash for image analysis, video understanding, and multimodal tasks.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gemini-3.8-flash'],
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.075,
                    'meta' => ['supports_images' => true, 'supports_video' => true],
                ],
            ],
            352 => [
                'id' => 352,
                'service' => 'Google',
                'name' => 'Gemini 3.7 Flash',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.7-flash',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 3.75,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.7 Flash - everyday driver for coding, agentic tool use, and reliable multi-step execution. 1M token context. Promotional $0.75/$3.75 through 2026-12-31.',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3.7-flash'],
                    'features' => ['reasoning', 'vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.075,
                    'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
                ],
            ],
            353 => [
                'id' => 353,
                'service' => 'Google',
                'name' => 'Gemini 3.7 Flash (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.7-flash',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 3.75,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.7 Flash for image analysis, video understanding, and multimodal tasks.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gemini-3.7-flash'],
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.075,
                    'meta' => ['supports_images' => true, 'supports_video' => true],
                ],
            ],
            354 => [
                'id' => 354,
                'service' => 'Google',
                'name' => 'Gemini 3.6 Flash',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.6-flash',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 3.75,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.6 Flash - previous-generation Flash balancing speed and multimodal capabilities for general agentic and everyday tasks. 1M token context. Promotional $0.75/$3.75 through 2026-12-31.',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3.6-flash'],
                    'features' => ['reasoning', 'vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.075,
                    'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
                ],
            ],
            355 => [
                'id' => 355,
                'service' => 'Google',
                'name' => 'Gemini 3.6 Flash (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.6-flash',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 3.75,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.6 Flash for image analysis, video understanding, and multimodal tasks.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gemini-3.6-flash'],
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.075,
                    'meta' => ['supports_images' => true, 'supports_video' => true],
                ],
            ],
            356 => [
                'id' => 356,
                'service' => 'Google',
                'name' => 'Gemini 3.5 Flash-Lite',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.5-flash-lite',
                'priceIn' => 0.3,
                'inUnit' => 'per1M',
                'priceOut' => 2.5,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.5 Flash-Lite - fastest, most cost-effective 3.5 model for high-throughput agentic tasks, translation, and data processing. 1M token context.',
                    'max_tokens' => 65536,
                    'params' => ['model' => 'gemini-3.5-flash-lite'],
                    'features' => ['vision', 'audio', 'tool_use'],
                    'cache_read_price_per_1M' => 0.03,
                    'meta' => ['context_window' => '1048576', 'max_output' => '65536'],
                ],
            ],
            357 => [
                'id' => 357,
                'service' => 'Google',
                'name' => 'Gemini 3.5 Flash-Lite (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.5-flash-lite',
                'priceIn' => 0.3,
                'inUnit' => 'per1M',
                'priceOut' => 2.5,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.5 Flash-Lite for image analysis and vision tasks. Cost-efficient multimodal model.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'gemini-3.5-flash-lite'],
                    'features' => ['vision'],
                    'cache_read_price_per_1M' => 0.03,
                    'meta' => ['supports_images' => true, 'supports_video' => true],
                ],
            ],
            358 => [
                'id' => 358,
                'service' => 'Google',
                'name' => 'Nano Banana 2 Lite',
                'tag' => 'text2pic',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.1-flash-lite-image',
                'priceIn' => 0,
                'inUnit' => 'perImage',
                'priceOut' => 0.0336,
                'outUnit' => 'perImage',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Nano Banana 2 Lite - ultra-low latency, cost-effective image generation and editing. $30/1M image tokens; a 1K image is 1120 tokens = $0.0336/image.',
                    'pricing_mode' => 'per_image',
                    'mode_prices' => ['output_cost_per_image' => 0.0336],
                    'params' => ['model' => 'gemini-3.1-flash-lite-image'],
                    'features' => ['image', 'pic2pic'],
                ],
            ],
            359 => [
                'id' => 359,
                'service' => 'Google',
                'name' => 'Gemini Omni Flash',
                'tag' => 'text2vid',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-omni-1.1-flash',
                'priceIn' => 0,
                'inUnit' => '-',
                'priceOut' => 0.1,
                'outUnit' => 'persec',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini Omni 1.1 Flash - video generation and conversational editing with native audio via the Interactions API. 720p: $0.10/sec. 3-10 second clips.',
                    'params' => ['model' => 'gemini-omni-1.1-flash'],
                    'pricing_mode' => 'per_second',
                    'allowed_resolutions' => ['720p'],
                    'default_resolution' => '720p',
                    'resolution_prices' => ['720p' => 0.1],
                    'default_duration' => 8,
                    'max_duration' => 10,
                    'features' => ['audio'],
                ],
            ],
            360 => [
                'id' => 360,
                'service' => 'Google',
                'name' => 'Gemini 3.5 Transcribe',
                'tag' => 'sound2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-3.5-transcribe',
                'priceIn' => 0.003,
                'inUnit' => 'permin',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Gemini 3.5 Transcribe - speech-to-text. ~$0.003/min of audio.',
                    'pricing_mode' => 'per_second',
                    'params' => ['model' => 'gemini-3.5-transcribe'],
                    'features' => [],
                ],
            ],
        ];

        foreach (self::NEW_BIDS as $bid) {
            $this->abortIf(
                !isset($byId[$bid]),
                sprintf('ModelCatalog is missing BID %d — the migration cannot insert it.', $bid),
            );

            /** @var array<string, mixed> $row */
            $row = $byId[$bid];

            $exists = $this->connection->fetchOne('SELECT BID FROM BMODELS WHERE BID = ?', [$bid]);
            if (false !== $exists) {
                continue;
            }

            ModelCatalog::upsert($this->connection, $row);
        }
    }

    public function down(Schema $schema): void
    {
        // Rows stay: BMESSAGES / BUSELOG reference BIDs. Disable via the admin UI
        // or ModelCatalog::RETIREMENTS if a later release withdraws them.
    }
}
