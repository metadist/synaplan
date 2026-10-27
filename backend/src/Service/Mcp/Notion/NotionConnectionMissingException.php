<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * A writing change was asked for before Notion is connected.
 */
final class NotionConnectionMissingException extends \RuntimeException
{
}
