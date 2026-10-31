<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderKeyPresentation;
use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The AI Services tab: the local Ollama server, the cloud provider keys,
 * self-hosted Triton, image and video providers, Cloudflare embeddings and
 * text-to-speech.
 *
 * Every provider key field comes from the provider's descriptor
 * ({@see ProviderKeyPresentation::$configSection} places it, the descriptions
 * are its texts). Such a key is stored encrypted in BCONFIG by
 * ProviderKeyStore and applies without a restart; the schema marks it
 * `managedBy`, so its status comes from the store and a write here is
 * refused with a pointer to Providers & keys.
 */
#[AsTaggedItem(priority: 130)]
final readonly class AiTab implements SystemConfigTab
{
    public function __construct(
        #[Autowire('%synaplan_tts_url_default%')]
        private string $defaultTtsUrl,
        private ProviderCatalog $catalog,
    ) {
    }

    public function id(): string
    {
        return 'ai';
    }

    public function label(): string
    {
        return 'AI Services';
    }

    public function sections(): array
    {
        return [
            'ollama' => $this->ollama(),
            'cloud' => $this->cloud(),
            'selfhosted' => $this->selfhosted(),
            'media' => $this->media(),
            'embeddings' => $this->embeddings(),
            'tts' => $this->tts(),
        ];
    }

    private function ollama(): ConfigSection
    {
        return new ConfigSection('Local AI (Ollama)', [
            'OLLAMA_BASE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Ollama server URL',
                default: 'http://ollama:11434',
            ),
        ]);
    }

    private function cloud(): ConfigSection
    {
        return new ConfigSection('Cloud AI Providers', [
            ...$this->keyFields(ProviderKeyPresentation::SECTION_CLOUD),
            'GOOGLE_VERTEX_ACCESS_TOKEN' => new FieldDefinition(
                type: 'password',
                description: 'Optional OAuth bearer for Vertex AI Imagen; leave empty to use Gemini API (Imagen 4) with the key above',
                sensitive: true,
            ),
        ]);
    }

    private function selfhosted(): ConfigSection
    {
        return new ConfigSection('Self-Hosted AI', [
            'TRITON_SERVER_URL' => new FieldDefinition(
                type: 'url',
                description: 'NVIDIA Triton gRPC endpoint',
            ),
        ]);
    }

    private function media(): ConfigSection
    {
        return new ConfigSection('Image & Video Generation', $this->keyFields(ProviderKeyPresentation::SECTION_MEDIA));
    }

    private function embeddings(): ConfigSection
    {
        return new ConfigSection('Embeddings (Cloudflare Workers AI)', [
            'CLOUDFLARE_ACCOUNT_ID' => new FieldDefinition(
                type: 'text',
                description: 'Cloudflare account ID for Workers AI embeddings (bge-m3)',
            ),
            'CLOUDFLARE_API_TOKEN' => new FieldDefinition(
                type: 'password',
                description: 'Cloudflare API token with Workers AI access',
                sensitive: true,
            ),
            'EMBEDDING_FALLBACK_PROVIDER' => new FieldDefinition(
                type: 'text',
                description: 'Provider to try when the primary embedding fails (e.g. "cloudflare"); empty disables fallback',
            ),
        ]);
    }

    private function tts(): ConfigSection
    {
        return new ConfigSection('Text-to-Speech', [
            'SYNAPLAN_TTS_URL' => new FieldDefinition(
                type: 'url',
                description: 'Synaplan TTS service URL (self-hosted, Piper-based)',
                default: $this->defaultTtsUrl,
            ),
            ...$this->keyFields(ProviderKeyPresentation::SECTION_TTS),
        ]);
    }

    /**
     * The password fields of every platform key the descriptors place in a
     * section, in the descriptors' order; a key + secret pair yields two.
     *
     * @return array<string, FieldDefinition>
     */
    private function keyFields(string $section): array
    {
        $placed = array_filter(
            $this->catalog->withKey(),
            static fn (ProviderDescriptor $d): bool => $section === $d->key?->presentation->configSection,
        );
        usort($placed, static fn (ProviderDescriptor $a, ProviderDescriptor $b): int => ($a->key?->presentation->configOrder ?? 0) <=> ($b->key?->presentation->configOrder ?? 0));

        $fields = [];
        foreach ($placed as $descriptor) {
            $key = $descriptor->key;
            if (null === $key || null === $key->envVar) {
                continue;
            }
            $fields[$key->envVar] = new FieldDefinition(
                type: 'password',
                description: $key->presentation->fieldDescription ?? sprintf('%s API key', $descriptor->displayName),
                sensitive: true,
            );
            if (null !== $key->secretEnvVar) {
                $fields[$key->secretEnvVar] = new FieldDefinition(
                    type: 'password',
                    description: $key->presentation->secretFieldDescription ?? sprintf('%s API secret', $descriptor->displayName),
                    sensitive: true,
                );
            }
        }

        return $fields;
    }
}
