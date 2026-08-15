<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\DestinationProvider;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\DestinationResult;
use App\Service\Destination\ShareableFile;
use App\Service\Destination\UnknownDestinationException;
use PHPUnit\Framework\TestCase;

final class DestinationRegistryTest extends TestCase
{
    public function testResolvesByIdAndRejectsUnknown(): void
    {
        $email = new class implements DestinationProvider {
            public function id(): string
            {
                return 'email';
            }

            public function send(ShareableFile $file, array $params): DestinationResult
            {
                return DestinationResult::success('ok');
            }
        };

        $registry = new DestinationRegistry([$email]);
        $this->assertSame('email', $registry->get('email')->id());
        $this->expectException(UnknownDestinationException::class);
        $registry->get('webdav');
    }

    public function testFailureVocabularyIsClosed(): void
    {
        $codes = array_map(static fn (DestinationFailureCode $c): string => $c->value, DestinationFailureCode::cases());
        $this->assertSame(
            ['unauthorized', 'not_found', 'quota_exceeded', 'too_large', 'unreachable', 'conflict', 'rate_limited'],
            $codes
        );
    }
}
