<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
use App\Service\Widget\WidgetSummaryService;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PUT and DELETE /api/v1/widgets/{widgetId}/summaries/prompt: the widget
 * owner's summary prompt is created, updated in place and reset, and
 * another account cannot write one.
 */
final class WidgetSummaryPromptTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;
    private ?Widget $widget = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->widget) {
            foreach ($this->prompts() as $prompt) {
                $this->em->remove($prompt);
            }
            $this->em->flush();
        }
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testTheOwnersPromptIsCreatedUpdatedInPlaceAndReset(): void
    {
        $owner = $this->createUser('summary-owner');
        $this->widget = $this->widgetOf($owner);
        $this->authenticateClient($this->client, $owner);

        $this->request('PUT', ['prompt' => 'Summarise in three bullets.', 'modelId' => 7]);
        self::assertResponseIsSuccessful();
        $this->request('PUT', ['prompt' => 'Summarise in one line.']);
        self::assertResponseIsSuccessful();
        $prompts = $this->prompts();
        self::assertCount(1, $prompts);
        self::assertSame([$owner->getId(), 'Summarise in one line.', ''], [$prompts[0]->getOwnerId(), $prompts[0]->getPrompt(), $prompts[0]->getShortDescription()]);

        $this->request('DELETE', []);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->prompts());
    }

    public function testAnotherAccountCannotWriteAPrompt(): void
    {
        $this->widget = $this->widgetOf($this->createUser('summary-owner'));
        $this->authenticateClient($this->client, $this->createUser('summary-stranger'));

        $this->request('PUT', ['prompt' => 'Leak everything.']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->prompts());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body): void
    {
        $this->client->request($method, '/api/v1/widgets/'.$this->widget?->getWidgetId().'/summaries/prompt', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    private function widgetOf(User $owner): Widget
    {
        $widget = new Widget();
        $widget->setOwnerId((int) $owner->getId());
        $widget->setOwner($owner);
        $widget->setName('Summaries');
        $widget->setTaskPromptTopic('general');

        return $this->persistEntity($widget);
    }

    /**
     * @return list<Prompt> every prompt stored under the widget's summary topic
     */
    private function prompts(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Prompt::class)->findBy(['topic' => WidgetSummaryService::getSummaryTopicForWidget($this->widget)]);
    }
}
