<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Discovery;

use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Compilation\AttributeCompilerContract;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompileContext;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

final class AttributeWorkflowLoader
{
    public function __construct(
        private readonly ?Application $app = null,
    ) {}

    /**
     * @return list<class-string>
     */
    public function classes(?string $path = null): array
    {
        $classes = [];

        foreach ($this->candidateFiles($path) as $file) {
            $class = $this->classFromFile($file);
            if ($class === null) {
                continue;
            }

            require_once $file;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(AsWorkflow::class) === []) {
                continue;
            }

            $classes[] = $class;
        }

        return array_values(array_unique($classes));
    }

    public function compileOnBoot(): void
    {
        if (! (bool) config('workflow.compile_on_boot', false)) {
            return;
        }

        if (! app()->bound(AttributeCompilerContract::class)) {
            return;
        }

        /** @var AttributeCompilerContract $compiler */
        $compiler = app(AttributeCompilerContract::class);
        $compiler->compileAll(new CompileContext(
            tenantId: $this->tenantIdFromConfig(),
            strict: true,
        ));
    }

    /**
     * @return list<string>
     */
    private function candidateFiles(?string $path): array
    {
        $paths = $path !== null && $path !== ''
            ? [$path]
            : (array) config('workflow.attribute_paths', ['app/Workflows']);

        $files = [];
        foreach ($paths as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $absolute = $this->absolutePath($candidate);
            if (is_file($absolute)) {
                $files[] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            foreach ((new Finder)->files()->in($absolute)->name('*.php') as $file) {
                $files[] = $file->getRealPath();
            }
        }

        return array_values(array_filter($files, 'is_string'));
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        $basePath = $this->app?->basePath() ?? (function_exists('base_path') ? base_path() : getcwd());

        $fromBasePath = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
        if (file_exists($fromBasePath)) {
            return $fromBasePath;
        }

        return rtrim((string) getcwd(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @return class-string|null
     */
    private function classFromFile(string $file): ?string
    {
        $contents = (string) file_get_contents($file);
        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches) === 1) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/\b(?:final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $contents, $matches) === 1) {
            $class = trim($matches[1]);
        }

        if ($class === null || $class === '') {
            return null;
        }

        return $namespace !== null && $namespace !== ''
            ? $namespace.'\\'.$class
            : $class;
    }

    private function tenantIdFromConfig(): int|string|null
    {
        if (! (bool) config('workflow.tenancy.enabled', false)) {
            return null;
        }

        $provider = config('workflow.tenancy.scope_provider');
        if (is_string($provider) && $provider !== '' && class_exists($provider)) {
            $resolved = app($provider);
            if (is_object($resolved) && method_exists($resolved, 'currentTenantId')) {
                return $resolved->currentTenantId();
            }
        }

        return null;
    }
}
