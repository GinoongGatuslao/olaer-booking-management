<?php

namespace App\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Facility product codes are free-form, admin-defined strings (for example
 * COTTAGE_JUMBO or FUNCTION_HALL_3). The old FacilityProductCode enum is still
 * accepted when writing so existing seeders and tests keep working, but reads
 * always return the plain string.
 *
 * @implements CastsAttributes<string|null, string|BackedEnum|null>
 */
class ProductCodeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return [$key => null];
        }

        if (! is_string($value) || ! preg_match('/^[A-Z0-9_]{2,50}$/', $value)) {
            throw new InvalidArgumentException(
                'Product code must be 2-50 characters of A-Z, 0-9 and underscores.',
            );
        }

        return [$key => $value];
    }
}
