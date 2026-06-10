<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\ConditionKind;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Condition
{
    /**
     * @param  array<string, mixed>  $expression
     * @param  class-string|null  $evaluator
     */
    public function __construct(
        public string $code,
        public string $name,
        public ConditionKind $kind = ConditionKind::Expression,
        public array $expression = [],
        public ?string $evaluator = null,
    ) {}
}
