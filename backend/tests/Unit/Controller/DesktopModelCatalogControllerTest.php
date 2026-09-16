<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DesktopModelCatalogController;
use App\Entity\User;
use App\Service\Model\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class DesktopModelCatalogControllerTest extends TestCase
{
    public function testCatalogRequiresAuth(): void
    {
        $catalog = $this->createMock(CapabilityCatalog::class);
        $catalog->expects($this->never())->method('forUser');

        $controller = new DesktopModelCatalogController($catalog);
        $response = $controller->catalog(null);

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_api_key', $data['error']['code']);
    }

    public function testCatalogReturnsPayload(): void
    {
        $user = $this->createMock(User::class);
        $payload = [
            'object' => 'catalog',
            'capabilities' => [
                'CHAT' => [],
                'SOUND2TEXT' => [],
                'TEXT2SOUND' => [],
                'PIC2TEXT' => [],
                'TEXT2PIC' => [],
                'TEXT2VID' => [],
                'VECTORIZE' => [],
                'ANALYZE' => [],
            ],
        ];

        $catalog = $this->createMock(CapabilityCatalog::class);
        $catalog->expects($this->once())->method('forUser')->with($user)->willReturn($payload);

        $controller = new DesktopModelCatalogController($catalog);
        $response = $controller->catalog($user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($payload, json_decode((string) $response->getContent(), true));
    }
}
