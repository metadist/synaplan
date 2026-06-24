<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MarketingNews\MarketingNewsFeedService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/news', name: 'api_news_')]
#[OA\Tag(name: 'News')]
final class NewsController extends AbstractController
{
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
        // Sanitise to a short ASCII locale token (e.g. "de", "en", "pt-br"); we
        // do NOT force it to a fixed allowlist so unknown locales correctly fall
        // through to FEED_URL_DEFAULT inside the resolver (matches the OpenAPI
        // description). The resolver itself only branches on de/en/else.
        $lang = strtolower((string) $request->query->get('lang', 'en'));
        $lang = preg_replace('/[^a-z-]/', '', $lang) ?? '';
        $lang = substr($lang, 0, 5);
        if ('' === $lang) {
            $lang = 'en';
        }

        $items = $this->feedService->getLandingItems($lang);

        return $this->json(['items' => $items]);
    }

    /**
     * Same-origin cover-image proxy for the guest landing.
     *
     * Cover images come from the (cross-origin) feed host, which may serve them
     * with a restrictive Cross-Origin-Resource-Policy or hotlink protection that
     * blocks <img> embedding. We fetch them server-side (not subject to CORP) and
     * re-serve them same-origin. The source URL is validated against the allowed
     * feed hosts (SSRF guard).
     */
    #[Route('/image', name: 'image', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/news/image',
        summary: 'Proxy a marketing-news cover image',
        description: 'Public endpoint. Fetches a cover image from an allowed feed host and serves it same-origin. Returns 404 when disabled or the URL is not allowed.',
        tags: ['News'],
        parameters: [
            new OA\Parameter(
                name: 'u',
                description: 'The original (absolute) image URL from the feed.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ]
    )]
    #[OA\Response(response: 200, description: 'The image bytes')]
    #[OA\Response(response: 404, description: 'Disabled, not allowed, or fetch failed')]
    public function image(Request $request): Response
    {
        $url = (string) $request->query->get('u', '');
        if ('' === $url) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $image = $this->feedService->fetchImage($url);
        if (null === $image) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($image['content'], Response::HTTP_OK, [
            'Content-Type' => $image['contentType'],
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
