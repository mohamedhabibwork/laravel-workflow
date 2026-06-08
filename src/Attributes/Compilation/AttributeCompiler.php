<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

use BackedEnum;
use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Assignee;
use HFlow\LaravelWorkflow\Attributes\AsWorkflow;
use HFlow\LaravelWorkflow\Attributes\Authorizer;
use HFlow\LaravelWorkflow\Attributes\Condition;
use HFlow\LaravelWorkflow\Attributes\Discovery\AttributeWorkflowLoader;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Engines\Expressions\Expression;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\Operator;
use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Exceptions\CompileValidationException;
use HFlow\LaravelWorkflow\Exceptions\DuplicateWorkflowCodeException;
use HFlow\LaravelWorkflow\Exceptions\InvalidExpressionException;
use HFlow\LaravelWorkflow\Exceptions\InvalidWorkflowException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class AttributeCompiler implements AttributeCompilerContract
{
    public function __construct(
        private readonly ?AttributeWorkflowLoader $loader = null,
        private readonly ?InvariantChecker $invariants = null,
    ) {}

    public function compile(string $class, CompileContext $context): CompiledWorkflow
    {
        $reflection = new ReflectionClass($class);
        $workflowAttribute = $reflection->getAttributes(AsWorkflow::class)[0] ?? null;

        if ($workflowAttribute === null) {
            throw InvalidWorkflowException::invalidGraph("Class [{$class}] is not marked with #[AsWorkflow].");
        }

        /** @var AsWorkflow $workflow */
        $workflow = $workflowAttribute->newInstance();

        $steps = [];
        $transitions = [];
        $conditions = [];
        $assignees = [];

        foreach ($reflection->getAttributes(Condition::class) as $attribute) {
            /** @var Condition $condition */
            $condition = $attribute->newInstance();
            $conditions[] = $this->compileCondition($condition);
        }

        foreach ($this->members($reflection) as $member) {
            $stepAttribute = $member->getAttributes(Step::class)[0] ?? null;
            if ($stepAttribute === null) {
                continue;
            }

            /** @var Step $step */
            $step = $stepAttribute->newInstance();

            $actions = array_map(
                fn ($attribute): CompiledAction => $this->compileAction($attribute->newInstance()),
                $member->getAttributes(Action::class),
            );

            $stepAssignees = array_map(
                fn ($attribute): array => $this->compileAssignee($attribute->newInstance()),
                $member->getAttributes(Assignee::class),
            );

            foreach ($stepAssignees as $assignee) {
                $assignees[] = ['step' => $step->code] + $assignee;
            }

            $authorizerAttribute = $member->getAttributes(Authorizer::class)[0] ?? null;
            $authorizerClass = $step->customAuthorizer;
            if ($authorizerAttribute !== null) {
                /** @var Authorizer $authorizer */
                $authorizer = $authorizerAttribute->newInstance();
                $authorizerClass = $authorizer->class;
            }

            $stepConditions = [];
            foreach ($member->getAttributes(Condition::class) as $attribute) {
                /** @var Condition $condition */
                $condition = $attribute->newInstance();
                $stepConditions[] = $this->compileCondition($condition);
            }

            $steps[] = new CompiledStep(
                code: $step->code,
                name: $this->nameOrCode($step->name, $step->code),
                type: $this->enumValue($step->type),
                position: $step->position,
                authorization: $this->enumValue($step->authorization),
                matchMode: $this->enumValue($step->matchMode),
                customAuthorizer: $authorizerClass,
                handler: $step->handler,
                isSkippable: $step->isSkippable,
                isReturnable: $step->isReturnable,
                slaSeconds: $step->slaSeconds,
                config: $step->config ?? [],
                actions: $actions,
                assignees: $stepAssignees,
                conditions: $stepConditions,
            );

            foreach ($stepConditions as $condition) {
                $conditions[] = $condition;
            }

            foreach ($member->getAttributes(Transition::class) as $attribute) {
                /** @var Transition $transition */
                $transition = $attribute->newInstance();
                $transitions[] = [
                    'from' => $transition->from,
                    'to' => $transition->to,
                    'on' => $transition->on,
                    'when' => $this->normalizeExpression($transition->when),
                    'priority' => $transition->priority,
                    'type' => $this->transitionType($transition->type, $transition->when !== null),
                ];
            }
        }

        usort(
            $steps,
            static fn (CompiledStep $left, CompiledStep $right): int => [$left->position, $left->code] <=> [$right->position, $right->code],
        );

        $compiled = new CompiledWorkflow(
            code: $workflow->code,
            name: $workflow->name,
            subject: $workflow->subject,
            type: $this->enumValue($workflow->type ?? WorkflowType::Generic),
            version: $context->version ?? 1,
            tenantId: $workflow->tenantId ?? $context->tenantId,
            description: $workflow->description,
            steps: $steps,
            transitions: $transitions,
            conditions: $conditions,
            assignees: $assignees,
        );

        $violations = $this->checker()->check($compiled);
        if ($violations !== [] && $context->strict) {
            throw new CompileValidationException($violations);
        }

        return $compiled;
    }

    public function compileAll(CompileContext $context): array
    {
        $compiled = [];
        $codes = [];

        foreach ($this->workflowLoader()->classes($context->path) as $class) {
            $workflow = $this->compile($class, $context);
            $codeKey = "{$workflow->tenantId}:{$workflow->code}";

            if (isset($codes[$codeKey])) {
                throw new DuplicateWorkflowCodeException("Duplicate workflow code [{$workflow->code}] for tenant [{$workflow->tenantId}].");
            }

            $codes[$codeKey] = true;
            $compiled[] = $workflow;
        }

        return $compiled;
    }

    /**
     * @return list<ReflectionMethod|ReflectionProperty>
     */
    private function members(ReflectionClass $reflection): array
    {
        return [
            ...$reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            ...$reflection->getProperties(ReflectionProperty::IS_PUBLIC),
            ...$reflection->getProperties(ReflectionProperty::IS_PROTECTED),
            ...$reflection->getProperties(ReflectionProperty::IS_PRIVATE),
        ];
    }

    private function compileAction(Action $action): CompiledAction
    {
        $guardCondition = $this->normalizeExpression($action->guardCondition);
        $availabilityMode = $this->enumValue($action->availabilityMode);

        if ($guardCondition !== null && $availabilityMode === ActionAvailabilityMode::General->value) {
            $availabilityMode = ActionAvailabilityMode::Conditional->value;
        }

        return new CompiledAction(
            code: $action->code,
            name: $this->nameOrCode($action->name, $action->code),
            type: $this->enumValue($action->type),
            label: $action->label,
            availabilityMode: $availabilityMode,
            guardCondition: $guardCondition,
            guardClass: $action->guardClass,
            targetStep: $action->targetStep,
            requiresComment: $action->requiresComment,
            handler: $action->handler,
            sortOrder: $action->sortOrder,
        );
    }

    /**
     * @return array{type: string, value: string, customResolver: ?string, sortOrder: int}
     */
    private function compileAssignee(Assignee $assignee): array
    {
        return [
            'type' => $this->enumValue($assignee->type),
            'value' => $assignee->value,
            'customResolver' => $assignee->customResolver,
            'sortOrder' => $assignee->sortOrder,
        ];
    }

    /**
     * @return array{code: string, name: string, kind: string, expression: array<string, mixed>|null, evaluator: ?string}
     */
    private function compileCondition(Condition $condition): array
    {
        return [
            'code' => $condition->code,
            'name' => $condition->name ?? $this->nameOrCode('', $condition->code),
            'kind' => $this->enumValue($condition->kind),
            'expression' => $this->normalizeExpression($condition->expression),
            'evaluator' => $condition->evaluator,
        ];
    }

    /**
     * @param  array<string, mixed>|string|null  $expression
     * @return array<string, mixed>|null
     */
    private function normalizeExpression(string|array|null $expression): ?array
    {
        if ($expression === null || $expression === '') {
            return null;
        }

        if (is_array($expression)) {
            Expression::fromArray($expression);

            return $expression;
        }

        $parsed = $this->parseInlineExpression($expression);
        Expression::fromArray($parsed);

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseInlineExpression(string $expression): array
    {
        $pattern = '/^\s*([A-Za-z_][A-Za-z0-9_.]*)\s*(==|!=|>=|<=|>|<|not_in|in|contains|starts_with|ends_with|is_not_null|is_null)\s*(.*?)\s*$/';

        if (preg_match($pattern, $expression, $matches) !== 1) {
            throw new InvalidExpressionException("Invalid inline expression [{$expression}].");
        }

        $operator = match ($matches[2]) {
            '==' => Operator::Eq->value,
            '!=' => Operator::NotEq->value,
            '>' => Operator::Gt->value,
            '>=' => Operator::Gte->value,
            '<' => Operator::Lt->value,
            '<=' => Operator::Lte->value,
            default => $matches[2],
        };

        $value = in_array($operator, [Operator::IsNull->value, Operator::IsNotNull->value], true)
            ? null
            : $this->parseInlineValue($matches[3]);

        return [
            'op' => 'and',
            'clauses' => [[
                'field' => $matches[1],
                'operator' => $operator,
                'value' => $value,
            ]],
        ];
    }

    private function parseInlineValue(string $value): mixed
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (($value[0] ?? '') === '[' || ($value[0] ?? '') === '{') {
            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidExpressionException("Invalid JSON literal [{$value}].");
            }
        }

        if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            return substr($value, 1, -1);
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($value) ? $value + 0 : $value,
        };
    }

    private function transitionType(TransitionType|string $type, bool $hasCondition): string
    {
        $value = $this->enumValue($type);

        return match ($value) {
            'unconditional', 'fallback' => TransitionType::Forward->value,
            default => $hasCondition && $value === TransitionType::Forward->value
                ? TransitionType::Conditional->value
                : $value,
        };
    }

    private function enumValue(BackedEnum|string $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    private function nameOrCode(string $name, string $code): string
    {
        if ($name !== '') {
            return $name;
        }

        return str($code)->replace(['-', '_'], ' ')->title()->toString();
    }

    private function workflowLoader(): AttributeWorkflowLoader
    {
        return $this->loader ?? new AttributeWorkflowLoader(app());
    }

    private function checker(): InvariantChecker
    {
        return $this->invariants ?? new InvariantChecker;
    }
}
