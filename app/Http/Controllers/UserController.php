<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\LoginRateLimiter;
use App\Support\TemporaryPasswordGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        $actor = Auth::guard('tenant')->user();

        $query = User::query();

        if ($actor->role === UserRole::Staff) {
            $query->where('role', UserRole::Student);
        }

        $users = $query->orderBy('name')->paginate(20);

        return view('users.index', ['users' => $users]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create');
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

        $temporaryPassword = TemporaryPasswordGenerator::generate();

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

        if (array_key_exists('role', $data)) {
            $newRole = UserRole::from($data['role']);

            if ($newRole !== $user->role) {
                Gate::authorize('changeRole', $user);
                $user->forceFill(['role' => $newRole]);
            }
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
