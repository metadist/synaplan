<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api;

use Plugin\Synafastbill\Api\Exception\FastBillApiException;
use Plugin\Synafastbill\Api\Http\HttpClientInterface;
use Plugin\Synafastbill\Dto\Customer;
use Plugin\Synafastbill\Dto\Invoice;
use Plugin\Synafastbill\Dto\InvoiceItem;

/**
 * Thin, typed wrapper around the FastBill JSON API.
 *
 * See https://apidocs.fastbill.com/ — one endpoint, POST a JSON body with
 * SERVICE / FILTER / DATA / LIMIT / OFFSET, receive REQUEST / RESPONSE / ERRORS.
 *
 * v1.0 deliberately exposes only four services and NO destructive/finalising
 * ones (no complete, send, or delete). Every write method here is only ever
 * called by a command handler AFTER the user has confirmed.
 */
final class FastBillClient
{
    public const DEFAULT_ENDPOINT = 'https://my.fastbill.com/api/1.0/api.php';

    /** FastBill caps list responses at 100 regardless of the requested LIMIT. */
    public const MAX_PAGE_SIZE = 100;

    /**
     * Safety cap on paginated invoice fetches (25 pages = up to 2 500 invoices).
     * Enough to cover well over the "3–5 months back" the overdue view needs,
     * while bounding API calls against FastBill's hourly rate limit.
     */
    public const MAX_INVOICE_PAGES = 25;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly FastBillCredentials $credentials,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<int, Customer>
     */
    public function getCustomers(array $filter = [], int $limit = 10, int $offset = 0): array
    {
        $response = $this->request('customer.get', $filter, [], $limit, $offset);
        $rows = $this->extractList($response, 'CUSTOMERS');

        return array_map(static fn (array $row): Customer => Customer::fromArray($row), $rows);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<int, Invoice>
     */
    public function getInvoices(array $filter = [], int $limit = 10, int $offset = 0): array
    {
        $response = $this->request('invoice.get', $filter, [], $limit, $offset);
        $rows = $this->extractList($response, 'INVOICES');

        return array_map(static fn (array $row): Invoice => Invoice::fromArray($row), $rows);
    }

    /**
     * Fetch invoices across pages. FastBill caps each page at 100 rows, so a
     * single `invoice.get` only ever returns the most recent 100 — an older
     * overdue/open invoice would be missed. This walks pages until a short page
     * (end of data) or the page cap, so "show overdue" / payment matching see
     * the full history, not just the latest page.
     *
     * @param array<string, mixed> $filter
     *
     * @return array<int, Invoice>
     */
    public function getAllInvoices(array $filter = [], int $maxPages = self::MAX_INVOICE_PAGES): array
    {
        $all = [];
        for ($page = 0; $page < $maxPages; ++$page) {
            $batch = $this->getInvoices($filter, self::MAX_PAGE_SIZE, $page * self::MAX_PAGE_SIZE);
            foreach ($batch as $invoice) {
                $all[] = $invoice;
            }
            if (count($batch) < self::MAX_PAGE_SIZE) {
                break;
            }
        }

        return $all;
    }

    /**
     * Create an invoice. FastBill creates invoices as drafts; they only become
     * finalised via `invoice.complete`, which this plugin never calls in v1.0.
     *
     * @param array<int, InvoiceItem> $items
     *
     * @return int the new INVOICE_ID
     */
    public function createDraftInvoice(int $customerId, array $items, string $currency = 'EUR'): int
    {
        $data = [
            'CUSTOMER_ID' => $customerId,
            'CURRENCY_CODE' => $currency,
            'ITEMS' => array_map(static fn (InvoiceItem $item): array => $item->toApiArray(), $items),
        ];
        $response = $this->request('invoice.create', [], $data);

        return (int) ($this->responseSection($response)['INVOICE_ID'] ?? 0);
    }

    /**
     * Register a payment against an existing invoice.
     */
    public function setInvoicePaid(int $invoiceId, string $paidDate, ?float $amount = null): void
    {
        $data = [
            'INVOICE_ID' => $invoiceId,
            'PAID_DATE' => $paidDate,
        ];
        if (null !== $amount) {
            $data['AMOUNT'] = $amount;
        }
        $this->request('invoice.setpaid', [], $data);
    }

    /**
     * Perform a single FastBill request and return the decoded body.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function request(string $service, array $filter = [], array $data = [], ?int $limit = null, ?int $offset = null): array
    {
        $payload = ['SERVICE' => $service];
        if ([] !== $filter) {
            $payload['FILTER'] = $filter;
        }
        if ([] !== $data) {
            $payload['DATA'] = $data;
        }
        if (null !== $limit) {
            $payload['LIMIT'] = min($limit, self::MAX_PAGE_SIZE);
        }
        if (null !== $offset) {
            $payload['OFFSET'] = $offset;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->http->post($this->endpoint, $body, [
            'Authorization' => $this->credentials->authorizationHeader(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        if (429 === $response->statusCode) {
            throw new FastBillApiException($service, ['Rate limit exceeded. Please try again later.'], 429);
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new FastBillApiException($service, ['Unexpected non-JSON response from FastBill.'], $response->statusCode);
        }

        /** @var array<string, mixed> $decoded */
        $errors = $this->extractErrors($decoded);
        if ([] !== $errors || !$response->isSuccessful()) {
            throw new FastBillApiException($service, $errors, $response->statusCode);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractList(array $response, string $key): array
    {
        $section = $this->responseSection($response);
        $list = $section[$key] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $rows = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                /* @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function responseSection(array $response): array
    {
        $section = $response['RESPONSE'] ?? [];

        /* @var array<string, mixed> $section */
        return is_array($section) ? $section : [];
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<int, string>
     */
    private function extractErrors(array $decoded): array
    {
        $section = $this->responseSection($decoded);
        $errors = $section['ERRORS'] ?? ($decoded['ERRORS'] ?? []);
        if (!is_array($errors)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $e): string => is_string($e) ? $e : json_encode($e, JSON_THROW_ON_ERROR), $errors));
    }
}
