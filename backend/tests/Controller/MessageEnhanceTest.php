<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/v1/messages/enhance with the test model: a text is required, and
 * an answer that does not rewrite it is rejected.
 */
final class MessageEnhanceTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->authenticateClient($this->client, $this->createUser('enhance'));
    }

    protected function tearDown(): void
    {
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testATextIsRequired(): void
    {
        $this->enhance('');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnAnswerThatIsNoRewriteIsRejected(): void
    {
        // The test model answers with a canned reply, not a rewrite of the text.
        $body = $this->enhance('write mail to boss about holiday next week');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'enhance_rejected'], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function enhance(string $text): array
    {
        $this->client->request('POST', '/api/v1/messages/enhance', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['text' => $text]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
