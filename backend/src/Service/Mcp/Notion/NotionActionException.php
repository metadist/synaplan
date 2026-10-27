<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * A Notion action the person asked for did not happen. The message is one
 * sentence for that person and says what did not change.
 */
final class NotionActionException extends \RuntimeException
{
}
