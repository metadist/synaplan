<?php

declare(strict_types=1);

namespace App\Service\PlatformLink\Exception;

/**
 * The configured cloud's app could not report what it takes. The status is
 * the HTTP answer: the report is missing, the token is not that cloud's, or
 * the body is not a report.
 */
final class OidcCloudReportException extends \RuntimeException
{
    public const NOT_CONFIGURED = 404;

    public const NOT_THIS_CLOUD = 403;

    public const BAD_REPORT = 422;

    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
