<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * One runtime step in a multi-step plan (Release D).
 *
 * `labelKey` matches frontend i18n paths (e.g. `config.routing.steps.chat`).
 */
final readonly class PlannedStep
{
    public function __construct(
        public string $id,
        public string $labelKey,
        public string $capability,
        public ?string $inputFrom = null,
    ) {
    }

    /**
     * @return array{id: string, label_key: string, capability: string, input_from?: string}
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'label_key' => $this->labelKey,
            'capability' => $this->capability,
        ];

        if (null !== $this->inputFrom && '' !== $this->inputFrom) {
            $data['input_from'] = $this->inputFrom;
        }

        return $data;
    }
}
