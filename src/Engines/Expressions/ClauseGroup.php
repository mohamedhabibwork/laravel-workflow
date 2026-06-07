<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Expressions;

/**
 * A group of clauses with AND/OR logic. Recursively composable.
 *
 * @phpstan-type ExpressionShape array{
 *     op?: 'and'|'or',
 *     clauses?: list<array<string, mixed>>,
 *     groups?: list<array<string, mixed>>,
 * }
 */
final readonly class ClauseGroup
{
    public const OP_AND = 'and';

    public const OP_OR = 'or';

    /**
     * @param  list<Clause>  $clauses
     * @param  list<ClauseGroup>  $groups
     */
    public function __construct(
        public string $op = self::OP_AND,
        public array $clauses = [],
        public array $groups = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            op: (string) ($data['op'] ?? self::OP_AND),
            clauses: array_map(
                static fn (array $c) => Clause::fromArray($c),
                (array) ($data['clauses'] ?? []),
            ),
            groups: array_map(
                static fn (array $g) => ClauseGroup::fromArray($g),
                (array) ($data['groups'] ?? []),
            ),
        );
    }
}
