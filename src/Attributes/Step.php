<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
final readonly class Step
{
    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        public string $code,
        public StepType|string $type,
        public string $name = '',
        public int $position = 0,
        public AuthorizationMode|string $authorization = AuthorizationMode::Public,
        public MatchMode|string $matchMode = MatchMode::All,
        public ?string $customAuthorizer = null,
        public ?string $handler = null,
        public bool $isSkippable = false,
        public bool $isReturnable = false,
        public ?int $slaSeconds = null,
        public ?array $config = null,
    ) {}
}
