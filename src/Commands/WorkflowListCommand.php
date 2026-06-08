<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\QueryBuilder\TenantScope;
use Illuminate\Console\Command;

/**
 * `workflow:list` — List all active workflows.
 *
 * Prints a table with: code, name, version, status, type, subject type.
 * The list is scoped by the global TenantScope when tenancy is enabled
 * (call `workflow:list --all` to bypass the scope).
 */
final class WorkflowListCommand extends Command
{
    protected $signature = 'workflow:list
        {--all : Bypass the tenant scope and list workflows across all tenants}
        {--status= : Filter by workflow status (draft, active, archived, deprecated)}';

    protected $description = 'List all active workflows';

    public function handle(): int
    {
        $query = Workflow::query()->orderBy('code')->orderByDesc('version');

        if (! $this->option('all')) {
            // Let the global TenantScope apply automatically (no-op when off).
        } else {
            $query->withoutGlobalScope(TenantScope::class);
        }

        $status = $this->option('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No workflows found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Name', 'Version', 'Status', 'Type', 'Subject Type', 'Tenant'],
            $rows->map(fn (Workflow $w): array => [
                $w->code,
                $w->name,
                (string) $w->version,
                $w->status->value,
                $w->type->value,
                (string) ($w->subject_type ?? '-'),
                $w->tenant_id === null ? '-' : (string) $w->tenant_id,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
