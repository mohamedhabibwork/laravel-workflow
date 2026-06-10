<?php

namespace HFlow\LaravelWorkflow\Enums;

enum ActorType: string
{
    case User = 'user';
    case System = 'system';
}
