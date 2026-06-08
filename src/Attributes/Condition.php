<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\ConditionKind;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Condition
{
    /**
     * @param  array<string, mixed>|string|null  $expression
     */
    public function __construct(
        public string $code,
        public ?string $name = null,
        public ConditionKind|string $kind = ConditionKind::Expression,
        public string|array|null $expression = null,
        public ?string $evaluator = null,
    ) {}
}
