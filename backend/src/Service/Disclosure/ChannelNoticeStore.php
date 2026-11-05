<?php

declare(strict_types=1);

namespace App\Service\Disclosure;

use App\Entity\ChannelNotice;
use App\Entity\User;
use App\Repository\ChannelNoticeRepository;
use App\Repository\InboundEmailHandlerRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads and writes the owner's AI-notice sentence for one channel binding.
 */
final readonly class ChannelNoticeStore
{
    public function __construct(
        private ChannelNoticeRepository $notices,
        private InboundEmailHandlerRepository $handlers,
        private AiDisclosure $disclosure,
    ) {
    }

    /**
     * @return array{text: string, defaultText: string}
     */
    public function read(User $user, string $channel, int $bindingId, string $locale): array
    {
        $this->assertBinding($user, $channel, $bindingId);
        $row = $this->notices->findOne((int) $user->getId(), $channel, $bindingId);

        return [
            'text' => $row instanceof ChannelNotice ? $row->getText() : '',
            'defaultText' => $this->disclosure->sentence($channel, $locale, $this->senderName($user, $channel, $bindingId)),
        ];
    }

    public function write(User $user, string $channel, int $bindingId, string $text): void
    {
        $this->assertBinding($user, $channel, $bindingId);
        $text = trim($text);
        AiDisclosure::rejectEmpty($text);

        $row = $this->notices->findOne((int) $user->getId(), $channel, $bindingId);
        if (!$row instanceof ChannelNotice) {
            $row = new ChannelNotice();
            $row->setOwnerId((int) $user->getId());
            $row->setChannel($channel);
            $row->setBindingId($bindingId);
        }
        $row->setText($text);
        $this->notices->save($row);
    }

    private function assertBinding(User $user, string $channel, int $bindingId): void
    {
        if (!in_array($channel, AiDisclosure::CHANNELS, true) || $bindingId < 0) {
            throw new NotFoundHttpException('Unknown channel.');
        }
        if (AiDisclosure::MAIL === $channel && $bindingId > 0) {
            $handler = $this->handlers->findByIdAndUser($bindingId, (int) $user->getId());
            if (null === $handler) {
                throw new NotFoundHttpException('Mail handler not found.');
            }
        }
    }

    private function senderName(User $user, string $channel, int $bindingId): string
    {
        if (AiDisclosure::MAIL === $channel && $bindingId > 0) {
            $handler = $this->handlers->findByIdAndUser($bindingId, (int) $user->getId());
            $name = null !== $handler ? trim($handler->getName()) : '';
            if ('' !== $name) {
                return $name;
            }
        }

        $mail = $user->getMail();
        $at = strpos($mail, '@');

        return false === $at ? 'Synaplan' : substr($mail, 0, $at);
    }
}
