<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * Notion is switched off for this installation (MCP client or OAuth connectors).
 * The HTTP layer answers 404 so the app is absent, not a dead control.
 */
final class NotionAppUnavailableException extends \RuntimeException
{
}
