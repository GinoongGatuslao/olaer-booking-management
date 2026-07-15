<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
     * Define the model's default state for Olaer's custom tbl_user schema.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'contact_no' => fake()->unique()->numerify('09#########'),
            'status' => 'Active',

            'address_id' => fn (): int => (int) Address::query()
                ->create([
                    'purok' => 'Purok '.fake()->numberBetween(1, 10),
                    'province' => 'Sultan Kudarat',
                    'city' => 'Tacurong City',
                    'barangay' => fake()->streetName(),
                ])
                ->address_id,

            'role_id' => fn (): int => (int) Role::query()
                ->firstOrCreate([
                    'role_name' => 'Admin',
                ])
                ->role_id,

            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the account email is unverified.
     */
    public function unverified(): static
    {
        return $this->state(
            fn (array $attributes): array => [
                'email_verified_at' => null,
            ],
        );
    }

    /**
     * Add two-factor fields only when the custom user table actually has them.
     *
     * Olaer's current schema does not include these columns and Fortify 2FA is
     * disabled, but keeping this conditional state makes the factory safe if
     * those columns are added later.
     */
    public function withTwoFactor(): static
    {
        return $this->state(
            function (array $attributes): array {
                $state = [];

                if (
                    Schema::hasColumn(
                        'tbl_user',
                        'two_factor_secret',
                    )
                ) {
                    $state['two_factor_secret'] =
                        encrypt('secret');
                }

                if (
                    Schema::hasColumn(
                        'tbl_user',
                        'two_factor_recovery_codes',
                    )
                ) {
                    $state['two_factor_recovery_codes'] =
                        encrypt(
                            json_encode([
                                'recovery-code-1',
                            ]),
                        );
                }

                if (
                    Schema::hasColumn(
                        'tbl_user',
                        'two_factor_confirmed_at',
                    )
                ) {
                    $state['two_factor_confirmed_at'] =
                        now();
                }

                return $state;
            },
        );
    }
}
