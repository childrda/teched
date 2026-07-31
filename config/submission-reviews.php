<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Comment field length limits (characters)
    |--------------------------------------------------------------------------
    |
    | Both the student-visible feedback and the private note use the same cap.
    |
    */

    'comment_max' => (int) env('SUBMISSION_REVIEW_COMMENT_MAX', 5000),

];
