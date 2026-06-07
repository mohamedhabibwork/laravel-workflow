<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

/**
 * The 14 operators supported by the structured `field/operator/value`
 * condition JSON. See `contracts/workflow-engine.md` §Conditions.
 */
enum Operator: string
{
    case Eq = 'eq';
    case NotEq = 'neq';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
    case IsTrue = 'is_true';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
