<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Dto;

/**
 * A FastBill customer, as returned by the `customer.get` service.
 */
final readonly class Customer
{
    public function __construct(
        public int $id,
        public string $customerNumber,
        public string $type,
        public string $organization,
        public string $firstName,
        public string $lastName,
        public string $city,
        public string $email,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['CUSTOMER_ID'] ?? 0),
            customerNumber: (string) ($data['CUSTOMER_NUMBER'] ?? ''),
            type: (string) ($data['CUSTOMER_TYPE'] ?? ''),
            organization: (string) ($data['ORGANIZATION'] ?? ''),
            firstName: (string) ($data['FIRST_NAME'] ?? ''),
            lastName: (string) ($data['LAST_NAME'] ?? ''),
            city: (string) ($data['CITY'] ?? ''),
            email: (string) ($data['EMAIL'] ?? ''),
        );
    }

    public function displayName(): string
    {
        if ('' !== trim($this->organization)) {
            return $this->organization;
        }
        $person = trim($this->firstName.' '.$this->lastName);

        return '' !== $person ? $person : ('Customer #'.$this->id);
    }

    /**
     * All human-readable identifiers used for fuzzy matching against a hint.
     *
     * @return array<int, string>
     */
    public function searchableTerms(): array
    {
        return array_values(array_filter([
            $this->organization,
            trim($this->firstName.' '.$this->lastName),
            $this->firstName,
            $this->lastName,
            $this->customerNumber,
            $this->email,
        ], static fn (string $term): bool => '' !== trim($term)));
    }
}
