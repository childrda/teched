<?php

namespace App\Enums;

enum UserRole: string
{
    /** Learner; the default for any new account. */
    case Student = 'student';

    /** Author and classroom staff. */
    case Teacher = 'teacher';

    /** Platform administrator. */
    case Admin = 'admin';
}
