<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        $actor = Auth::guard('tenant')->user();

        $query = User::query();

        if ($actor->role === 'staff') {
            $query->where('role', 'student');
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
            'role' => ['nullable', 'in:owner,staff,student'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'email' => __('messages.errors.contact_required'),
                'phone' => __('messages.errors.contact_required'),
            ]);
        }

        $role = $data['role'] ?? 'student';

        Gate::authorize('createWithRole', [User::class, $role]);

        $temporaryPassword = Str::password(12);

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        $user->forceFill([
            'role' => $role,
            'status' => 'active',
            'must_change_password' => true,
            'password' => Hash::make($temporaryPassword),
        ]);

        $user->save();

        return redirect()->route('users.index')->with('temporary_password', $temporaryPassword);
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
            'role' => ['sometimes', 'in:owner,staff,student'],
        ]);

        if (array_key_exists('role', $data) && $data['role'] !== $user->role) {
            Gate::authorize('changeRole', $user);
            $user->forceFill(['role' => $data['role']]);
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

        $user->forceFill(['status' => 'disabled'])->save();

        return redirect()->route('users.index');
    }

    public function enable(User $user): RedirectResponse
    {
        Gate::authorize('enable', $user);

        $user->forceFill(['status' => 'active'])->save();

        return redirect()->route('users.index');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('resetPassword', $user);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => true,
        ])->save();

        return redirect()->route('users.index');
    }
}
