<?php

namespace HFlow\LaravelWorkflow\Enums;

enum ActivityStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case WaitingForCompletion = 'waiting_for_completion';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
}
