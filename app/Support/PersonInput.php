<?php

namespace App\Support;

use Livewire\Component;

/**
 * Normalises person-related text typed into forms:
 *  - first / last names: first letter of each word is capitalised
 *    (the rest is left exactly as typed, so "McDonald" and "dela Cruz" are not mangled)
 *  - emails: trimmed and lower-cased
 */
class PersonInput
{
    /** Last path segments treated as a first/last name. */
    private const NAME_FIELDS = ['first_name', 'last_name', 'firstName', 'lastName'];

    public static function name(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return '';
        }

        // Capitalise the first letter at the start and after a space, hyphen, apostrophe or period.
        return (string) preg_replace_callback(
            '/(^|[\s\-\'’.])(\p{L})/u',
            fn (array $m): string => $m[1].mb_strtoupper($m[2]),
            $value,
        );
    }

    public static function email(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Call from a Livewire component's updated() hook. Reformats the property
     * that was just edited if it is a first name, last name or email field.
     * Works for flat ("first_name") and nested ("extra_guests.0.first_name") paths.
     */
    public static function normalize(Component $component, string $property): void
    {
        $segments = explode('.', $property);
        $field = end($segments);

        if ($field === 'email') {
            $format = [self::class, 'email'];
        } elseif (in_array($field, self::NAME_FIELDS, true)) {
            $format = [self::class, 'name'];
        } else {
            return;
        }

        $root = array_shift($segments);

        if (! property_exists($component, $root)) {
            return;
        }

        if ($segments === []) {
            if (is_string($component->{$root})) {
                $component->{$root} = $format($component->{$root});
            }

            return;
        }

        $data = $component->{$root};
        $current = data_get($data, implode('.', $segments));

        if (! is_array($data) || ! is_string($current)) {
            return;
        }

        data_set($data, implode('.', $segments), $format($current));
        $component->{$root} = $data;
    }
}
