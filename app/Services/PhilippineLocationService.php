<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Region / province / city-municipality / barangay lists for the address dropdowns.
 * Data lives in the tbl_ph_* tables (filled by `php artisan locations:import`).
 * While those tables are missing or empty every method returns [] and the forms fall back
 * to plain text inputs, so nothing breaks before the import has been run.
 *
 * Addresses are saved as text (names), so everything here is looked up by name.
 */
class PhilippineLocationService
{
    private const VERSION_KEY = 'ph_locations.version';

    /** @return array<int, string> */
    public function regions(): array
    {
        return $this->remember('regions', fn (): array => DB::table('tbl_ph_region')
            ->orderBy('region_code')
            ->pluck('name')
            ->all());
    }

    /**
     * @param  string  $region  limit to this region ('' = every province)
     * @param  string  $include  a value already saved on a record; kept in the list even
     *                           when it is not an official name (older free-text addresses)
     * @return array<int, string>
     */
    public function provinces(string $region = '', string $include = ''): array
    {
        $region = trim($region);

        $names = $this->remember('provinces.'.md5(mb_strtolower($region)), function () use ($region): array {
            $query = DB::table('tbl_ph_province as p')->orderBy('p.name');

            if ($region !== '') {
                $query->join('tbl_ph_region as r', 'r.region_code', '=', 'p.region_code')
                    ->where('r.name', $region);
            }

            return $query->distinct()->pluck('p.name')->all();
        });

        if ($names === [] && $region !== '') {
            // Unknown region: fall back to every province rather than an empty box.
            return $this->provinces('', $include);
        }

        return $names === [] ? [] : $this->withCurrent($names, $include);
    }

    /** @return array<int, string> */
    public function cities(string $province, string $include = ''): array
    {
        $province = trim($province);

        if ($province === '') {
            return [];
        }

        $names = $this->remember('cities.'.md5(mb_strtolower($province)), fn (): array => DB::table('tbl_ph_city as c')
            ->join('tbl_ph_province as p', 'p.province_code', '=', 'c.province_code')
            ->where('p.name', $province)
            ->orderBy('c.name')
            ->distinct()
            ->pluck('c.name')
            ->all());

        if ($names === []) {
            // Older free-text province: the only choice is the city already saved.
            return trim($include) === '' ? [] : [trim($include)];
        }

        return $this->withCurrent($names, $include);
    }

    /** @return array<int, string> */
    public function barangays(string $province, string $city, string $include = ''): array
    {
        $province = trim($province);
        $city = trim($city);

        if ($province === '' || $city === '') {
            return [];
        }

        $names = $this->remember('barangays.'.md5(mb_strtolower($province.'|'.$city)), fn (): array => DB::table('tbl_ph_barangay as b')
            ->join('tbl_ph_city as c', 'c.city_code', '=', 'b.city_code')
            ->join('tbl_ph_province as p', 'p.province_code', '=', 'c.province_code')
            ->where('p.name', $province)
            ->where('c.name', $city)
            ->orderBy('b.name')
            ->distinct()
            ->pluck('b.name')
            ->all());

        if ($names === []) {
            return trim($include) === '' ? [] : [trim($include)];
        }

        return $this->withCurrent($names, $include);
    }

    /** The region a province belongs to, or null when the province is not in the list. */
    public function regionOfProvince(string $province): ?string
    {
        $province = trim($province);

        if ($province === '') {
            return null;
        }

        try {
            $name = DB::table('tbl_ph_province as p')
                ->join('tbl_ph_region as r', 'r.region_code', '=', 'p.region_code')
                ->where('p.name', $province)
                ->value('r.name');
        } catch (Throwable) {
            return null;
        }

        return is_string($name) ? $name : null;
    }

    /** Forget cached lists (called after an import). */
    public function flush(): void
    {
        Cache::put(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
    }

    /**
     * @param  callable(): array<int, string>  $load
     * @return array<int, string>
     */
    private function remember(string $key, callable $load): array
    {
        $cacheKey = 'ph_locations.'.(int) Cache::get(self::VERSION_KEY, 1).'.'.$key;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $names = array_values($load());
        } catch (Throwable) {
            return []; // tables not created yet
        }

        if ($names !== []) {
            Cache::put($cacheKey, $names, now()->addDay());
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function withCurrent(array $names, string $include): array
    {
        $include = trim($include);

        if ($include !== '' && ! in_array($include, $names, true)) {
            $names[] = $include;
        }

        return $names;
    }
}
