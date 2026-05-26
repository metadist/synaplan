<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\RoutingFeedback;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Repository\RoutingFeedbackRepository;
use App\UseCase\CompoundRoutingCatalog;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin endpoints for routing training data management.
 */
#[Route('/api/v1/admin/routing')]
#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin Routing')]
final class AdminRoutingTrainingController extends AbstractController
{
    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly PromptRepository $promptRepository,
        private readonly RoutingFeedbackRepository $feedbackRepository,
        private readonly MessageRepository $messageRepository,
    ) {
    }

    /**
     * Generate training examples for a use case via AI.
     */
    #[Route('/generate-examples', name: 'admin_routing_generate_examples', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/routing/generate-examples',
        summary: 'Generate training examples for a use case',
        description: 'Uses an AI model to generate 10-15 realistic user messages that match the given use case description and keywords.',
        security: [['Bearer' => []]],
        tags: ['Admin Routing'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['topic', 'description'],
                properties: [
                    new OA\Property(property: 'topic', type: 'string', description: 'Use case topic identifier'),
                    new OA\Property(property: 'description', type: 'string', description: 'Short description of the use case'),
                    new OA\Property(property: 'keywords', type: 'string', description: 'Optional comma-separated keywords'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Generated examples',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'examples',
                    type: 'array',
                    items: new OA\Items(type: 'string')
                ),
            ]
        )
    )]
    public function generateExamples(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);

        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $topic = trim((string) ($body['topic'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $keywords = trim((string) ($body['keywords'] ?? ''));

        if ('' === $description) {
            return $this->json(['error' => 'description is required'], Response::HTTP_BAD_REQUEST);
        }

        $prompt = $this->buildGenerationPrompt($topic, $description, $keywords);

        try {
            $response = $this->aiFacade->chat(
                [['role' => 'user', 'content' => $prompt]],
                null,
                ['temperature' => 0.9],
            );

            $content = trim($response['content'] ?? '');
            $examples = $this->parseExamplesResponse($content);

            return $this->json(['examples' => $examples]);
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => 'Generation failed: '.$e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Export all training data as JSONL for the external router.
     */
    #[Route('/training-data', name: 'admin_routing_training_data', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/routing/training-data',
        summary: 'Export training data as JSONL',
        description: 'Combines prompt training examples, verified user feedbacks, and compound catalog data into JSONL format for SetFit training.',
        security: [['Bearer' => []]],
        tags: ['Admin Routing']
    )]
    #[OA\Response(
        response: 200,
        description: 'JSONL training data stream',
        content: new OA\MediaType(
            mediaType: 'application/x-ndjson'
        )
    )]
    public function exportTrainingData(): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            // 1. Training examples from prompts
            $prompts = $this->promptRepository->findAllForUser(0, 'en', false);
            foreach ($prompts as $prompt) {
                $examples = $prompt->getTrainingExamples();
                if (null === $examples || '' === trim($examples)) {
                    continue;
                }

                $lines = array_filter(
                    array_map('trim', explode("\n", $examples)),
                    static fn (string $line) => '' !== $line,
                );

                foreach ($lines as $line) {
                    echo json_encode([
                        'text' => $line,
                        'label' => $prompt->getTopic(),
                        'source' => 'admin_examples',
                    ], JSON_UNESCAPED_UNICODE)."\n";
                }
            }

            // 2. Verified user feedbacks
            $feedbacks = $this->feedbackRepository->findVerified();
            foreach ($feedbacks as $feedback) {
                echo json_encode([
                    'text' => $this->getFeedbackMessageText($feedback),
                    'label' => $feedback->getSuggestedTopic(),
                    'source' => 'user_feedback',
                ], JSON_UNESCAPED_UNICODE)."\n";
            }

            // 3. Compound routing catalog data
            $catalogData = CompoundRoutingCatalog::exportTrainingData();
            foreach ($catalogData as $entry) {
                echo json_encode($entry, JSON_UNESCAPED_UNICODE)."\n";
            }
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Content-Disposition', 'attachment; filename="training-data.jsonl"');

        return $response;
    }

    private function buildGenerationPrompt(string $topic, string $description, string $keywords): string
    {
        $parts = ["Generate 12 short, realistic user messages that would be routed to the use case \"{$topic}\"."];
        $parts[] = "Description: {$description}";

        if ('' !== $keywords) {
            $parts[] = "Keywords: {$keywords}";
        }

        $parts[] = 'Requirements:';
        $parts[] = '- Mix German and English messages (roughly 50/50)';
        $parts[] = '- Each message should be 5-30 words, like a real user would type in a chat';
        $parts[] = '- Vary the phrasing, tone, and specificity';
        $parts[] = '- Include both formal and casual variants';
        $parts[] = '- Output ONLY the messages, one per line, no numbering or prefixes';

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function parseExamplesResponse(string $content): array
    {
        $lines = explode("\n", $content);
        $examples = [];

        foreach ($lines as $line) {
            $cleaned = trim($line);
            // Strip common formatting like "1. ", "- ", "* "
            $cleaned = preg_replace('/^[\d]+[.)]\s*/', '', $cleaned);
            $cleaned = preg_replace('/^[-*]\s*/', '', $cleaned);
            $cleaned = trim($cleaned, '"\'');
            $cleaned = trim($cleaned);

            if ('' !== $cleaned && mb_strlen($cleaned) >= 5) {
                $examples[] = $cleaned;
            }
        }

        return $examples;
    }

    private function getFeedbackMessageText(RoutingFeedback $feedback): string
    {
        $message = $this->messageRepository->find($feedback->getMessageId());

        return $message?->getText() ?? '';
    }
}
