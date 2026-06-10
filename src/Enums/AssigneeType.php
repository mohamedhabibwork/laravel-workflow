<?php

namespace HFlow\LaravelWorkflow\Enums;

enum AssigneeType: string
{
    case Role = 'role';
    case Permission = 'permission';
    case User = 'user';
    case Public = 'public';
    case Custom = 'custom';
}
