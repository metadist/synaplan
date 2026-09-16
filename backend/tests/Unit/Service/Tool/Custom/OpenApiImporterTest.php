<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Custom;

use App\Service\Security\SsrfGuard;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\Custom\OpenApiImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenApiImporterTest extends TestCase
{
    public function testPreviewMapsGetReadAndDeleteDestructive(): void
    {
        $importer = new OpenApiImporter($this->createMock(HttpClientInterface::class), new SsrfGuard());
        $result = $importer->previewFromDocument(json_encode([
            'openapi' => '3.0.3',
            'paths' => [
                '/tickets' => [
                    'get' => ['operationId' => 'listTickets', 'summary' => 'List tickets'],
                    'post' => ['operationId' => 'createTicket', 'summary' => 'Create ticket'],
                    'delete' => ['operationId' => 'deleteTicket', 'summary' => 'Delete ticket'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $byId = [];
        foreach ($result['operations'] as $op) {
            $byId[$op['operationId']] = $op;
        }
        $this->assertSame('read', $byId['listTickets']['sideEffect']);
        $this->assertSame('write', $byId['createTicket']['sideEffect']);
        $this->assertSame('destructive', $byId['deleteTicket']['sideEffect']);
        $this->assertSame(0, $result['dropped']);
    }

    public function testRemoteRefIsRejected(): void
    {
        $importer = new OpenApiImporter($this->createMock(HttpClientInterface::class), new SsrfGuard());
        $this->expectException(InvalidToolTemplateException::class);
        $importer->previewFromDocument(json_encode([
            'openapi' => '3.1.0',
            'paths' => [
                '/x' => [
                    'get' => [
                        'operationId' => 'x',
                        'parameters' => [['$ref' => 'http://evil.example/schema.json']],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
    }
}
