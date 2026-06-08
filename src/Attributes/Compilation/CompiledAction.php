<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

use JsonSerializable;

final readonly class CompiledAction implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $guardCondition
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $type,
        public ?string $label = null,
        public string $availabilityMode = 'general',
        public ?array $guardCondition = null,
        public ?string $guardClass = null,
        public ?string $targetStep = null,
        public bool $requiresComment = false,
        public ?string $handler = null,
        public int $sortOrder = 0,
        public array $config = [],
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
            'label' => $this->label,
            'availabilityMode' => $this->availabilityMode,
            'guardCondition' => $this->guardCondition,
            'guardClass' => $this->guardClass,
            'targetStep' => $this->targetStep,
            'requiresComment' => $this->requiresComment,
            'handler' => $this->handler,
            'sortOrder' => $this->sortOrder,
            'config' => $this->config,
        ];
    }
}
