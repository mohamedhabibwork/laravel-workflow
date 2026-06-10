<?php

namespace HFlow\LaravelWorkflow\Enums;

enum InstanceStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';
    case TimedOut = 'timed_out';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
