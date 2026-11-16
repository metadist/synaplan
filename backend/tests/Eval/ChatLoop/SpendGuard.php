<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * Refuses to start a run that would call a provider outside the stack unless
 * the caller said so. The stack's built-in test models have negative ids;
 * every other model is treated as one that may cost money.
 *
 * It sees the defaults the account can choose. Planning, tool and summary
 * models are instance settings it cannot read, and a configured search
 * provider is not a model at all; {@see CorpusRunner} therefore also stops a
 * run that is not live at the first turn the account was charged for.
 */
final class SpendGuard
{
    /** What a corpus turn can call: sorting, the answer, memories, and reading the files it attaches. */
    public const CORPUS = ['SORT', 'CHAT', 'MEM', 'ANALYZE', 'VECTORIZE', 'PIC2TEXT'];
    /** The long chat also generates a picture and edits it. */
    public const SCRIPT = [...self::CORPUS, 'TEXT2PIC', 'PIC2PIC'];

    private const ANSWERING = 'CHAT';

    /**
     * @param array<string, int> $defaultModels capability => model id of the account
     * @param list<string>       $capabilities  what the selected turns can call
     */
    public static function check(array $defaultModels, bool $live, array $capabilities = self::CORPUS): void
    {
        if ($live) {
            return;
        }
        if (!isset($defaultModels[self::ANSWERING])) {
            throw new \RuntimeException('The stack did not say which model answers this account, so the run cannot be known to be free. Pass --live to run anyway. No turn was sent.');
        }
        $outside = [];
        foreach ($capabilities as $capability) {
            if (($defaultModels[$capability] ?? -1) > 0) {
                $outside[] = $capability.' (model '.$defaultModels[$capability].')';
            }
        }
        if ([] !== $outside) {
            throw new \RuntimeException('This account does not answer with the built-in test models: '.implode(', ', $outside).'. That can cost money. Pass --live to spend its budget. No turn was sent.');
        }
    }
}
