<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

/**
 * The services the readiness list reports, in display order, with the copy
 * each row carries.
 *
 * `compose` is the compose service name; `dev_only` marks services that only
 * the dev compose files define, so a command naming them is wrong anywhere
 * else. `profile` is the dev compose profile that starts an optional service,
 * or null when it starts by default.
 */
final class ServiceReadinessCatalog
{
    /**
     * @var array<string, array{name: string, compose: string, dev_only: bool, profile: ?string, unlocks: string, effect: string, docs: ?string}>
     */
    public const SERVICES = [
        'ollama' => [
            'name' => 'Ollama (local AI)',
            'compose' => 'ollama',
            'dev_only' => true,
            'profile' => 'local-ai',
            'unlocks' => 'Private chat and embeddings on this machine.',
            'effect' => 'Local AI models are not available.',
            'docs' => 'modules/local-ai',
        ],
        'qdrant' => [
            'name' => 'Qdrant (memories)',
            'compose' => 'qdrant',
            'dev_only' => false,
            'profile' => null,
            'unlocks' => 'Memories across chats.',
            'effect' => 'Memories are paused.',
            'docs' => null,
        ],
        'whisper' => [
            'name' => 'Whisper (dictation)',
            'compose' => 'whisper-models',
            'dev_only' => true,
            'profile' => null,
            'unlocks' => 'Dictation and voice messages.',
            'effect' => 'Dictation is off.',
            'docs' => null,
        ],
        'tts' => [
            'name' => 'Piper (spoken answers)',
            'compose' => 'tts',
            'dev_only' => false,
            'profile' => null,
            'unlocks' => 'Spoken answers.',
            'effect' => 'Spoken answers are off.',
            'docs' => 'modules/piper-tts',
        ],
        'tika' => [
            'name' => 'Tika (documents)',
            'compose' => 'tika',
            'dev_only' => false,
            'profile' => null,
            'unlocks' => 'Reading PDFs and Office files.',
            'effect' => 'PDFs and Office files cannot be read.',
            'docs' => 'modules/tika',
        ],
        'docling' => [
            'name' => 'Docling (document layout)',
            'compose' => 'docling',
            'dev_only' => true,
            'profile' => 'docling',
            'unlocks' => 'Tables and layout from scanned and complex documents.',
            'effect' => 'Complex documents are read as plain text.',
            'docs' => 'modules/docling',
        ],
        'searxng' => [
            'name' => 'SearXNG (web search)',
            'compose' => 'searxng',
            'dev_only' => true,
            'profile' => 'searxng',
            'unlocks' => 'Web search without an API key.',
            'effect' => 'Web search is off.',
            'docs' => 'modules/searxng',
        ],
        'collabora' => [
            'name' => 'Collabora (office files)',
            'compose' => 'collabora',
            'dev_only' => true,
            'profile' => 'office',
            'unlocks' => 'Editing and converting office files.',
            'effect' => 'Office files open read-only.',
            'docs' => 'modules/office-convert',
        ],
        'centrifugo' => [
            'name' => 'Centrifugo (live updates)',
            'compose' => 'centrifugo',
            'dev_only' => false,
            'profile' => null,
            'unlocks' => 'Live updates and live takeover of widget chats.',
            'effect' => 'Live takeover of widget chats is off.',
            'docs' => null,
        ],
    ];
}
