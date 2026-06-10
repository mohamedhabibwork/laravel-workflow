<?php

namespace HFlow\LaravelWorkflow\Enums;

enum ConditionKind: string
{
    case Expression = 'expression';
    case Custom = 'custom';
    case Composite = 'composite';
}
