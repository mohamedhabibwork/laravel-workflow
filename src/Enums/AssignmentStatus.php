<?php

namespace HFlow\LaravelWorkflow\Enums;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case Acted = 'acted';
    case Reassigned = 'reassigned';
    case Expired = 'expired';
}
