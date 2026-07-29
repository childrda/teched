<?php

namespace App\Enums;

enum PageCompletionType: string
{
    case View = 'view';
    case SubmitRequired = 'submit_required';
    case CompleteActivity = 'complete_activity';
    case PassActivity = 'pass_activity';
    case ConfirmVideo = 'confirm_video';
}
