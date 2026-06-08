<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

interface AttributeCompilerContract
{
    /**
     * @param  class-string  $class
     */
    public function compile(string $class, CompileContext $context): CompiledWorkflow;

    /**
     * @return list<CompiledWorkflow>
     */
    public function compileAll(CompileContext $context): array;
}
