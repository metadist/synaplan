<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Fake;

use Plugin\Synafastbill\Api\Http\HttpClientInterface;
use Plugin\Synafastbill\Api\Http\HttpResponse;

/**
 * In-memory stand-in for the FastBill JSON API at the HTTP boundary.
 *
 * It decodes the same JSON body the real {@see \Plugin\Synafastbill\Api\FastBillClient}
 * sends and returns FastBill-shaped JSON, so the production client code
 * (request building + response parsing) is exercised end-to-end without any
 * network access. Data is FastBill-field-shaped (CUSTOMER_ID, INVOICE_ID, …).
 */
final class FakeFastBillHttpClient implements HttpClientInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $customers;

    /** @var array<int, array<string, mixed>> */
    private array $invoices;

    /** @var array<int, array<string, mixed>> every decoded request payload */
    public array $requests = [];

    private int $nextInvoiceId = 0;

    /**
     * @param array<int, array<string, mixed>> $customers
     * @param array<int, array<string, mixed>> $invoices
     */
    public function __construct(
        array $customers = [],
        array $invoices = [],
        private readonly bool $failAuth = false,
        private readonly bool $rateLimited = false,
    ) {
        $this->customers = $customers;
        $this->invoices = $invoices;
        foreach ($invoices as $invoice) {
            $this->nextInvoiceId = max($this->nextInvoiceId, (int) ($invoice['INVOICE_ID'] ?? 0));
        }
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $payload;

        if ($this->rateLimited) {
            return new HttpResponse(429, '');
        }
        if ($this->failAuth) {
            return $this->json(['RESPONSE' => ['ERRORS' => ['Login Error: Wrong API KEY or user credentials.']]], 401);
        }

        $service = (string) ($payload['SERVICE'] ?? '');
        /** @var array<string, mixed> $filter */
        $filter = is_array($payload['FILTER'] ?? null) ? $payload['FILTER'] : [];
        /** @var array<string, mixed> $data */
        $data = is_array($payload['DATA'] ?? null) ? $payload['DATA'] : [];

        $limit = isset($payload['LIMIT']) ? (int) $payload['LIMIT'] : null;
        $offset = isset($payload['OFFSET']) ? (int) $payload['OFFSET'] : 0;

        return match ($service) {
            'customer.get' => $this->json(['RESPONSE' => ['CUSTOMERS' => $this->findCustomers($filter)]]),
            'invoice.get' => $this->json(['RESPONSE' => ['INVOICES' => $this->paginate($this->findInvoices($filter), $limit, $offset)]]),
            'invoice.create' => $this->json(['RESPONSE' => ['STATUS' => 'success', 'INVOICE_ID' => $this->createInvoice($data)]]),
            'invoice.setpaid' => $this->json(['RESPONSE' => ['STATUS' => 'success', 'INVOICE_ID' => $this->setPaid($data)]]),
            default => $this->json(['RESPONSE' => ['ERRORS' => ['Unknown service: '.$service]]], 400),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invoice(int $id): ?array
    {
        foreach ($this->invoices as $invoice) {
            if ((int) ($invoice['INVOICE_ID'] ?? 0) === $id) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<int, array<string, mixed>>
     */
    private function findCustomers(array $filter): array
    {
        $result = [];
        foreach ($this->customers as $customer) {
            if (isset($filter['CUSTOMER_ID']) && (int) $customer['CUSTOMER_ID'] !== (int) $filter['CUSTOMER_ID']) {
                continue;
            }
            if (isset($filter['TERM']) && !$this->customerMatchesTerm($customer, (string) $filter['TERM'])) {
                continue;
            }
            $result[] = $customer;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function customerMatchesTerm(array $customer, string $term): bool
    {
        $needle = mb_strtolower(trim($term));
        if ('' === $needle) {
            return true;
        }
        foreach (['ORGANIZATION', 'FIRST_NAME', 'LAST_NAME', 'CUSTOMER_NUMBER', 'EMAIL'] as $field) {
            $value = mb_strtolower((string) ($customer[$field] ?? ''));
            if ('' !== $value && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emulate FastBill's LIMIT/OFFSET paging (each page capped at 100 rows).
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function paginate(array $rows, ?int $limit, int $offset): array
    {
        $pageSize = null === $limit ? count($rows) : min($limit, 100);

        return array_slice($rows, $offset, $pageSize);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<int, array<string, mixed>>
     */
    private function findInvoices(array $filter): array
    {
        $result = [];
        foreach ($this->invoices as $invoice) {
            if (isset($filter['CUSTOMER_ID']) && (int) $invoice['CUSTOMER_ID'] !== (int) $filter['CUSTOMER_ID']) {
                continue;
            }
            if (isset($filter['TYPE']) && '' !== (string) $filter['TYPE'] && (string) ($invoice['TYPE'] ?? '') !== (string) $filter['TYPE']) {
                continue;
            }
            $result[] = $invoice;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createInvoice(array $data): int
    {
        $id = ++$this->nextInvoiceId;
        $this->invoices[] = [
            'INVOICE_ID' => $id,
            'INVOICE_NUMBER' => '',
            'CUSTOMER_ID' => (int) ($data['CUSTOMER_ID'] ?? 0),
            'TYPE' => 'draft',
            'CURRENCY_CODE' => (string) ($data['CURRENCY_CODE'] ?? 'EUR'),
            'ITEMS' => $data['ITEMS'] ?? [],
        ];

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setPaid(array $data): int
    {
        $id = (int) ($data['INVOICE_ID'] ?? 0);
        foreach ($this->invoices as $index => $invoice) {
            if ((int) ($invoice['INVOICE_ID'] ?? 0) === $id) {
                $this->invoices[$index]['PAID_DATE'] = (string) ($data['PAID_DATE'] ?? '');
            }
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
