<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Disclosure;

use App\Exception\EmptyAiNoticeException;
use App\Service\Disclosure\AiDisclosure;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiDisclosureTest extends TestCase
{
    public function testCustomSentenceWins(): void
    {
        $disclosure = new AiDisclosure($this->translator());

        self::assertSame('Our own sentence.', $disclosure->sentence('mail', 'en', 'Acme', 'Our own sentence.'));
    }

    public function testEmptyCustomFallsThroughToTheDefault(): void
    {
        $disclosure = new AiDisclosure($this->translator());

        self::assertSame('of Acme:de', $disclosure->sentence('mail', 'de-DE', 'Acme', '   '));
    }

    public function testUnknownLocaleUsesEnglish(): void
    {
        $disclosure = new AiDisclosure($this->translator());

        self::assertSame('I am Bot:en', $disclosure->sentence('telegram', 'pt', 'Bot'));
    }

    public function testRejectEmpty(): void
    {
        $this->expectException(EmptyAiNoticeException::class);
        AiDisclosure::rejectEmpty('  ');
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $params, ?string $domain, ?string $locale): string {
                $template = match ($id) {
                    'disclosure.mail' => 'of %sender%',
                    'disclosure.chat' => 'I am %name%',
                    default => $id,
                };

                return strtr($template, $params).':'.$locale;
            }
        );

        return $translator;
    }
}
