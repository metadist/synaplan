<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Tool\Execution\DocumentToolExecutor;
use App\Service\Tool\Execution\ToolInvoker;
use Psr\Log\NullLogger;

/**
 * The document-only invoker the chat loop tests construct by hand.
 */
final class DocumentLoopInvoker
{
    public static function create(DocumentToolRegistry $registry): ToolInvoker
    {
        return new ToolInvoker([new DocumentToolExecutor($registry, new NullLogger())]);
    }
}
