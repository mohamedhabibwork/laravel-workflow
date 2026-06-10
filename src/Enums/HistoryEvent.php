<?php

namespace HFlow\LaravelWorkflow\Enums;

enum HistoryEvent: string
{
    case Started = 'started';
    case StepEntered = 'step_entered';
    case StepCompleted = 'step_completed';
    case ActionPerformed = 'action_performed';
    case Skipped = 'skipped';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';
    case TimedOut = 'timed_out';
    case CommentAdded = 'comment_added';
    case Error = 'error';
    case SignalReceived = 'signal_received';
    case UpdateAccepted = 'update_accepted';
    case UpdateRejected = 'update_rejected';
    case TimerScheduled = 'timer_scheduled';
    case TimerFired = 'timer_fired';
    case ChildStarted = 'child_started';
    case ContinuedAsNew = 'continued_as_new';
    case Retried = 'retried';
    case StartDelayed = 'start_delayed';
    case SearchAttributesUpdated = 'search_attributes_updated';
    case ActivityScheduled = 'activity_scheduled';
    case ActivityStarted = 'activity_started';
    case ActivityWaiting = 'activity_waiting';
    case ActivityCompleted = 'activity_completed';
    case ActivityFailed = 'activity_failed';
    case ActivityTimedOut = 'activity_timed_out';
}
