<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Expressions;

use HFlow\LaravelWorkflow\Enums\Operator;

/**
 * A single `field / operator / value` leaf in a structured condition.
 */
final readonly class Clause
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            field: (string) ($data['field'] ?? ''),
            operator: Operator::from((string) ($data['operator'] ?? 'eq')),
            value: $data['value'] ?? null,
        );
    }
}
