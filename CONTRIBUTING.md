# Contributing

Thanks for contributing to Laravel Workflow.

## Local Setup

```bash
composer install
```

## Quality Checks

Run these before opening a pull request:

```bash
composer test
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

Use Pint to fix style issues:

```bash
vendor/bin/pint
```

## Pull Requests

- Target the active development branch unless a maintainer asks for another branch.
- Include tests for behavior changes.
- Update documentation for public API changes.
- Keep unrelated refactors out of feature or bug-fix pull requests.

## Releases

Releases are tagged with semantic version tags such as `v1.2.3`.
