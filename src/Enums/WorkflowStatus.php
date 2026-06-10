<?php

namespace HFlow\LaravelWorkflow\Enums;

enum WorkflowStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
