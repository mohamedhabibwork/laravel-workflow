<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

final readonly class CompileContext
{
    public function __construct(
        public int|string|null $tenantId = null,
        public bool $strict = true,
        public ?int $version = null,
        public bool $dryRun = false,
        public ?string $path = null,
    ) {}
}
