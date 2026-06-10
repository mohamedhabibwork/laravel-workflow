<?php

namespace HFlow\LaravelWorkflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Signal
{
    /**
     * @param  class-string  $handler
     */
    public function __construct(
        public string $name,
        public string $handler,
    ) {}
}
