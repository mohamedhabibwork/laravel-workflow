<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Expressions;

/**
 * The top-level expression value object.
 *
 * Wraps either a flat {@see ClauseGroup} or a raw array shape. Evaluated
 * deterministically against a `$context` array.
 */
final readonly class Expression
{
    public function __construct(
        public ClauseGroup $root,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ClauseGroup::fromArray($data));
    }
}
