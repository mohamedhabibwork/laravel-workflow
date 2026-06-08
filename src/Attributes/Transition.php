<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\TransitionType;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Transition
{
    public function __construct(
        public string $from,
        public string $to,
        public string $on,
        public ?string $when = null,
        public int $priority = 0,
        public TransitionType|string $type = TransitionType::Forward,
    ) {}
}
