<?php

namespace App\Enums;

enum BlockType: string
{
    case RichText = 'rich_text';
    case Image = 'image';
    case Video = 'video';
    case FileLink = 'file_link';
    case Callout = 'callout';
    case StaticTable = 'static_table';
    case VocabularyCards = 'vocabulary_cards';
    case Matching = 'matching';
    case ImageLabeling = 'image_labeling';
    case ShortResponse = 'short_response';
    case Cer = 'cer';
    case Quiz = 'quiz';
}
