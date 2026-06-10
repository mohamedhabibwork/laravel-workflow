<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Action
{
    /**
     * @param  class-string|null  $guardClass
     * @param  class-string|null  $handler
     */
    public function __construct(
        public string $step,
        public string $code,
        public ?string $name = null,
        public ?string $label = null,
        public ActionType $type = ActionType::Submit,
        public AvailabilityMode $availabilityMode = AvailabilityMode::General,
        public ?string $guardCondition = null,
        public ?string $guardClass = null,
        public ?string $targetStep = null,
        public bool $requiresComment = false,
        public ?string $handler = null,
        public int $sortOrder = 0,
    ) {}
}
