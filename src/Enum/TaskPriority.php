<?php

namespace App\Enum;

enum TaskPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}
