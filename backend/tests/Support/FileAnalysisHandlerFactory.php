<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Service\AiFacade;
use App\Service\Context\ContextCondenser;
use App\Service\Context\ModelContextWindow;
use App\Service\File\ConversationFileCatalog;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\FileAnalysis\AnalysisFileResolver;
use App\Service\Message\FileAnalysis\DocumentAnalysis;
use App\Service\Message\FileAnalysis\FileAnalysisFailureCopy;
use App\Service\Message\FileAnalysis\ImageAnalysis;
use App\Service\Message\FileAnalysis\VoiceMessageReply;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\ModelConfigService;
use App\Service\Stt\WhisperService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a FileAnalysisHandler from its leaf dependencies, so the handler's
 * own constructor can follow its collaborators without every test changing.
 */
final class FileAnalysisHandlerFactory
{
    public static function create(
        AiFacade $aiFacade,
        ModelConfigService $modelConfigService,
        LoggerInterface $logger,
        string $uploadDir = '/var/www/backend/var/uploads',
        ?ContextCondenser $contextCondenser = null,
        ?ModelContextWindow $modelContextWindow = null,
        ChatFailureClassifier $failureClassifier = new ChatFailureClassifier(),
        ?ChatErrorPresenter $chatErrorPresenter = null,
        ?TranslatorInterface $translator = null,
        ?ConversationFileCatalog $conversationFileCatalog = null,
        ?WhisperService $whisperService = null,
    ): FileAnalysisHandler {
        $failures = new FileAnalysisFailureCopy($aiFacade, $logger, $chatErrorPresenter, $translator, $whisperService);

        return new FileAnalysisHandler(
            new AnalysisFileResolver($conversationFileCatalog),
            new DocumentAnalysis($aiFacade, $modelConfigService, $logger, $failureClassifier, $contextCondenser, $modelContextWindow),
            new VoiceMessageReply($aiFacade, $modelConfigService, $logger),
            new ImageAnalysis($aiFacade, $modelConfigService, $failures, $logger, $uploadDir),
            $failures,
            $logger,
        );
    }
}
