<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Actions;

use HFlow\LaravelWorkflow\Enums\ActionType;

/**
 * Immutable ordered collection of Action value objects.
 */
final readonly class ActionSet
{
    /**
     * @param  array<int, Action>  $actions
     */
    public function __construct(public array $actions) {}

    public function has(string $key): bool
    {
        foreach ($this->actions as $a) {
            if ($a->key === $key) {
                return true;
            }
        }

        return false;
    }

    public function get(string $key): Action
    {
        foreach ($this->actions as $a) {
            if ($a->key === $key) {
                return $a;
            }
        }

        throw new \OutOfBoundsException("Action [{$key}] not found in ActionSet.");
    }

    public function find(string $key): ?Action
    {
        foreach ($this->actions as $a) {
            if ($a->key === $key) {
                return $a;
            }
        }

        return null;
    }

    public function filter(ActionType $type): self
    {
        return new self(array_values(array_filter(
            $this->actions,
            static fn (Action $a) => $a->type === $type,
        )));
    }

    public function first(): ?Action
    {
        return $this->actions[0] ?? null;
    }

    public function count(): int
    {
        return count($this->actions);
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(static fn (Action $a) => $a->key, $this->actions);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (Action $a) => $a->toArray(), $this->actions);
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
