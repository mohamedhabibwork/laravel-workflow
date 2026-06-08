<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\AssigneeType;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Assignee
{
    public function __construct(
        public AssigneeType|string $type,
        public string $value,
        public ?string $customResolver = null,
        public int $sortOrder = 0,
    ) {}
}
