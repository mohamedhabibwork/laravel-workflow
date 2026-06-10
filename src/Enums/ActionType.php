<?php

namespace HFlow\LaravelWorkflow\Enums;

enum ActionType: string
{
    case Submit = 'submit';
    case Approve = 'approve';
    case Reject = 'reject';
    case Skip = 'skip';
    case Return = 'return';
    case Complete = 'complete';
    case Cancel = 'cancel';
    case Custom = 'custom';
}
