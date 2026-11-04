<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\UploadedFileSandboxSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Uploads are served from the application's own origin, so a stored file a
 * browser renders as a document must not be able to run script there.
 */
final class UploadedFileSandboxSubscriberTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/upload-sandbox-'.bin2hex(random_bytes(4));
        foreach (['uploads/widget-icons', 'uploads-archive', 'plugins'] as $dir) {
            mkdir($this->root.'/'.$dir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
        foreach (['uploads/widget-icons', 'uploads', 'uploads-archive', 'plugins', ''] as $dir) {
            rmdir($this->root.'/'.$dir);
        }
    }

    #[DataProvider('documentTypes')]
    public function testAStoredDocumentIsSandboxed(string $contentType): void
    {
        $response = $this->serve('uploads/widget-icons/icon.svg', $contentType);

        $this->assertSame(UploadedFileSandboxSubscriber::POLICY, $response->headers->get('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentTypes(): iterable
    {
        yield 'SVG' => ['image/svg+xml'];
        yield 'SVG with a charset' => ['image/svg+xml; charset=utf-8'];
        yield 'XML' => ['application/xml'];
        yield 'XML as text' => ['text/xml'];
        yield 'XHTML' => ['application/xhtml+xml'];
        yield 'another XML type' => ['application/atom+xml'];
        yield 'HTML' => ['text/html; charset=UTF-8'];
        yield 'upper case' => ['Image/SVG+XML'];
    }

    /**
     * A PDF stays outside the policy on purpose: a sandboxed document cannot
     * show the browser's built-in PDF viewer.
     */
    #[DataProvider('passiveTypes')]
    public function testOtherStoredTypesKeepTheirHeaders(string $contentType): void
    {
        $response = $this->serve('uploads/widget-icons/file.bin', $contentType);

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function passiveTypes(): iterable
    {
        yield 'PNG' => ['image/png'];
        yield 'PDF' => ['application/pdf'];
        yield 'audio' => ['audio/mpeg'];
        yield 'plain text' => ['text/plain'];
        yield 'JSON' => ['application/json'];
    }

    public function testAFileOutsideTheUploadRootIsLeftAlone(): void
    {
        $plugin = $this->serve('plugins/logo.svg', 'image/svg+xml');
        $sibling = $this->serve('uploads-archive/icon.svg', 'image/svg+xml');

        $this->assertFalse($plugin->headers->has('Content-Security-Policy'), 'A plugin asset is not an upload.');
        $this->assertFalse($sibling->headers->has('Content-Security-Policy'), 'A directory that only starts like the upload root is not inside it.');
    }

    public function testAPolicyTheControllerSetIsKept(): void
    {
        $response = $this->serve('uploads/widget-icons/icon.svg', 'image/svg+xml', "default-src 'self'");

        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testAResponseThatIsNotAFileIsLeftAlone(): void
    {
        $response = new Response('<svg/>', Response::HTTP_OK, ['Content-Type' => 'image/svg+xml']);

        $this->subscriber()->onResponse($this->event($response));

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    private function serve(string $relativePath, string $contentType, ?string $policy = null): BinaryFileResponse
    {
        $path = $this->root.'/'.$relativePath;
        file_put_contents($path, 'bytes');
        $this->files[] = $path;

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $contentType);
        if (null !== $policy) {
            $response->headers->set('Content-Security-Policy', $policy);
        }

        $this->subscriber()->onResponse($this->event($response));

        return $response;
    }

    private function subscriber(): UploadedFileSandboxSubscriber
    {
        return new UploadedFileSandboxSubscriber($this->root.'/uploads');
    }

    private function event(Response $response): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
