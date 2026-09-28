<?php

namespace App\Enums;

enum LessonStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
