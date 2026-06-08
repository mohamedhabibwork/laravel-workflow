<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

use JsonSerializable;

final readonly class CompiledWorkflow implements JsonSerializable
{
    /**
     * @param  list<CompiledStep>  $steps
     * @param  list<array{from: string, to: string, on: string, when: array<string, mixed>|null, priority: int, type: string}>  $transitions
     * @param  list<array{code: string, name: string, kind: string, expression: array<string, mixed>|null, evaluator: ?string}>  $conditions
     * @param  list<array{step: string, type: string, value: string, customResolver: ?string, sortOrder: int}>  $assignees
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $subject,
        public string $type,
        public int $version,
        public int|string|null $tenantId,
        public ?string $description = null,
        public array $steps = [],
        public array $transitions = [],
        public array $conditions = [],
        public array $assignees = [],
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->jsonSerializeForFingerprint(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'subject' => $this->subject,
            'type' => $this->type,
            'version' => $this->version,
            'tenantId' => $this->tenantId,
            'description' => $this->description,
            'steps' => $this->steps,
            'transitions' => $this->transitions,
            'conditions' => $this->conditions,
            'assignees' => $this->assignees,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSerializeForFingerprint(): array
    {
        $data = $this->jsonSerialize();
        unset($data['version']);

        return $data;
    }
}
