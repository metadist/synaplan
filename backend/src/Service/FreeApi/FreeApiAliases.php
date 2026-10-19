<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * The Demo aliases of contract/v1/aliases.json, as this install uses them:
 * which BMODELS tag each alias is imported with and which DEFAULTMODEL
 * purposes it takes over while the Demo is active (plan §5.4).
 *
 * Speech (SOUND2TEXT, TEXT2SOUND) is not part of the Demo and stays local.
 */
final class FreeApiAliases
{
    /** @var array<string, string> alias => BMODELS tag */
    public const TAGS = [
        'free-chat' => 'chat',
        'free-sort' => 'chat',
        'free-vision' => 'pic2text',
        'free-embed' => 'vectorize',
        'free-image' => 'text2pic',
    ];

    /** @var array<string, string> DEFAULTMODEL capability => alias */
    public const BINDINGS = [
        'CHAT' => 'free-chat',
        'TOOLS' => 'free-chat',
        'ANALYZE' => 'free-chat',
        'SORT' => 'free-sort',
        'PLAN' => 'free-sort',
        'SUMMARIZE' => 'free-sort',
        'MEM' => 'free-sort',
        'PIC2TEXT' => 'free-vision',
        'TEXT2PIC' => 'free-image',
        'VECTORIZE' => 'free-embed',
    ];

    /** Changing the embedding model would orphan every stored vector, so it only moves while none exist. */
    public const VECTORIZE = 'VECTORIZE';

    /** Endpoint capabilities in the registry's vocabulary. */
    public const ENDPOINT_CAPABILITIES = ['chat', 'vectorize', 'pic2text', 'text2pic'];
}
