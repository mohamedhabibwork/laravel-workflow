<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\TransitionType;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Transition
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $action = null,
        public ?string $condition = null,
        public TransitionType $type = TransitionType::Forward,
        public int $priority = 0,
    ) {}
}
