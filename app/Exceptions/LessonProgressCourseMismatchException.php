<?php

namespace App\Exceptions;

use Exception;

class LessonProgressCourseMismatchException extends Exception
{
    public function __construct(string $message = "Lesson progress's course_id does not match its lesson's course")
    {
        parent::__construct($message);
    }
}
