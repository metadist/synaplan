<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Disclosure;

use App\Repository\ChannelNoticeRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\OutboundSilenceQuery;
use App\Service\Disclosure\AiDisclosure;
use App\Service\Disclosure\ChannelReplyNotice;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ChannelReplyNoticeTest extends TestCase
{
    public function testMailCarriesTheSentenceEveryTime(): void
    {
        $notice = $this->notice(false);

        self::assertSame('of Synaplan:en', $notice->mailSentence(1, 'en', 'Synaplan'));
    }

    public function testChatPrependsOnlyWhenTheSilenceClockIsDue(): void
    {
        $quiet = $this->notice(false);
        self::assertSame('Hello', $quiet->prependIfDue('telegram', 1, 9, 'peer', 'en', 'Synaplan', 'Hello'));

        $due = $this->notice(true);
        self::assertSame(
            "I am Synaplan:en\n\nHello",
            $due->prependIfDue('whatsapp', 1, 9, 'peer', 'en', 'Synaplan', 'Hello'),
        );
    }

    private function notice(bool $due): ChannelReplyNotice
    {
        $notices = $this->createStub(ChannelNoticeRepository::class);
        $notices->method('forOwner')->willReturn([]);
        $notices->method('findOne')->willReturn(null);
        $silence = $this->createStub(OutboundSilenceQuery::class);
        $silence->method('isDue')->willReturn($due);

        return new ChannelReplyNotice(
            new AiDisclosure($this->translator()),
            $notices,
            $silence,
            $this->createStub(InboundEmailHandlerRepository::class),
        );
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
