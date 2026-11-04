<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A stored file must not run script in the application's origin.
 *
 * Uploads are served from the same origin as the app and its API. Opened
 * directly, an SVG, XML or HTML file renders as a document, and one a user
 * put there (a widget icon, a generated file, an attachment) can carry a
 * `<script>` or an event handler that would act with the viewer's session.
 * Such a file from the upload root is served with a policy that blocks script
 * and sandboxes the document; an `<img>` renders an SVG exactly as before.
 *
 * The priority runs this after ResponseListener (0), which fills in the
 * Content-Type a BinaryFileResponse did not set itself.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: -64)]
final readonly class UploadedFileSandboxSubscriber
{
    public const POLICY = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox";

    private string $uploadRoot;

    public function __construct(
        #[Autowire(param: 'app.upload_dir')] string $uploadDir,
    ) {
        $this->uploadRoot = rtrim($uploadDir, '/').'/';
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof BinaryFileResponse || $response->headers->has('Content-Security-Policy')) {
            return;
        }

        if (!str_starts_with($response->getFile()->getPathname(), $this->uploadRoot)) {
            return;
        }

        if (self::rendersAsDocument((string) $response->headers->get('Content-Type'))) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }
    }

    /**
     * HTML and every XML type: a browser runs script in an XML document as
     * soon as it contains an XHTML or SVG element.
     */
    private static function rendersAsDocument(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($type, ['text/html', 'text/xml', 'application/xml'], true) || str_ends_with($type, '+xml');
    }
}
