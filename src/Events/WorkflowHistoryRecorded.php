<?php

namespace HFlow\LaravelWorkflow\Events;

use HFlow\LaravelWorkflow\Models\WorkflowHistory;

class WorkflowHistoryRecorded
{
    public function __construct(
        public WorkflowHistory $history,
    ) {}
}
