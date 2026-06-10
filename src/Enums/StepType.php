<?php

namespace HFlow\LaravelWorkflow\Enums;

enum StepType: string
{
    case Start = 'start';
    case Task = 'task';
    case Approval = 'approval';
    case Automated = 'automated';
    case Gateway = 'gateway';
    case End = 'end';
}
