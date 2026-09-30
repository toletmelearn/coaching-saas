<?php

use App\Actions\EnrolStudentAction;

test('the shared enrolment action class exists with the expected invokable interface', function () {
    // class_exists() is used deliberately instead of referencing the class directly:
    // App\Actions\EnrolStudentAction::class is only a string literal at compile time (no
    // autoload is triggered by `use` or `::class`), so this fails as a clean assertion
    // failure — not a fatal "class not found" error — until the class is extracted in
    // Step 2, per Manage\EnrolmentController::store.
    expect(class_exists(EnrolStudentAction::class))->toBeTrue();

    $method = new ReflectionMethod(EnrolStudentAction::class, '__invoke');
    $paramNames = collect($method->getParameters())->map->getName()->all();

    // Interface assumed for Step 2: __invoke(Course $course, User $student, ?Carbon
    // $endsAt, ?string $paymentNote, int $enrolledBy): Enrolment — mirrors the fields
    // EnrolmentController::store already builds an Enrolment from.
    expect($paramNames)->toBe(['course', 'student', 'endsAt', 'paymentNote', 'enrolledBy']);
    expect((string) $method->getReturnType())->toBe('App\Models\Enrolment');
});
