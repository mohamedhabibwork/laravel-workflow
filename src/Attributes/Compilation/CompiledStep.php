<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

use JsonSerializable;

final readonly class CompiledStep implements JsonSerializable
{
    /**
     * @param  list<CompiledAction>  $actions
     * @param  list<array{type: string, value: string, customResolver: ?string, sortOrder: int}>  $assignees
     * @param  list<array{code: string, name: string, kind: string, expression: array<string, mixed>|null, evaluator: ?string}>  $conditions
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $type,
        public int $position = 0,
        public string $authorization = 'public',
        public string $matchMode = 'all',
        public ?string $customAuthorizer = null,
        public ?string $handler = null,
        public bool $isSkippable = false,
        public bool $isReturnable = false,
        public ?int $slaSeconds = null,
        public array $config = [],
        public array $actions = [],
        public array $assignees = [],
        public array $conditions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'position' => $this->position,
            'authorization' => $this->authorization,
            'matchMode' => $this->matchMode,
            'customAuthorizer' => $this->customAuthorizer,
            'handler' => $this->handler,
            'isSkippable' => $this->isSkippable,
            'isReturnable' => $this->isReturnable,
            'slaSeconds' => $this->slaSeconds,
            'config' => $this->config,
            'actions' => $this->actions,
            'assignees' => $this->assignees,
            'conditions' => $this->conditions,
        ];
    }
}
