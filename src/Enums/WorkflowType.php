<?php

namespace HFlow\LaravelWorkflow\Enums;

enum WorkflowType: string
{
    case Automation = 'automation';
    case Approval = 'approval';
    case Generic = 'generic';
}
