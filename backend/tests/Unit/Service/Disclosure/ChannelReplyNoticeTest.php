<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Disclosure;

use App\Entity\ChannelNotice;
use App\Entity\InboundEmailHandler;
use App\Entity\User;
use App\Repository\ChannelNoticeRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\OutboundSilenceQuery;
use App\Repository\UserRepository;
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

    public function testMailUsesTheHandlerForTheAddressNotTheImapLogin(): void
    {
        $support = $this->handler(4, 'imap-login', 'Support', 'support@acme.test');
        $billing = $this->handler(8, 'billing-login', 'Billing', 'billing@acme.test');
        $supportNotice = $this->saved('Support wrote this.');
        $billingNotice = $this->saved('Billing wrote this.');
        $notices = $this->createStub(ChannelNoticeRepository::class);
        $notices->method('forOwner')->willReturn([$supportNotice, $billingNotice]);
        $notices->method('findOne')->willReturnCallback(
            static fn (int $owner, string $channel, int $binding): ?ChannelNotice => match ($binding) {
                4 => $supportNotice,
                8 => $billingNotice,
                default => null,
            }
        );
        $handlers = $this->createStub(InboundEmailHandlerRepository::class);
        $handlers->method('findByUser')->willReturn([$support, $billing]);
        $notice = $this->withHandlers($notices, $handlers, $this->createStub(UserRepository::class));

        self::assertSame(
            'Support wrote this.',
            $notice->mailSentence(1, 'en', 'Synaplan AI', 'smart+support@synaplan.net'),
        );
        self::assertSame(
            'Billing wrote this.',
            $notice->mailSentence(1, 'en', 'Synaplan AI', 'billing@acme.test'),
        );
    }

    public function testUnsavedMailUsesTheHandlerNameShownInTheForm(): void
    {
        $support = $this->handler(4, 'imap-login', 'Acme Support', 'support@acme.test');
        $notices = $this->createStub(ChannelNoticeRepository::class);
        $notices->method('forOwner')->willReturn([]);
        $notices->method('findOne')->willReturn(null);
        $handlers = $this->createStub(InboundEmailHandlerRepository::class);
        $handlers->method('findByUser')->willReturn([$support]);
        $notice = $this->withHandlers($notices, $handlers, $this->createStub(UserRepository::class));

        self::assertSame(
            'of Acme Support:en',
            $notice->mailSentence(1, 'en', 'Synaplan AI', 'support@acme.test'),
        );
    }

    public function testUnsavedMailWithoutAHandlerNameUsesTheOwnerMailbox(): void
    {
        $support = $this->handler(4, 'imap-login', '', 'support@acme.test');
        $owner = $this->createStub(User::class);
        $owner->method('getMail')->willReturn('ada@acme.test');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($owner);
        $notices = $this->createStub(ChannelNoticeRepository::class);
        $notices->method('forOwner')->willReturn([]);
        $notices->method('findOne')->willReturn(null);
        $handlers = $this->createStub(InboundEmailHandlerRepository::class);
        $handlers->method('findByUser')->willReturn([$support]);
        $notice = $this->withHandlers($notices, $handlers, $users);

        self::assertSame(
            'of ada:en',
            $notice->mailSentence(1, 'en', 'Synaplan AI', 'support@acme.test'),
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
            $this->createStub(UserRepository::class),
        );
    }

    private function withHandlers(
        ChannelNoticeRepository $notices,
        InboundEmailHandlerRepository $handlers,
        UserRepository $users,
    ): ChannelReplyNotice {
        return new ChannelReplyNotice(
            new AiDisclosure($this->translator()),
            $notices,
            $this->createStub(OutboundSilenceQuery::class),
            $handlers,
            $users,
        );
    }

    private function handler(int $id, string $username, string $name, string $email): InboundEmailHandler
    {
        $handler = $this->createStub(InboundEmailHandler::class);
        $handler->method('getId')->willReturn($id);
        $handler->method('getUsername')->willReturn($username);
        $handler->method('getName')->willReturn($name);
        $handler->method('getDepartments')->willReturn([['email' => $email]]);
        $handler->method('getConfig')->willReturn(['smtp' => ['username' => $email]]);

        return $handler;
    }

    private function saved(string $text): ChannelNotice
    {
        $row = new ChannelNotice();
        $row->setText($text);

        return $row;
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
