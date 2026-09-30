<?php

namespace App\Http\Controllers;

use App\Actions\EnrolStudentAction;
use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\User;
use App\Support\Import\CsvImportPreviewBuilder;
use App\Support\Import\ImportSessionStore;
use App\Support\TemporaryPasswordGenerator;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class UserImportController extends Controller
{
    public function __construct(private readonly EnrolStudentAction $enrolStudent) {}

    public function template(): Response
    {
        $this->authorizeImport();

        $csv = "\xEF\xBB\xBFname,phone,email\n"
            ."Asha Rao,9876543210,asha@example.com\n"
            ."Rahul Jain,9123456780,rahul@example.com\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=student-import-template.csv',
        ]);
    }

    public function create(): View
    {
        $this->authorizeImport();

        return view('users.import.create', [
            'courses' => Course::where('status', CourseStatus::Published)->orderBy('title')->get(),
        ]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        $user = $this->authorizeImport();

        $validator = Validator::make($request->all(), [
            'file' => [
                'required', 'file', 'extensions:csv', 'max:1024',
                function ($attribute, $value, $fail) {
                    // Content-based check, not just the .csv extension: finfo/MIME
                    // guessing is too fragile here (a legitimate CSV cell containing
                    // markup-like text, e.g. "<script>...", can make content sniffers
                    // guess text/html), so a genuinely binary upload is instead
                    // detected directly by its share of raw control bytes — real CSV/
                    // text content essentially never has any.
                    $sample = @file_get_contents($value->getRealPath(), false, null, 0, 8192);

                    if ($sample === false) {
                        $fail(__('import.invalid_file'));

                        return;
                    }

                    $controlBytes = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);

                    if ($sample !== '' && ($controlBytes / strlen($sample)) > 0.01) {
                        $fail(__('import.invalid_file'));
                    }
                },
            ],
            'course_id' => ['nullable', 'integer'],
            'ends_at' => ['nullable', 'date'],
            'payment_note' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $courseId = $request->input('course_id');

            if (! empty($courseId)) {
                $course = Course::where('id', $courseId)->first();

                if ($course === null || $course->status !== CourseStatus::Published) {
                    $validator->errors()->add('course_id', __('import.course_not_published'));
                }
            }
        });

        $data = $validator->validate();

        $contents = file_get_contents($data['file']->getRealPath());

        try {
            $preview = app(CsvImportPreviewBuilder::class)->build($contents);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $tenant = app(TenantContext::class)->get();

        // Same default the manual enrolment screen's form pre-fills client-side: an
        // empty ends_at defaults to the institute's academic year end when a course was
        // chosen — applied here server-side since the import flow has no interactive
        // form to pre-fill for each upload.
        $endsAtInput = $data['ends_at'] ?? null;
        if (empty($endsAtInput) && ! empty($data['course_id']) && $tenant->academic_year_end !== null) {
            $endsAtInput = $tenant->academic_year_end->toDateString();
        }

        $token = $this->previewStore()->put([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'rows' => $preview['rows'],
            'course_id' => $data['course_id'] ?? null,
            'ends_at' => $endsAtInput,
            'payment_note' => $data['payment_note'] ?? null,
        ]);

        return view('users.import.preview', [
            'token' => $token,
            'rows' => $preview['rows'],
            'ok' => $preview['ok'],
            'problems' => $preview['problems'],
            'courses' => Course::where('status', CourseStatus::Published)->orderBy('title')->get(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->authorizeImport();
        $tenant = app(TenantContext::class)->get();

        $token = (string) $request->input('token', '');
        $store = $this->previewStore();
        $payload = $store->get($token);

        if ($payload === null
            || ($payload['tenant_id'] ?? null) !== $tenant->id
            || ($payload['created_by'] ?? null) !== $user->id) {
            abort(404);
        }

        if ($payload['consumed'] ?? false) {
            return redirect('/users/import')->with('error', __('import.already_processed'));
        }

        if ($store->isExpired($payload)) {
            abort(404);
        }

        $course = null;
        if (! empty($payload['course_id'])) {
            $course = Course::where('id', $payload['course_id'])->where('status', CourseStatus::Published)->first();
        }

        $endsAt = ! empty($payload['ends_at'])
            ? Carbon::parse($payload['ends_at'], 'Asia/Kolkata')->endOfDay()->utc()
            : null;

        $okRows = array_values(array_filter($payload['rows'], fn ($row) => $row['status'] === 'ok'));
        $credentials = [];

        try {
            DB::transaction(function () use ($okRows, $course, $endsAt, $payload, $user, &$credentials) {
                foreach ($okRows as $row) {
                    $temporaryPassword = TemporaryPasswordGenerator::generate();

                    $student = new User([
                        'name' => $row['name'],
                        'email' => $row['email'] !== '' ? $row['email'] : null,
                        'phone' => $row['phone'] !== '' ? $row['phone'] : null,
                    ]);
                    $student->forceFill([
                        'role' => UserRole::Student,
                        'status' => UserStatus::Active,
                        'must_change_password' => true,
                        'password' => Hash::make($temporaryPassword),
                    ]);
                    $student->save();

                    if ($course !== null) {
                        ($this->enrolStudent)($course, $student, now(), $endsAt, $payload['payment_note'] ?? null, $user->id);
                    }

                    $credentials[] = [
                        'name' => $student->name,
                        'login' => $student->phone ?? $student->email,
                        'phone' => $student->phone,
                        'password' => $temporaryPassword,
                    ];
                }
            });
        } catch (Throwable) {
            return redirect('/users/import')->with('error', __('import.batch_failed'));
        }

        $store->markConsumed($token, $payload);

        $sheetToken = $this->sheetStore()->put([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'rows' => $credentials,
        ]);

        $request->session()->put('import_sheet_token', $sheetToken);

        return redirect("/users/import/sheet/{$sheetToken}");
    }

    private function authorizeImport(): User
    {
        $user = Auth::guard('tenant')->user();
        abort_unless($user->role->canManageUsers(), 403);

        return $user;
    }

    private function previewStore(): ImportSessionStore
    {
        return new ImportSessionStore(session()->driver(), 'import.preview', 15);
    }

    private function sheetStore(): ImportSessionStore
    {
        return new ImportSessionStore(session()->driver(), 'import.sheet', 15);
    }
}
