<?php

namespace App\Tests\Repository;

use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for PromptRepository.
 *
 * Verifies that user prompt overrides are visible regardless of language.
 */
class PromptRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PromptRepository $repository;
    private ?User $testUser = null;

    /** @var Prompt[] */
    private array $createdPrompts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->repository = $this->em->getRepository(Prompt::class);

        $this->testUser = new User();
        $this->testUser->setMail('prompt_test_'.time().'@test.com');
        $this->testUser->setPw('test123');
        $this->testUser->setProviderId('WEB');
        $this->testUser->setUserLevel('NEW');
        $this->em->persist($this->testUser);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPrompts as $prompt) {
            $this->em->remove($prompt);
        }

        if ($this->testUser) {
            $this->em->remove($this->testUser);
        }

        $this->em->flush();
        parent::tearDown();
    }

    public function testFindAllForUserReturnsOverrideRegardlessOfLanguage(): void
    {
        $systemPrompt = $this->createPrompt('test-general', 0, 'en', 'System general');
        $userOverride = $this->createPrompt('test-general', $this->testUser->getId(), 'en', 'User override');

        // Ask for 'de' prompts -- user override saved as 'en' must still appear
        $results = $this->repository->findAllForUser($this->testUser->getId(), 'de');

        $found = $this->findByTopic($results, 'test-general');
        $this->assertNotNull($found, 'User override should be visible regardless of language parameter');
        $this->assertSame($userOverride->getId(), $found->getId(), 'Should return user override, not system prompt');
    }

    public function testFindAllForUserReturnsSystemPromptWhenNoOverride(): void
    {
        $this->createPrompt('test-system-only', 0, 'en', 'System prompt');

        $results = $this->repository->findAllForUser($this->testUser->getId(), 'de');

        $found = $this->findByTopic($results, 'test-system-only');
        $this->assertNotNull($found, 'System prompt should be visible');
        $this->assertSame(0, $found->getOwnerId());
    }

    public function testFindPromptsWithSelectionRulesReturnsOverrideRegardlessOfLanguage(): void
    {
        $systemPrompt = $this->createPrompt('test-rules', 0, 'en', 'System', 'System rules');
        $userOverride = $this->createPrompt('test-rules', $this->testUser->getId(), 'en', 'User', 'User rules');

        // Ask for 'de' -- user override saved as 'en' must still appear
        $results = $this->repository->findPromptsWithSelectionRules($this->testUser->getId(), 'de');

        $found = $this->findByTopic($results, 'test-rules');
        $this->assertNotNull($found, 'User override with selection rules should be visible regardless of language');
        $this->assertSame($userOverride->getId(), $found->getId());
    }

    public function testGetTopicsWithDescriptionsReturnsOverrideRegardlessOfLanguage(): void
    {
        $this->createPrompt('test-topics', 0, 'en', 'System desc');
        $this->createPrompt('test-topics', $this->testUser->getId(), 'en', 'User desc');

        // Ask for 'de' -- user override saved as 'en' must still appear
        $results = $this->repository->getTopicsWithDescriptions(0, 'de', $this->testUser->getId());

        $found = null;
        foreach ($results as $r) {
            if ('test-topics' === $r['topic']) {
                $found = $r;
                break;
            }
        }

        $this->assertNotNull($found, 'User override description should be visible regardless of language');
        $this->assertSame('User desc', $found['description']);
    }

    public function testFindByTopicAndUserReturnsUserOverride(): void
    {
        $this->createPrompt('test-lookup', 0, 'en', 'System');
        $userOverride = $this->createPrompt('test-lookup', $this->testUser->getId(), 'en', 'User');

        $result = $this->repository->findByTopicAndUser('test-lookup', $this->testUser->getId());

        $this->assertNotNull($result);
        $this->assertSame($userOverride->getId(), $result->getId());
    }

    public function testFindByTopicAndUserFallsBackToSystem(): void
    {
        $systemPrompt = $this->createPrompt('test-fallback', 0, 'en', 'System');

        $result = $this->repository->findByTopicAndUser('test-fallback', $this->testUser->getId());

        $this->assertNotNull($result);
        $this->assertSame($systemPrompt->getId(), $result->getId());
    }

    private function createPrompt(string $topic, int $ownerId, string $lang, string $desc, ?string $rules = null): Prompt
    {
        $prompt = new Prompt();
        $prompt->setOwnerId($ownerId);
        $prompt->setTopic($topic);
        $prompt->setLanguage($lang);
        $prompt->setShortDescription($desc);
        $prompt->setPrompt('Test prompt content for '.$topic);
        $prompt->setSelectionRules($rules);

        $this->em->persist($prompt);
        $this->em->flush();

        $this->createdPrompts[] = $prompt;

        return $prompt;
    }

    /**
     * @param Prompt[] $prompts
     */
    private function findByTopic(array $prompts, string $topic): ?Prompt
    {
        foreach ($prompts as $p) {
            if ($p->getTopic() === $topic) {
                return $p;
            }
        }

        return null;
    }
}
