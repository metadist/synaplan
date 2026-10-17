<?php

namespace App\AI\Provider\OpenAI;

/**
 * The native cURL POST the gpt-image generations request has always used:
 * a JSON body with the bearer key and a time limit. Tests replace it, so
 * no request leaves the process.
 */
final readonly class OpenAICurlTransport
{
    /**
     * @return array{0: string|bool, 1: int, 2: string} the response body, the HTTP status and the cURL error
     */
    public function postJson(string $url, ?string $key, string|false $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$key,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [$responseBody, $httpCode, $curlError];
    }
}
