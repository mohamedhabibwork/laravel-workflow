<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Update
{
    /**
     * @param  class-string  $handler
     * @param  class-string|null  $validator
     */
    public function __construct(
        public string $name,
        public string $handler,
        public ?string $validator = null,
    ) {}
}
