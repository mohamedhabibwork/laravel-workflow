<?php

namespace HFlow\LaravelWorkflow\Enums;

enum TransitionType: string
{
    case Forward = 'forward';
    case Skip = 'skip';
    case Return = 'return';
    case Conditional = 'conditional';
    case Automatic = 'automatic';
}
