<?php

class Curler {
    /**
     * Makes a cURL request and returns the response as an array.
     *
     * @param string $url The URL to request.
     * @param array $arrHeader An array of headers to send with the request.
     * @param array|null $arrDataOption Optional data for POST requests.
     * @return array The response decoded as an array.
     * @throws \Exception If the cURL request fails.
     */
    public static function callJson(string $url, array $arrHeader, ?array $arrDataOption = null): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $arrHeader);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');

        if ($arrDataOption !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arrDataOption));
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('Curl error: ' . curl_error($ch));
        }

        curl_close($ch);

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON decode error: ' . json_last_error_msg());
        }

        return $decodedResponse;
    }


} 