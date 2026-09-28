<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Apply default values for guarded fields (role, status, must_change_password)
     * that cannot be set via mass assignment. Individual state methods below
     * override these via forceFill, applied after this default.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->forceFill([
                'role' => $user->role ?? 'student',
                'status' => $user->status ?? 'active',
                'must_change_password' => $user->must_change_password ?? false,
            ]);
        });
    }

    /**
     * Set the guarded `role` column via forceFill (role is not mass-assignable).
     */
    public function owner(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['role' => 'owner']));
    }

    public function staff(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['role' => 'staff']));
    }

    public function student(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['role' => 'student']));
    }

    /**
     * Set the guarded `status` column via forceFill (status is not mass-assignable).
     */
    public function active(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['status' => 'active']));
    }

    public function disabled(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['status' => 'disabled']));
    }

    /**
     * Set the guarded `must_change_password` column via forceFill.
     */
    public function mustChangePassword(bool $value = true): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['must_change_password' => $value]));
    }
}
