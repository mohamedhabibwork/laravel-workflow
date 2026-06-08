<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsWorkflow
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $subject = null,
        public string|\BackedEnum|null $type = null,
        public ?string $description = null,
        public int|string|null $tenantId = null,
    ) {}
}
