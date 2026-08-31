<?php

declare(strict_types=1);

namespace App\Monolog;

use App\Observability\RequestIdGenerator;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stamps the current request's correlation id onto every log record's `extra`.
 *
 * Reads the id that {@see \App\EventSubscriber\RequestIdSubscriber} placed on
 * the request attributes. On CLI / worker runs there is no main request, so the
 * id is simply absent — the record is still emitted, just without correlation.
 */
final readonly class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return $record;
        }

        $correlationId = $request->attributes->get(RequestIdGenerator::ATTRIBUTE);
        if (!\is_string($correlationId) || '' === $correlationId) {
            return $record;
        }

        $record->extra['request_id'] = $correlationId;

        return $record;
    }
}
