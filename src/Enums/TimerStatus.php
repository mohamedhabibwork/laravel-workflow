<?php

namespace HFlow\LaravelWorkflow\Enums;

enum TimerStatus: string
{
    case Pending = 'pending';
    case Fired = 'fired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
