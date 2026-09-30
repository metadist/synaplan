<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\SlashCommandCopy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class SlashCommandCopyTest extends TestCase
{
    public function testNeedsArgumentNamesTheCommandAndGivesAnExample(): void
    {
        $copy = new SlashCommandCopy($this->translator());

        $text = $copy->needsArgument('pic', 'en');

        self::assertStringContainsString('/pic', $text);
        self::assertStringContainsString('a dog on the beach', $text);
    }

    public function testDocsNotFoundIsLocalized(): void
    {
        $copy = new SlashCommandCopy($this->translator());

        self::assertStringContainsString('No matching file', $copy->docsNotFound('en'));
        self::assertStringContainsString('Wissensbasis', $copy->docsNotFound('de'));
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = dirname(__DIR__, 4).'/translations';
        foreach (['en', 'de', 'es', 'fr', 'tr'] as $locale) {
            $translator->addResource('yaml', $dir.'/slash.'.$locale.'.yaml', $locale, 'slash');
        }

        return $translator;
    }
}
