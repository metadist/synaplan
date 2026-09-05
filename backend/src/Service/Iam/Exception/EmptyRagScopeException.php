<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

/**
 * A vector search with zero scopes would be unfiltered. Never run it.
 */
final class EmptyRagScopeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A RAG search must include at least one owner scope.');
    }
}
