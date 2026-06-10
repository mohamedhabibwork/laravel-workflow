# Multi-Tenancy

The package can apply tenant filtering to workflow definition and runtime models.

## Enable Tenancy

```dotenv
WORKFLOW_TENANCY_ENABLED=true
```

## Current Tenant

Set a tenant ID directly:

```php
config(['workflow.multi_tenancy.current_tenant_id' => 123]);
```

Or configure a resolver:

```php
config([
    'workflow.multi_tenancy.resolver' => fn () => tenant('id'),
]);
```

If no config value or resolver is set, the package checks the authenticated user if it is an Eloquent model and reads its `tenant_id` attribute.

## Tenant Column

```php
'column' => 'tenant_id',
```

The migration adds `tenant_id` to:

- workflows
- workflow instances

Related models inherit tenant filtering through their nearest workflow or workflow instance relationship.
