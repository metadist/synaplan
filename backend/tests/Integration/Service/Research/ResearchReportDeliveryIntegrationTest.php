<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Research;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchReportDelivery;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The report reaches the chat as the assistant message. Sources stay on the
 * message; no markdown file is written.
 */
final class ResearchReportDeliveryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private ResearchStateStore $store;

    private ?ResearchRunState $state = null;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->store = static::getContainer()->get(ResearchStateStore::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->state) {
            try {
                $this->store->delete($this->state->ref->id);
            } catch (\Throwable) {
                // Redis is gone; its keys expire on their own.
            }
        }
        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testACompleteRunPostsTheReportInThePersonsLanguageAndWritesNoFile(): void
    {
        $user = (new User())
            ->setCreated(date('Y-m-d H:i:s'))
            ->setType('USER')
            ->setMail('research-delivery-'.bin2hex(random_bytes(4)).'@test.synaplan.local')
            ->setPw(null)
            ->setProviderId('test-'.bin2hex(random_bytes(4)))
            ->setUserLevel('USER');
        $user->applySignupLanguage('de');
        $this->em->persist($user);
        $this->em->flush();
        $this->created[] = $user;
        $chat = (new Chat())->setUserId((int) $user->getId())->setTitle('Research')->setSource('web');
        $this->em->persist($chat);
        $this->em->flush();
        $this->created[] = $chat;

        $now = new \DateTimeImmutable();
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $now);
        $budget->at($now);
        $this->state = ResearchRunState::open(ResearchRunRef::start((int) $user->getId(), ResearchDepth::Quick, 'Population?', (int) $chat->getId(), $now), $budget);
        $this->state->ledger->setReport("# Iceland\n\nAbout 396,500 people.");
        $this->state->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        try {
            $this->store->save($this->state);
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }

        static::getContainer()->get(ResearchReportDelivery::class)->deliver($this->state);
        $this->em->clear();

        $messages = $this->em->getRepository(Message::class)->findBy(['chat' => $chat->getId()]);
        self::assertCount(1, $messages);
        $message = $messages[0];
        self::assertSame("# Iceland\n\nAbout 396,500 people.", $message->getText());
        self::assertSame('de', $message->getLanguage());
        self::assertCount(0, $message->getFiles());
        $this->created[] = $message;
        self::assertTrue($this->store->wasDelivered($this->state->ref->id));
    }
}
