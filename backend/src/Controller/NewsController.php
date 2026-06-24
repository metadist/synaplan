<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MarketingNews\MarketingNewsFeedService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/news', name: 'api_news_')]
#[OA\Tag(name: 'News')]
final class NewsController extends AbstractController
{
    private const ALLOWED_LOCALES = ['de', 'en', 'es', 'tr'];

    public function __construct(
        private readonly MarketingNewsFeedService $feedService,
    ) {
    }

    /**
     * Public marketing news for the anonymous guest landing.
     *
     * Returns an empty list when the admin master switch is OFF (no outbound HTTP),
     * or when the configured feed cannot be fetched/parsed.
     */
    #[Route('/landing', name: 'landing', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/news/landing',
        summary: 'Get marketing news for the guest landing',
        description: 'Public endpoint. Returns up to 4 latest news items from the admin-configured RSS feed for the requested language. Empty when the marketing-news master switch is disabled.',
        tags: ['News'],
        parameters: [
            new OA\Parameter(
                name: 'lang',
                description: 'UI locale (de, en, es, tr). Falls back to the default feed for unknown locales.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'en')
            ),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Marketing news items (possibly empty)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'title', type: 'string', example: 'Synamail: Your Synaplan AI workspace, right inside Outlook'),
                            new OA\Property(property: 'url', type: 'string', example: 'https://www.synaplan.com/blog/synamail-outlook'),
                            new OA\Property(property: 'excerpt', type: 'string', example: 'Summarise threads, translate, draft replies and save to your knowledge base.'),
                            new OA\Property(property: 'imageUrl', type: 'string', nullable: true, example: 'https://www.synaplan.com/uploads/synamail.webp'),
                            new OA\Property(property: 'publishedAt', type: 'string', nullable: true, example: '2026-06-17T00:00:00+00:00'),
                            new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string', example: 'Outlook')),
                        ],
                        type: 'object'
                    )
                ),
            ]
        )
    )]
    public function landing(Request $request): JsonResponse
    {
        $lang = strtolower((string) $request->query->get('lang', 'en'));
        if (!\in_array($lang, self::ALLOWED_LOCALES, true)) {
            $lang = 'en';
        }

        $items = $this->feedService->getLandingItems($lang);

        return $this->json(['items' => $items]);
    }
}
