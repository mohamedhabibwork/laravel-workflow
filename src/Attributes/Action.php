<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Action
{
    /**
     * @param  array<string, mixed>|string|null  $guardCondition
     */
    public function __construct(
        public string $code,
        public ActionType|string $type,
        public string $name = '',
        public ?string $label = null,
        public ActionAvailabilityMode|string $availabilityMode = ActionAvailabilityMode::General,
        public string|array|null $guardCondition = null,
        public ?string $guardClass = null,
        public ?string $targetStep = null,
        public bool $requiresComment = false,
        public ?string $handler = null,
        public int $sortOrder = 0,
    ) {}
}
