<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class ActivityLogService
{
    private const SENSITIVE_FIELDS = [
        'password',
        'remember_token',
        'proof_of_payment_path',
    ];

    private const IGNORED_FIELDS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'last_seen_at',
    ];

    private const STATUS_FIELDS = [
        'payment_status',
        'amenity_request_status',
        'inspection_status',
        'facility_status',
        'status',
    ];

    private const SEMANTIC_ACTIONS = [
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'canceled' => 'Cancelled',
        'no-show' => 'No-show',
        'checked-in' => 'Checked-in',
        'checked-out' => 'Checked-out',
        'completed' => 'Completed',
        'delivered' => 'Delivered',
        'paid' => 'Paid',
        'cleared' => 'Cleared',
        'damage found' => 'Damage Found',
    ];

    public function recordCreated(Model $model): void
    {
        $values = $this->sanitizeValues(
            $model->getAttributes(),
        );

        $this->store(
            action: 'Created',
            model: $model,
            oldValues: null,
            newValues: $values,
            description:
                'Created '.$this->subjectLabel($model),
        );
    }

    public function recordUpdated(Model $model): void
    {
        $changedKeys = array_keys(
            $model->getChanges(),
        );

        $changedKeys = array_values(
            array_diff(
                $changedKeys,
                self::IGNORED_FIELDS,
            ),
        );

        if ($changedKeys === []) {
            return;
        }

        $oldValues = [];
        $newValues = [];

        foreach ($changedKeys as $key) {
            $oldValues[$key] =
                $model->getOriginal($key);

            $newValues[$key] =
                $model->getAttribute($key);
        }

        $oldValues = $this->sanitizeValues(
            $oldValues,
        );

        $newValues = $this->sanitizeValues(
            $newValues,
        );

        $action = $this->resolveUpdateAction(
            $newValues,
        );

        $this->store(
            action: $action,
            model: $model,
            oldValues: $oldValues,
            newValues: $newValues,
            description: $this->updateDescription(
                $model,
                $oldValues,
                $newValues,
                $action,
            ),
        );
    }

    public function recordDeleted(Model $model): void
    {
        $values = $this->sanitizeValues(
            $model->getAttributes(),
        );

        $this->store(
            action: 'Deleted',
            model: $model,
            oldValues: $values,
            newValues: null,
            description:
                'Deleted '.$this->subjectLabel($model),
        );
    }

    private function store(
        string $action,
        Model $model,
        ?array $oldValues,
        ?array $newValues,
        string $description,
    ): void {
        try {
            ActivityLog::query()->create([
                'user_id' => auth()->id(),
                'action' => $action,
                'module' =>
                    $this->moduleName($model),
                'subject_type' => $model::class,
                'subject_id' =>
                    is_numeric($model->getKey())
                        ? (int) $model->getKey()
                        : null,
                'subject_label' =>
                    $this->subjectLabel($model),
                'description' => $description,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' =>
                    app()->runningInConsole()
                        ? null
                        : request()->ip(),
                'user_agent' =>
                    app()->runningInConsole()
                        ? null
                        : Str::limit(
                            (string) request()
                                ->userAgent(),
                            1000,
                            '',
                        ),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Audit logging must never make the resort transaction fail.
            report($exception);
        }
    }

    private function resolveUpdateAction(
        array $newValues,
    ): string {
        foreach (self::STATUS_FIELDS as $field) {
            if (! array_key_exists($field, $newValues)) {
                continue;
            }

            $normalized = strtolower(
                trim((string) $newValues[$field]),
            );

            if (
                array_key_exists(
                    $normalized,
                    self::SEMANTIC_ACTIONS,
                )
            ) {
                return self::SEMANTIC_ACTIONS[
                    $normalized
                ];
            }
        }

        return 'Updated';
    }

    private function sanitizeValues(
        array $values,
    ): array {
        $sanitized = [];

        foreach ($values as $key => $value) {
            if (
                in_array(
                    $key,
                    self::IGNORED_FIELDS,
                    true,
                )
            ) {
                continue;
            }

            if (
                in_array(
                    $key,
                    self::SENSITIVE_FIELDS,
                    true,
                )
            ) {
                $sanitized[$key] =
                    $key === 'password'
                    || $key === 'remember_token'
                        ? '[REDACTED]'
                        : '[FILE STORED]';

                continue;
            }

            $sanitized[$key] =
                $this->normalizeValue($value);
        }

        return $sanitized;
    }

    private function normalizeValue(
        mixed $value,
    ): mixed {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(
                'Y-m-d H:i:s',
            );
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->normalizeValue($item),
                $value,
            );
        }

        if (
            is_bool($value)
            || is_int($value)
            || is_float($value)
            || $value === null
        ) {
            return $value;
        }

        return Str::limit(
            (string) $value,
            500,
            '…',
        );
    }

    private function moduleName(
        Model $model,
    ): string {
        return match (class_basename($model)) {
            'User' => 'User Management',
            'EntranceFee' =>
                'Entrance Fee Management',
            'Discount' =>
                'Discount Management',
            'Facility',
            'FacilityPrice',
            'FacilityAmenity' =>
                'Facility Management',
            'Amenity' =>
                'Amenity Management',
            'Fine',
            'DamageType' =>
                'Fines Management',
            'Reservation',
            'ReservationDetail' =>
                'Reservation',
            'Booking',
            'BookingDetail' =>
                'Booking',
            'EntranceSlip' =>
                'Entrance Slip',
            'Payment' => 'Payment',
            'AmenityRequest' =>
                'Amenity Request',
            'FacilityInspectionRequest',
            'FacilityInspection' =>
                'Facility Inspection',
            'GuestFine' => 'Guest Fine',
            default => Str::headline(
                class_basename($model),
            ),
        };
    }

    private function subjectLabel(
        Model $model,
    ): string {
        $referenceFields = [
            'b_ref_no',
            'r_ref_no',
            'p_ref_no',
            'facility_name',
            'username',
            'booking_details_id',
            'reservation_details_id',
            'amenity_request_id',
            'entrance_slip_id',
            'facility_inspection_request_id',
            'facility_inspection_id',
            'guest_fine_id',
        ];

        foreach ($referenceFields as $field) {
            $value = $model->getAttribute(
                $field,
            );

            if (filled($value)) {
                return class_basename($model)
                    .' '
                    .$value;
            }
        }

        $key = $model->getKey();

        return class_basename($model)
            .(
                $key !== null
                    ? ' #'.$key
                    : ''
            );
    }

    private function updateDescription(
        Model $model,
        array $oldValues,
        array $newValues,
        string $action,
    ): string {
        $changes = [];

        foreach ($newValues as $field => $newValue) {
            $oldValue = $oldValues[$field] ?? null;

            if (
                $field === 'password'
                || $field === 'remember_token'
            ) {
                $changes[] =
                    Str::headline($field)
                    .' changed';

                continue;
            }

            $changes[] = sprintf(
                '%s: %s → %s',
                Str::headline($field),
                $this->displayValue($oldValue),
                $this->displayValue($newValue),
            );

            if (count($changes) >= 4) {
                break;
            }
        }

        $suffix = $changes === []
            ? ''
            : ' ('.implode('; ', $changes).')';

        $verb = $action === 'Updated'
            ? 'Updated'
            : $action;

        return $verb
            .' '
            .$this->subjectLabel($model)
            .$suffix;
    }

    private function displayValue(
        mixed $value,
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            return 'blank';
        }

        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        if (is_array($value)) {
            return Str::limit(
                json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES,
                ) ?: 'array',
                80,
                '…',
            );
        }

        return '"'
            .Str::limit(
                (string) $value,
                80,
                '…',
            )
            .'"';
    }
}
