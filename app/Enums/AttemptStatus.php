<?php

namespace App\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /** Replaced by a staff restart; not completed, not resumeable. */
    case Superseded = 'superseded';
}
