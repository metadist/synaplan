<?php

declare(strict_types=1);

namespace App\Service\Auth;

use ReCaptcha\ReCaptcha;
use ReCaptcha\RequestMethod;
use ReCaptcha\RequestParameters;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * reCAPTCHA siteverify through the shared HTTP client.
 */
final class RecaptchaEgressRequest implements RequestMethod
{
    public function __construct(
        private readonly ?HttpClientInterface $http,
    ) {
    }

    public function submit(RequestParameters $params): string
    {
        if (null === $this->http) {
            throw new \LogicException('reCAPTCHA requires the shared HTTP client.');
        }
        $response = $this->http->request('POST', ReCaptcha::SITE_VERIFY_URL, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $params->toQueryString(),
            'timeout' => 10,
            'max_duration' => 10,
        ]);

        return $response->getContent(false);
    }
}
