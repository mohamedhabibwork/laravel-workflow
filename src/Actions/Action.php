<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Actions;

use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;

final readonly class Action
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $key,
        public ActionType $type,
        public string $label,
        public ActionAvailabilityMode $availability = ActionAvailabilityMode::General,
        public ?string $conditionJson = null,
        public ?int $windowStart = null,
        public ?int $windowEnd = null,
        public ?string $handlerClass = null,
        public bool $requiresComment = false,
        public ?int $nextStepId = null,
        public array $metadata = [],
    ) {}

    public static function approve(): self
    {
        return new self(
            key: 'approve',
            type: ActionType::Approve,
            label: 'Approve',
        );
    }

    public static function reject(): self
    {
        return new self(
            key: 'reject',
            type: ActionType::Reject,
            label: 'Reject',
        );
    }

    public static function custom(string $key, string $handlerClass, string $label): self
    {
        return new self(
            key: $key,
            type: ActionType::Custom,
            label: $label,
            availability: ActionAvailabilityMode::Custom,
            handlerClass: $handlerClass,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
            'availability' => $this->availability->value,
            'conditionJson' => $this->conditionJson,
            'windowStart' => $this->windowStart,
            'windowEnd' => $this->windowEnd,
            'handlerClass' => $this->handlerClass,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            type: ActionType::from((string) ($data['type'] ?? 'custom')),
            label: (string) ($data['label'] ?? ''),
            availability: ActionAvailabilityMode::from((string) ($data['availability'] ?? 'general')),
            conditionJson: $data['conditionJson'] ?? null,
            windowStart: $data['windowStart'] ?? null,
            windowEnd: $data['windowEnd'] ?? null,
            handlerClass: $data['handlerClass'] ?? null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
