<?php

namespace HFlow\LaravelWorkflow\Enums;

enum AvailabilityMode: string
{
    case General = 'general';
    case Conditional = 'conditional';
    case Custom = 'custom';
}
