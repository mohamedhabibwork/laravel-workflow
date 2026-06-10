<?php

namespace HFlow\LaravelWorkflow\Enums;

enum MatchMode: string
{
    case Any = 'any';
    case All = 'all';
}
