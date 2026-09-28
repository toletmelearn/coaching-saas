<?php

namespace App\Exceptions;

use Exception;

class LessonCourseMismatchException extends Exception
{
    public function __construct(string $message = "Lesson's course_id does not match its chapter's course")
    {
        parent::__construct($message);
    }
}
