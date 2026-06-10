<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Step
{
    /**
     * @param  class-string|null  $customAuthorizer
     * @param  class-string|null  $handler
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $code,
        public string $name,
        public StepType $type = StepType::Task,
        public int $position = 0,
        public AuthorizationMode $authorizationMode = AuthorizationMode::Public,
        public MatchMode $matchMode = MatchMode::Any,
        public ?string $description = null,
        public ?string $customAuthorizer = null,
        public ?string $handler = null,
        public bool $isSkippable = false,
        public bool $isReturnable = false,
        public ?int $slaSeconds = null,
        public array $config = [],
    ) {}
}
