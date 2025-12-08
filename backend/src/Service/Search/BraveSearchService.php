<?php

namespace App\Service\Search;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for interacting with Brave Search API
 * Documentation: https://api-dashboard.search.brave.com/app/documentation/web-search/get-started.
 */
class BraveSearchService
{
    private string $apiKey;
    private string $apiUrl;
    private bool $enabled;
    private int $defaultCount;
    private string $defaultCountry;
    private string $defaultSearchLang;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $braveSearchApiKey,
        string $braveSearchApiUrl,
        bool $braveSearchEnabled,
        int $braveSearchCount,
        string $braveSearchCountry,
        string $braveSearchLang,
    ) {
        $this->apiKey = $braveSearchApiKey;
        $this->apiUrl = rtrim($braveSearchApiUrl, '/');
        $this->enabled = $braveSearchEnabled;
        $this->defaultCount = $braveSearchCount;
        $this->defaultCountry = $braveSearchCountry;
        $this->defaultSearchLang = $braveSearchLang;
    }

    /**
     * Check if Brave Search is enabled and configured.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    /**
     * Perform a web search using Brave Search API.
     *
     * @param string $query   The search query
     * @param array  $options Additional search options (count, country, search_lang, etc.)
     *
     * @return array Search results with metadata
     *
     * @throws \RuntimeException If search fails or is not configured
     */
    public function search(string $query, array $options = []): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Brave Search API is not enabled or configured');
        }

        if (empty($query)) {
            throw new \InvalidArgumentException('Search query cannot be empty');
        }

        // Normalize language and country codes with fallbacks
        $searchLang = $this->normalizeLanguageCode($options['search_lang'] ?? $this->defaultSearchLang);
        $country = $this->normalizeCountryCode($options['country'] ?? $this->defaultCountry);

        $this->logger->info('🔍 Brave Search: Performing search', [
            'query' => $query,
            'search_lang' => $searchLang,
            'country' => $country,
            'options' => $options,
        ]);

        try {
            // Build query parameters with normalized values
            // URL-encode the query to handle special characters (umlauts, etc.)
            $params = [
                'q' => $query, // Symfony HttpClient will encode this
                'count' => $options['count'] ?? $this->defaultCount,
                'country' => $country,
                'search_lang' => $searchLang,
            ];

            // Optional parameters
            if (isset($options['safesearch'])) {
                $params['safesearch'] = $options['safesearch']; // off, moderate, strict
            }
            if (isset($options['freshness'])) {
                $params['freshness'] = $options['freshness']; // pd (past day), pw (past week), pm (past month), py (past year)
            }
            if (isset($options['offset'])) {
                $params['offset'] = $options['offset'];
            }

            $this->logger->info('🔍 Brave Search: Making API request', [
                'url' => $this->apiUrl.'/web/search',
                'params' => $params,
            ]);

            // Make API request
            $response = $this->httpClient->request('GET', $this->apiUrl.'/web/search', [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Subscription-Token' => $this->apiKey,
                ],
                'query' => $params,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                // Get error message from response body
                $errorBody = $response->getContent(false); // false = don't throw on error status

                $this->logger->error('Brave Search API error', [
                    'status_code' => $statusCode,
                    'query' => $query,
                    'error_body' => substr($errorBody, 0, 500), // First 500 chars
                ]);
                throw new \RuntimeException("Brave Search API returned status code: {$statusCode}");
            }

            $data = $response->toArray();

            $this->logger->info('✅ Brave Search: Search completed', [
                'query' => $query,
                'results_count' => count($data['web']['results'] ?? []),
            ]);

            // Parse and structure the results
            return $this->parseSearchResults($data, $query);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Brave Search API transport error', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);
            throw new \RuntimeException('Failed to connect to Brave Search API: '.$e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $this->logger->error('Brave Search API unexpected error', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_trace' => $e->getTraceAsString(),
                'query' => $query,
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            throw new \RuntimeException('Brave Search API error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Normalize language code to ISO 639-1 (2-letter) format
     * Ensures valid language codes with fallback to default.
     */
    private function normalizeLanguageCode(?string $lang): string
    {
        if (empty($lang)) {
            return $this->defaultSearchLang;
        }

        // Convert to lowercase and extract first 2 characters
        $lang = strtolower(trim($lang));
        $lang = substr($lang, 0, 2);

        // List of valid ISO 639-1 codes that Brave Search commonly supports
        $validLangCodes = [
            'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'ru', 'ja',
            'zh', 'ko', 'ar', 'tr', 'hi', 'sv', 'da', 'fi', 'no', 'cs',
            'hu', 'ro', 'uk', 'el', 'he', 'id', 'th', 'vi', 'ms', 'bn',
        ];

        if (in_array($lang, $validLangCodes, true)) {
            return $lang;
        }

        // Fallback to default if invalid
        $this->logger->debug('Invalid language code, using default', [
            'provided' => $lang,
            'default' => $this->defaultSearchLang,
        ]);

        return $this->defaultSearchLang;
    }

    /**
     * Normalize country code to ISO 3166-1 alpha-2 (2-letter) format
     * Maps language codes to appropriate country codes with fallback.
     */
    private function normalizeCountryCode(?string $country): string
    {
        if (empty($country)) {
            return $this->defaultCountry;
        }

        // Convert to uppercase and extract first 2 characters
        $country = strtoupper(trim($country));
        $country = substr($country, 0, 2);

        // Map common language codes to country codes (for convenience)
        // This helps when language is passed instead of country
        $langToCountryMap = [
            'EN' => 'US',  // English -> United States (default)
            'DE' => 'DE',  // German -> Germany
            'FR' => 'FR',  // French -> France
            'ES' => 'ES',  // Spanish -> Spain
            'IT' => 'IT',  // Italian -> Italy
            'PT' => 'PT',  // Portuguese -> Portugal
            'NL' => 'NL',  // Dutch -> Netherlands
            'PL' => 'PL',  // Polish -> Poland
            'RU' => 'RU',  // Russian -> Russia
            'JA' => 'JP',  // Japanese -> Japan
            'ZH' => 'CN',  // Chinese -> China
            'KO' => 'KR',  // Korean -> South Korea
            'AR' => 'SA',  // Arabic -> Saudi Arabia
            'TR' => 'TR',  // Turkish -> Turkey
            'HI' => 'IN',  // Hindi -> India
            'SV' => 'SE',  // Swedish -> Sweden
            'DA' => 'DK',  // Danish -> Denmark
            'FI' => 'FI',  // Finnish -> Finland
            'NO' => 'NO',  // Norwegian -> Norway
            'CS' => 'CZ',  // Czech -> Czech Republic
            'HU' => 'HU',  // Hungarian -> Hungary
            'RO' => 'RO',  // Romanian -> Romania
            'UK' => 'UA',  // Ukrainian -> Ukraine
            'EL' => 'GR',  // Greek -> Greece
            'HE' => 'IL',  // Hebrew -> Israel
            'ID' => 'ID',  // Indonesian -> Indonesia
            'TH' => 'TH',  // Thai -> Thailand
            'VI' => 'VN',  // Vietnamese -> Vietnam
            'MS' => 'MY',  // Malay -> Malaysia
            'BN' => 'BD',  // Bengali -> Bangladesh
        ];

        // Try to map language to country
        if (isset($langToCountryMap[$country])) {
            $mappedCountry = $langToCountryMap[$country];
            $this->logger->debug('Mapped language to country', [
                'language' => $country,
                'country' => $mappedCountry,
            ]);

            return $mappedCountry;
        }

        // List of valid ISO 3166-1 alpha-2 country codes
        $validCountryCodes = [
            'US', 'GB', 'CA', 'AU', 'DE', 'FR', 'ES', 'IT', 'PT', 'NL',
            'PL', 'RU', 'JP', 'CN', 'KR', 'IN', 'BR', 'MX', 'AR', 'CL',
            'SE', 'DK', 'FI', 'NO', 'CZ', 'HU', 'RO', 'UA', 'GR', 'TR',
            'IL', 'SA', 'AE', 'ID', 'TH', 'VN', 'MY', 'SG', 'PH', 'BD',
            'BE', 'AT', 'CH', 'IE', 'NZ', 'ZA', 'EG', 'NG', 'KE', 'CO',
        ];

        if (in_array($country, $validCountryCodes, true)) {
            return $country;
        }

        // Fallback to default if invalid
        $this->logger->debug('Invalid country code, using default', [
            'provided' => $country,
            'default' => $this->defaultCountry,
        ]);

        return $this->defaultCountry;
    }

    /**
     * Parse and structure Brave Search API results.
     */
    private function parseSearchResults(array $data, string $query): array
    {
        $results = [];

        // Extract web results
        if (isset($data['web']['results']) && is_array($data['web']['results'])) {
            foreach ($data['web']['results'] as $result) {
                $results[] = [
                    'title' => $result['title'] ?? '',
                    'url' => $result['url'] ?? '',
                    'description' => strip_tags($result['description'] ?? ''),
                    'age' => $result['age'] ?? null,
                    'language' => $result['language'] ?? null,
                    'profile' => [
                        'name' => $result['profile']['name'] ?? null,
                        'url' => $result['profile']['url'] ?? null,
                        'img' => $result['profile']['img'] ?? null,
                    ],
                    'thumbnail' => $result['thumbnail']['src'] ?? null,
                    'extra_snippets' => $result['extra_snippets'] ?? [],
                ];
            }
        }

        return [
            'query' => $query,
            'results' => $results,
            'query_metadata' => [
                'total' => count($results),
                'altered' => $data['query']['altered'] ?? null,
                'original' => $data['query']['original'] ?? $query,
            ],
            'mixed_results' => $data['mixed'] ?? [],
            'news' => $data['news']['results'] ?? [],
            'videos' => $data['videos']['results'] ?? [],
            'faq' => $data['faq']['results'] ?? [],
            'timestamp' => time(),
        ];
    }

    /**
     * Format search results as plain text for AI model consumption.
     */
    public function formatResultsForAI(array $searchResults): string
    {
        if (empty($searchResults['results'])) {
            return 'No search results found for query: '.($searchResults['query'] ?? 'unknown');
        }

        $formatted = "Web Search Results for: \"{$searchResults['query']}\"\n\n";
        $formatted .= "Found {$searchResults['query_metadata']['total']} results:\n\n";

        foreach ($searchResults['results'] as $index => $result) {
            $num = $index + 1;
            $formatted .= "[{$num}] {$result['title']}\n";
            $formatted .= "URL: {$result['url']}\n";

            if (!empty($result['description'])) {
                $formatted .= "Description: {$result['description']}\n";
            }

            if (!empty($result['age'])) {
                $formatted .= "Published: {$result['age']}\n";
            }

            // Add extra snippets if available
            if (!empty($result['extra_snippets'])) {
                $formatted .= "Snippets:\n";
                foreach ($result['extra_snippets'] as $snippet) {
                    $formatted .= '  - '.strip_tags($snippet)."\n";
                }
            }

            $formatted .= "\n";
        }

        return $formatted;
    }
}
