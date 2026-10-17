<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Service\AiFacade;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\Media\MediaChatHandOff;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\Media\MediaModelResolver;
use App\Service\Message\Handler\Media\MediaPromptResolver;
use App\Service\Message\Handler\Media\MediaReferenceResolver;
use App\Service\Message\Handler\Media\MediaRequestGuard;
use App\Service\Message\Handler\Media\MediaTurnBudget;
use App\Service\Message\Handler\Media\MediaTurnMemory;
use App\Service\Message\Handler\Media\MediaUploadStore;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

/**
 * The media generation handler, built from the doubles a test hands in.
 */
final class MediaGenerationHandlerFactory
{
    /**
     * @param array{
     *     ai: AiFacade,
     *     models: ModelConfigService,
     *     em: EntityManagerInterface,
     *     extractor: MediaPromptExtractor,
     *     downloader: MediaDownloader,
     *     thumbnails: ThumbnailService,
     *     limits: RateLimitService,
     *     memory: PendingMemoryExtraction,
     *     cancellation: MediaCancellationStore,
     *     jobConfig: MediaJobConfig,
     *     jobs: MediaJobService,
     *     jobDispatcher: MediaJobDispatcher,
     *     jobSync: MediaJobMessageSync,
     *     registrar: GeneratedFileRegistrar,
     *     gate: PremiumFeatureGate,
     *     catalog: ConversationFileCatalog,
     *     uploadDir: string,
     *     publicBaseUrl: string,
     *     folders: ?RequestedFolderDelivery,
     *     chat: ?ChatHandler,
     * } $doubles
     */
    public static function build(array $doubles): MediaGenerationHandler
    {
        $logger = new NullLogger();
        $uploads = new MediaUploadStore(new UserUploadPathBuilder(), $doubles['downloader'], $logger, $doubles['uploadDir'], $doubles['publicBaseUrl']);

        return new MediaGenerationHandler(
            $doubles['ai'],
            new MediaModelResolver($doubles['models'], $doubles['em'], $logger),
            $doubles['em'],
            $logger,
            new MediaPromptResolver($doubles['extractor'], $logger),
            $uploads,
            $doubles['thumbnails'],
            $doubles['limits'],
            new MediaErrorMessageBuilder(),
            new MediaTurnMemory($doubles['memory'], $doubles['em']),
            $doubles['cancellation'],
            $doubles['jobConfig'],
            $doubles['jobs'],
            $doubles['jobDispatcher'],
            $doubles['jobSync'],
            $doubles['registrar'],
            new MediaTurnBudget($doubles['em'], $doubles['limits'], $doubles['gate'], $logger),
            new MediaReferenceResolver($doubles['catalog'], $logger, $doubles['uploadDir']),
            new MediaRequestGuard($doubles['ai'], $uploads, $logger, $doubles['publicBaseUrl']),
            new MediaChatHandOff($logger, $doubles['chat']),
            $doubles['uploadDir'],
            $doubles['folders'],
        );
    }
}
