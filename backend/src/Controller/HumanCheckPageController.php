<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\HumanCheckPage;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hosts the human check for the native app. The WebView document itself
 * cannot run Turnstile; see {@see HumanCheckPage}.
 */
#[OA\Tag(name: 'Authentication')]
final class HumanCheckPageController extends AbstractController
{
    public function __construct(
        private readonly HumanCheckPage $page,
    ) {
    }

    #[Route(HumanCheckPage::PATH, name: 'api_auth_human_check', methods: ['GET'])]
    #[OA\Get(
        path: HumanCheckPage::PATH,
        summary: 'Human-check page for the native app',
        description: 'Blank page the native shell frames. Cloudflare Turnstile runs here, on the real site hostname, and the page posts the token back to the app. Query values are read by the page script and are not written into the HTML.',
        tags: ['Authentication'],
    )]
    #[OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'login'))]
    #[OA\Parameter(name: 'parent', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'capacitor://localhost'))]
    #[OA\Parameter(name: 'theme', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'light'))]
    #[OA\Parameter(name: 'lang', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'de'))]
    #[OA\Response(response: 200, description: 'HTML page that solves the human check and postMessages the token to the app')]
    public function page(): Response
    {
        return $this->page->response();
    }
}
