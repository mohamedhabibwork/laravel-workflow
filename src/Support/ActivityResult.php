<?php

namespace HFlow\LaravelWorkflow\Support;

class ActivityResult
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public bool $async,
        public array $result = [],
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function complete(array $result = []): self
    {
        return new self(false, $result);
    }

    public static function async(): self
    {
        return new self(true);
    }
}
