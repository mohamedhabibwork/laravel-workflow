<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
final readonly class Authorizer
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $class,
        public array $params = [],
    ) {}
}
