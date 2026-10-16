<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Built-in live-voice rosters. The first id is the default: the session
 * service uses it when the saved choice is missing or not in the list.
 *
 * GPT-Live names: https://developers.openai.com/api/docs/guides/live-conversations
 * Realtime names: https://developers.openai.com/api/docs/guides/realtime-conversations
 * Gemini names: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/live-api/configure-language-voice
 * Grok names: https://docs.x.ai/developers/model-capabilities/audio/voice
 */
final class LiveVoiceRosters
{
    /**
     * @var list<string>
     */
    public const GPT_LIVE = [
        'marin',
        'quartz',
        'ripple',
        'vesper',
        'willow',
        'stone',
        'gleam',
        'meridian',
        'bossa',
        'tempo',
        'beacon',
        'delta',
        'cinder',
    ];

    /**
     * Shared by Realtime 2.1 and Realtime 2.1 mini.
     *
     * @var list<string>
     */
    public const REALTIME = [
        'marin',
        'alloy',
        'ash',
        'ballad',
        'coral',
        'echo',
        'sage',
        'shimmer',
        'verse',
        'cedar',
    ];

    /**
     * Shared by Gemini 3.8 Live and the extended-thinking variant.
     * Gemini matches these names with their capital letters.
     *
     * @var list<string>
     */
    public const GEMINI = [
        'Puck',
        'Zephyr',
        'Kore',
        'Orus',
        'Autonoe',
        'Umbriel',
        'Erinome',
        'Laomedeia',
        'Schedar',
        'Achird',
        'Sadachbia',
        'Fenrir',
        'Aoede',
        'Enceladus',
        'Algieba',
        'Algenib',
        'Achernar',
        'Gacrux',
        'Zubenelgenubi',
        'Sadaltager',
        'Charon',
        'Leda',
        'Callirrhoe',
        'Iapetus',
        'Despina',
        'Rasalgethi',
        'Alnilam',
        'Pulcherrima',
        'Vindemiatrix',
        'Sulafat',
    ];

    /**
     * Grok Voice 3 uses the same built-in voices as xAI text-to-speech.
     * Eve stays first so an existing conversation keeps that voice.
     *
     * @var list<string>
     */
    public const GROK = [
        'eve',
        'ara',
        'rex',
        'sal',
        'leo',
        'carina',
        'zagan',
        'helix',
        'orion',
        'luna',
        'iris',
        'altair',
        'zenith',
        'perseus',
        'helios',
        'lux',
        'kepler',
        'rigel',
        'cosmo',
        'celeste',
        'ursa',
        'sirius',
        'lumen',
        'castor',
        'naksh',
        'atlas',
        'aurora',
        'liora',
    ];
}
