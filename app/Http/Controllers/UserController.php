<?php

namespace App\Http\Controllers;

use App\Enums\ConsentMethod;
use App\Enums\ConsentPurpose;
use App\Enums\DeviceRevocationReason;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Consent;
use App\Models\User;
use App\Support\Devices\DeviceRegistrar;
use App\Support\LoginRateLimiter;
use App\Support\TemporaryPasswordGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly DeviceRegistrar $deviceRegistrar) {}

    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        $actor = Auth::guard('tenant')->user();

        $query = User::query()
            ->withCount(['devices as active_device_count' => function ($query) {
                $query->whereNull('revoked_at');
            }])
            ->withMax('devices as last_device_active_at', 'last_seen_at');

        if ($actor->role === UserRole::Staff) {
            $query->where('role', UserRole::Student);
        }

        $users = $query->orderBy('name')->paginate(20);

        return view('users.index', ['users' => $users]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', [
            'noticeVersion' => Consent::NOTICE_VERSION,
            'purposes' => ConsentPurpose::cases(),
            'methods' => ConsentMethod::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['nullable', new Enum(UserRole::class)],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'email' => __('messages.errors.contact_required'),
                'phone' => __('messages.errors.contact_required'),
            ]);
        }

        $role = isset($data['role']) ? UserRole::from($data['role']) : UserRole::Student;

        Gate::authorize('createWithRole', [User::class, $role]);

        // Phase 15: creating a student is a consent event. Guardian details and
        // a consent for every purpose are required for role=student only — they
        // are validated after the authorisation checks above so an unauthorised
        // actor still gets the 403 they were always owed, and after the contact
        // check so its error keys keep precedence. Any other role skips this
        // entirely: guardian fields posted alongside a non-student are dropped.
        $consentData = null;

        if ($role === UserRole::Student) {
            $consentData = $request->validate([
                'guardian_name' => ['required', 'string', 'max:255'],
                'guardian_relationship' => ['required', 'string', 'max:100'],
                'guardian_phone' => ['required', 'string', 'max:20'],
                'guardian_email' => ['nullable', 'email', 'max:255'],
                'consents' => ['required', 'array'],
                'consents.*' => ['string', new Enum(ConsentPurpose::class)],
                'consent_method' => ['required', new Enum(ConsentMethod::class)],
            ]);

            if (array_diff(ConsentPurpose::values(), $consentData['consents']) !== []) {
                throw ValidationException::withMessages([
                    'consents' => __('consents.errors.purposes_required'),
                ]);
            }
        }

        $temporaryPassword = TemporaryPasswordGenerator::generate();

        $user = DB::transaction(function () use ($data, $role, $consentData, $request, $temporaryPassword) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $user->forceFill([
                'role' => $role,
                'status' => UserStatus::Active,
                'must_change_password' => true,
                'password' => Hash::make($temporaryPassword),
            ]);

            $user->save();

            if ($consentData !== null) {
                $user->forceFill([
                    'guardian_name' => $consentData['guardian_name'],
                    'guardian_relationship' => $consentData['guardian_relationship'],
                    'guardian_phone' => $consentData['guardian_phone'],
                    'guardian_email' => $consentData['guardian_email'] ?? null,
                ])->save();

                // One consent per purpose, all stamped with the same notice
                // version, collection method and recorder — in the same
                // transaction as the student, so a partial create is impossible.
                foreach (ConsentPurpose::values() as $purpose) {
                    $consent = new Consent;
                    $consent->forceFill([
                        'user_id' => $user->id,
                        'purpose' => $purpose,
                        'method' => $consentData['consent_method'],
                        'notice_version' => Consent::NOTICE_VERSION,
                        'granted_at' => now(),
                        'recorded_by' => $request->user('tenant')->id,
                    ]);
                    $consent->save();
                }
            }

            return $user;
        });

        return $this->redirectWithTemporaryPassword($user, $temporaryPassword);
    }

    public function show(User $user): View
    {
        Gate::authorize('view', $user);

        return view('users.show', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', new Enum(UserRole::class)],
        ]);

        // Authorised whenever `role` is present, even when it equals the current role: a self-edit
        // that names a role is refused outright (UserPolicy::changeRole), so a probe cannot tell
        // whether the value would have changed.
        if (array_key_exists('role', $data)) {
            Gate::authorize('changeRole', $user);
            $user->forceFill(['role' => UserRole::from($data['role'])]);
        }

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        $user->save();

        return redirect()->route('users.index');
    }

    public function disable(User $user): RedirectResponse
    {
        Gate::authorize('disable', $user);

        $user->forceFill(['status' => UserStatus::Disabled])->save();

        if ($user->role === UserRole::Student) {
            $this->deviceRegistrar->revokeAll($user, DeviceRevocationReason::Disabled);
        }

        return redirect()->route('users.index');
    }

    public function enable(User $user): RedirectResponse
    {
        Gate::authorize('enable', $user);

        $user->forceFill(['status' => UserStatus::Active])->save();

        return redirect()->route('users.index');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        Gate::authorize('resetPassword', $user);

        $temporaryPassword = TemporaryPasswordGenerator::generate();

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ])->save();

        if ($user->role === UserRole::Student) {
            $this->deviceRegistrar->revokeAll($user, DeviceRevocationReason::PasswordReset);
        }

        // Lets a teacher unblock a locked-out student in the same action as resetting
        // their password, instead of a separate support request.
        LoginRateLimiter::clearForUser($user->tenant_id, $user);

        return $this->redirectWithTemporaryPassword($user, $temporaryPassword);
    }

    private function redirectWithTemporaryPassword(User $user, string $temporaryPassword): RedirectResponse
    {
        return redirect()->route('users.index')
            ->with('temporary_password', $temporaryPassword)
            ->with('temporary_password_for', [
                'name' => $user->name,
                'identifier' => $user->email ?? $user->phone,
            ]);
    }
}
