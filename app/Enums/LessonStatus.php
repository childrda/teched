<?php

namespace App\Enums;

enum LessonStatus: string
{
    /** Never published. */
    case Draft = 'draft';

    /** Has an active published version. */
    case Published = 'published';

    /** Withdrawn from students. */
    case Archived = 'archived';
}
