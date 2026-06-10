<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;

#[Attribute(Attribute::TARGET_CLASS)]
class WorkflowDefinition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $code,
        public string $name,
        public WorkflowType $type = WorkflowType::Generic,
        public int $version = 1,
        public WorkflowStatus $status = WorkflowStatus::Draft,
        public ?string $description = null,
        public ?string $subjectType = null,
        public bool $isCurrentVersion = true,
        public bool $requireExplicitTransitions = false,
        public array $config = [],
        public bool $activate = false,
    ) {}
}
