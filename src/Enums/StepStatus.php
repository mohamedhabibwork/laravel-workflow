<?php

namespace HFlow\LaravelWorkflow\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Returned = 'returned';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
