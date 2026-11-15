<?php

namespace App\Console\Commands;

use App\Services\PhilippineLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ImportPhilippineLocations extends Command
{
    /**
     * Source: https://github.com/wilfredpine/philippine-address-selector (MIT), ph-json folder,
     * which is built from the Philippine Statistics Authority's PSGC data.
     */
    private const DEFAULT_BASE_URL = 'https://raw.githubusercontent.com/wilfredpine/philippine-address-selector/main/ph-json/';

    /** Words kept lower-case inside a name ("San Jose del Monte") when a name arrives in capitals. */
    private const SMALL_WORDS = ['of', 'del', 'de', 'la', 'las', 'los', 'y', 'ng', 'sa', 'dela', 'delos'];

    protected $signature = 'locations:import
        {--base-url= : Folder holding region.json, province.json, city.json and barangay.json (default: wilfredpine/philippine-address-selector on GitHub)}
        {--without-barangays : Skip the large barangay list (the barangay field stays a text box)}';

    protected $description = 'Download the Philippine region, province, city/municipality and barangay lists from GitHub for the address dropdowns';

    public function handle(PhilippineLocationService $locations): int
    {
        // barangay.json is several MB once decoded.
        ini_set('memory_limit', '768M');

        $base = rtrim((string) ($this->option('base-url') ?: self::DEFAULT_BASE_URL), '/').'/';
        $withBarangays = ! $this->option('without-barangays');

        try {
            $this->info('Downloading regions…');
            $regionRows = $this->download($base.'region.json');
            $this->info('Downloading provinces…');
            $provinceRows = $this->download($base.'province.json');
            $this->info('Downloading cities and municipalities…');
            $cityRows = $this->download($base.'city.json');
            $barangayRows = [];

            if ($withBarangays) {
                $this->info('Downloading barangays (large file)…');
                $barangayRows = $this->download($base.'barangay.json');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $regions = [];
        foreach ($regionRows as $row) {
            $code = $this->code($row['region_code'] ?? null);
            $name = $this->name($row['region_name'] ?? $row['name'] ?? null, keepNotes: true);

            if ($code !== null && $name !== null) {
                $regions[$code] = ['region_code' => $code, 'name' => $name];
            }
        }

        $provinces = [];
        foreach ($provinceRows as $row) {
            $code = $this->code($row['province_code'] ?? null);
            $region = $this->code($row['region_code'] ?? null);
            $name = $this->name($row['province_name'] ?? $row['name'] ?? null);

            if ($code !== null && $region !== null && $name !== null && isset($regions[$region])) {
                $provinces[$code] = ['province_code' => $code, 'region_code' => $region, 'name' => $name];
            }
        }

        $cities = [];
        foreach ($cityRows as $row) {
            $code = $this->code($row['city_code'] ?? null);
            $province = $this->code($row['province_code'] ?? null);
            $name = $this->name($row['city_name'] ?? $row['name'] ?? null, isCity: true);

            if ($code !== null && $province !== null && $name !== null && isset($provinces[$province])) {
                $cities[$code] = ['city_code' => $code, 'province_code' => $province, 'name' => $name];
            }
        }

        $barangays = [];
        foreach ($barangayRows as $row) {
            $code = $this->code($row['brgy_code'] ?? $row['barangay_code'] ?? null);
            $city = $this->code($row['city_code'] ?? null);
            $name = $this->name($row['brgy_name'] ?? $row['barangay_name'] ?? $row['name'] ?? null);

            if ($code !== null && $city !== null && $name !== null && isset($cities[$city])) {
                $barangays[$code] = ['brgy_code' => $code, 'city_code' => $city, 'name' => $name];
            }
        }

        unset($barangayRows, $cityRows, $provinceRows, $regionRows);

        // Never wipe a working list because of a bad download.
        if (count($regions) < 10 || count($provinces) < 70 || count($cities) < 1000 || ($withBarangays && count($barangays) < 30000)) {
            $this->error(sprintf(
                'The downloaded data looks incomplete (%d regions, %d provinces, %d cities, %d barangays); nothing was changed.',
                count($regions), count($provinces), count($cities), count($barangays),
            ));

            return self::FAILURE;
        }

        DB::transaction(function () use ($regions, $provinces, $cities, $barangays, $withBarangays): void {
            DB::table('tbl_ph_barangay')->delete();
            DB::table('tbl_ph_city')->delete();
            DB::table('tbl_ph_province')->delete();
            DB::table('tbl_ph_region')->delete();

            foreach (array_chunk(array_values($regions), 500) as $chunk) {
                DB::table('tbl_ph_region')->insert($chunk);
            }
            foreach (array_chunk(array_values($provinces), 500) as $chunk) {
                DB::table('tbl_ph_province')->insert($chunk);
            }
            foreach (array_chunk(array_values($cities), 500) as $chunk) {
                DB::table('tbl_ph_city')->insert($chunk);
            }
            if ($withBarangays) {
                foreach (array_chunk(array_values($barangays), 1000) as $chunk) {
                    DB::table('tbl_ph_barangay')->insert($chunk);
                }
            }
        });

        $locations->flush();

        $this->info(sprintf(
            'Imported %d regions, %d provinces, %d cities/municipalities and %d barangays.',
            count($regions), count($provinces), count($cities), count($barangays),
        ));

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function download(string $url): array
    {
        $response = Http::timeout(180)->retry(2, 500, throw: false)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Could not download {$url} (HTTP {$response->status()}). Check the server's internet access, or pass --base-url.");
        }

        $data = $response->json();

        if (! is_array($data) || $data === []) {
            throw new RuntimeException("{$url} did not return a JSON list.");
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function code(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Tidy a name: collapse spacing, title-case names the source wrote in capitals, and write
     * "City of Tacurong" as "Tacurong City" (the form already used in this system's data).
     * Names are cut to 50 characters because that is the width of the tbl_address columns.
     */
    private function name(mixed $raw, bool $isCity = false, bool $keepNotes = false): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

        if ($name === mb_strtoupper($name) && preg_match('/\p{L}/u', $name) === 1) {
            $words = explode(' ', mb_convert_case(mb_strtolower($name), MB_CASE_TITLE));

            foreach ($words as $index => $word) {
                if ($index > 0 && in_array(mb_strtolower($word), self::SMALL_WORDS, true)) {
                    $words[$index] = mb_strtolower($word);
                }
            }

            $name = implode(' ', $words);
        }

        if ($isCity && preg_match('/^city of (.+)$/i', $name, $match) === 1) {
            $name = trim($match[1]).' City';
        }

        return Str::limit($name, $keepNotes ? 100 : 50, '');
    }
}
