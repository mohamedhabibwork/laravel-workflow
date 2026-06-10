# Laravel Workflow Documentation

This directory contains package documentation for installing, configuring, defining, and running workflows.

## Contents

- [Installation](installation.md)
- [Configuration](configuration.md)
- [Defining Workflows](defining-workflows.md)
- [PHP Attribute Workflows](attribute-workflows.md)
- [Model Usage and Builder API](model-usage.md)
- [Facade API](facade-api.md)
- [Runtime Controls](runtime-controls.md)
- [Activities and Workers](activities-and-workers.md)
- [Laravel Events](events.md)
- [Customization and Overrides](customization.md)
- [End-to-End Example](end-to-end-example.md)
- [Actions and Conditions](actions-and-conditions.md)
- [Automation](automation.md)
- [Multi-Tenancy](multi-tenancy.md)
- [Artisan Commands](artisan-commands.md)
- [Extension Contracts](extension-contracts.md)
- [Temporal Feature Mapping](temporal-feature-mapping.md)
- [Testing](testing.md)

## Core Concepts

- A `Workflow` is the versioned blueprint.
- A `WorkflowStep` is a state in the blueprint.
- A `WorkflowStepAction` is a user action available on a step.
- A `WorkflowTransition` routes from one step to another.
- A `WorkflowInstance` is one running workflow for one Eloquent subject.
- A `WorkflowStepInstance` tracks runtime entry into one step.
- A `WorkflowActivity` is durable external work executed by a Laravel worker.
- A `WorkflowTimer` is durable delayed work for one workflow instance.
- `WorkflowHistory` records immutable audit events.
