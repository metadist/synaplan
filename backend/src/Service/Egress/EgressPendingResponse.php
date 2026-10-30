<?php

declare(strict_types=1);

namespace App\Service\Egress;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A response whose redirects and metrics are applied the first time it is
 * read or streamed, not when {@see EgressHttpClient::request()} returns.
 * Starting several reads therefore overlaps, the way Symfony's own client does.
 */
final class EgressPendingResponse implements ResponseInterface
{
    private ?ResponseInterface $final = null;

    /**
     * @param \Closure(ResponseInterface): ResponseInterface $follow
     */
    public function __construct(
        private readonly \Closure $follow,
        private readonly ResponseInterface $started,
        private readonly int $redirectsRemaining,
    ) {
    }

    public function transport(): ResponseInterface
    {
        return $this->final ?? $this->started;
    }

    public function redirectsRemaining(): int
    {
        return null === $this->final ? $this->redirectsRemaining : 0;
    }

    public function settle(): ResponseInterface
    {
        if (null === $this->final) {
            $this->final = ($this->follow)($this->started);
        }

        return $this->final;
    }

    public function getStatusCode(): int
    {
        return $this->settle()->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->settle()->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->settle()->getContent($throw);
    }

    public function toArray(bool $throw = true): array
    {
        return $this->settle()->toArray($throw);
    }

    public function cancel(): void
    {
        ($this->final ?? $this->started)->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->settle()->getInfo($type);
    }
}
