<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Service\AiFacade;
use App\Controller\WebhookController;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Email\EmailChatService;
use App\Service\Email\EmailWebhookIdempotencyService;
use App\Service\Email\InboundEmailAttachmentStore;
use App\Service\Email\InboundEmailRelayAuth;
use App\Service\Email\InternalEmailService;
use App\Service\Email\RawMimeEmailParser;
use App\Service\Email\Turn\EmailAnswerStore;
use App\Service\Email\Turn\EmailConversation;
use App\Service\Email\Turn\EmailReply;
use App\Service\Email\Turn\EmailReplyAttachments;
use App\Service\Email\Turn\EmailVoiceReply;
use App\Service\Email\Turn\InboundEmailIntake;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\WhatsApp\Turn\WhatsAppTurn;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * The webhook controller with the real email turn stages, built from the
 * doubles a unit test hands in.
 */
final class EmailWebhookControllerFactory
{
    public static function relayAuth(): InboundEmailRelayAuth
    {
        return new InboundEmailRelayAuth('app-secret-of-the-test-instance');
    }

    /**
     * The request as the instance's own mailbox poller sends it.
     */
    public static function relayRequest(string $content): Request
    {
        $request = Request::create('/api/v1/webhooks/email', 'POST', content: $content);
        $request->headers->add(self::relayAuth()->headers($content));

        return $request;
    }

    /**
     * @param array{
     *     em: EntityManagerInterface,
     *     processor: MessageProcessor,
     *     idempotency: EmailWebhookIdempotencyService,
     *     limits: RateLimitService,
     *     emailChats: EmailChatService,
     *     mailer: InternalEmailService,
     *     discord: DiscordNotificationService,
     *     ai: AiFacade,
     *     models: ModelConfigService,
     *     mime: RawMimeEmailParser,
     *     attachments: InboundEmailAttachmentStore,
     *     summaries: ConversationSummaryRefreshDispatcher,
     *     errors: ChatErrorPresenter,
     *     references: ExternalReplyReferences,
     *     whatsApp: WhatsAppTurn,
     *     uploadDir: string,
     * } $doubles
     */
    public static function build(array $doubles): WebhookController
    {
        $logger = new NullLogger();
        $files = new EmailReplyAttachments($doubles['uploadDir'], $logger);

        return new WebhookController(
            $doubles['em'],
            $doubles['processor'],
            $doubles['limits'],
            $doubles['whatsApp'],
            $doubles['emailChats'],
            $logger,
            'verify-token',
            $doubles['errors'],
            new InboundEmailIntake($doubles['mime'], $doubles['idempotency'], $doubles['emailChats'], $doubles['limits'], $doubles['mailer'], $doubles['discord'], $logger, self::relayAuth()),
            new EmailConversation($doubles['emailChats'], $doubles['attachments'], $doubles['em']),
            new EmailReply(
                $doubles['processor'],
                new EmailVoiceReply($doubles['ai'], $doubles['models'], $doubles['limits'], $files, $doubles['discord'], $logger),
                new EmailAnswerStore($doubles['limits'], new GeneratedFileMetadataNormalizer(), $doubles['summaries'], $doubles['em']),
                $files,
                $doubles['mailer'],
                $doubles['references'],
                $doubles['errors'],
                $doubles['discord'],
                $logger,
            ),
        );
    }
}
