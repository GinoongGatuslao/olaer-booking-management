<?php

namespace App\Services;

use App\Models\FacilityProduct;
use App\Models\GuestSubmissionConfirmation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Guest reservations and bookings are only created after the guest clicks the
 * link in an email. This service stores the request, emails the link, and
 * creates the reservation/booking (with its payment) when the link is opened.
 */
class GuestSubmissionConfirmationService
{
    public const LINK_MINUTES = 60;

    public function __construct(
        private readonly FacilityRequirementService $requirements,
        private readonly FacilityAssignmentService $assignments,
        private readonly GcashProofStorageService $proofStorage,
        private readonly GcashReferenceIntegrityService $gcashReferences,
        private readonly DecimalMoneyService $money,
    ) {}

    /**
     * The same payment rules the reservation/booking itself applies, checked
     * up front so the guest is not emailed a link that cannot work.
     */
    public function assertPaymentTerms(string $type, string $planTotal, string $paymentAmount, string $referenceNumber): void
    {
        $total = $this->money->normalize($planTotal);
        $amount = $this->money->normalize($paymentAmount);

        if ($type === 'booking') {
            if (! $this->money->equals($amount, $total)) {
                throw new InvalidArgumentException(
                    'Direct online booking requires exact full GCash payment before submission. Staff will still verify the proof.',
                );
            }
        } else {
            if ($this->money->compare($amount, $this->money->percentage($total, '0.500000')) === -1) {
                throw new InvalidArgumentException('Reservation downpayment must be at least 50% of the final discounted total.');
            }

            if ($this->money->compare($amount, $total) === 1) {
                throw new InvalidArgumentException('Reservation downpayment cannot exceed the reservation total.');
            }
        }

        $this->gcashReferences->assertAvailable(trim($referenceNumber));
    }

    /**
     * Stores the request and emails the confirmation link.
     *
     * @param  array<string, mixed>  $payload  party_count, groups, plan_total, guest, payment_amount, reference_number, proof_of_payment_path
     */
    public function request(string $type, array $payload): GuestSubmissionConfirmation
    {
        if (! in_array($type, ['reservation', 'booking'], true)) {
            throw new InvalidArgumentException('Unknown request type.');
        }

        $email = trim((string) ($payload['guest']['email'] ?? ''));

        if ($email === '') {
            throw new InvalidArgumentException('An email address is required to send the confirmation link.');
        }

        $this->pruneExpired();

        // A newer request from the same email replaces an earlier unconfirmed one.
        GuestSubmissionConfirmation::query()
            ->where('type', $type)
            ->where('email', $email)
            ->where('status', 'Pending')
            ->get()
            ->each(fn (GuestSubmissionConfirmation $old) => $this->discard($old, 'Replaced by a newer request.'));

        $rawToken = Str::random(64);

        $submission = GuestSubmissionConfirmation::query()->create([
            'token_hash' => hash('sha256', $rawToken),
            'type' => $type,
            'email' => $email,
            'payload' => $payload,
            'status' => 'Pending',
            'expires_at' => Carbon::now()->addMinutes(self::LINK_MINUTES),
        ]);

        if (! $this->sendLink($submission, $rawToken)) {
            $this->discard($submission, 'The confirmation email could not be sent.', 'Failed');

            throw new InvalidArgumentException(
                'We could not send the confirmation email. Please check your email address and try again.',
            );
        }

        return $submission;
    }

    /**
     * Opens the link: creates the reservation/booking and records the payment.
     * Safe to open more than once; the second time it returns the same result.
     */
    public function confirm(string $rawToken): GuestSubmissionConfirmation
    {
        $submission = GuestSubmissionConfirmation::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($submission === null) {
            throw new InvalidArgumentException('This confirmation link is not valid.');
        }

        $result = DB::transaction(function () use ($submission): array {
            $locked = GuestSubmissionConfirmation::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if ($locked->status === 'Confirmed') {
                return ['submission' => $locked, 'error' => null];
            }

            if ($locked->status !== 'Pending') {
                return ['submission' => $locked, 'error' => $locked->failure_reason
                    ?: 'This confirmation link is no longer valid. Please make your request again.'];
            }

            if ($locked->expires_at->isPast()) {
                $this->discard($locked, 'The confirmation link expired.');

                return ['submission' => $locked, 'error' => 'This confirmation link has expired (it is valid for '
                    .self::LINK_MINUTES.' minutes). Please make your request again.'];
            }

            try {
                $this->createRecords($locked);
            } catch (InvalidArgumentException $exception) {
                $this->discard($locked, $exception->getMessage(), 'Failed');

                return ['submission' => $locked, 'error' => $exception->getMessage()
                    .' Please make your request again.'];
            }

            return ['submission' => $locked->fresh(), 'error' => null];
        });

        if ($result['error'] !== null) {
            throw new InvalidArgumentException($result['error']);
        }

        return $result['submission'];
    }

    private function createRecords(GuestSubmissionConfirmation $submission): void
    {
        $payload = (array) $submission->payload;
        $partyCount = (int) ($payload['party_count'] ?? 1);

        $intent = $this->requirements->createIntent('confirm-'.$submission->getKey(), $partyCount);
        $intent = $this->requirements->replaceGroups($intent, $partyCount, (array) ($payload['groups'] ?? []));

        $data = [
            ...(array) ($payload['guest'] ?? []),
            'payment_amount' => $payload['payment_amount'] ?? null,
            'reference_number' => $payload['reference_number'] ?? null,
            'proof_of_payment_path' => $payload['proof_of_payment_path'] ?? null,
        ];

        $ids = [];

        if ($submission->type === 'booking') {
            $ids['booking_id'] = (int) $this->assignments->createPendingBooking($intent, $data)->booking_id;
        } else {
            $ids['reservation_id'] = (int) $this->assignments->createReservation($intent, $data)->reservation_id;
        }

        $submission->update([
            ...$ids,
            'status' => 'Confirmed',
            'confirmed_at' => Carbon::now(),
            // The details now live on the reservation/booking; do not keep a second copy.
            'payload' => [],
        ]);
    }

    /** Drops an unconfirmed request, its proof file and its personal details. */
    private function discard(GuestSubmissionConfirmation $submission, string $reason, string $status = 'Expired'): void
    {
        $path = (string) (((array) $submission->payload)['proof_of_payment_path'] ?? '');

        if ($path !== '') {
            try {
                $this->proofStorage->deletePrivate($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $submission->update([
            'status' => $status,
            'failure_reason' => $reason,
            'payload' => [],
        ]);
    }

    private function pruneExpired(): void
    {
        GuestSubmissionConfirmation::query()
            ->where('status', 'Pending')
            ->where('expires_at', '<', Carbon::now())
            ->limit(25)
            ->get()
            ->each(fn (GuestSubmissionConfirmation $old) => $this->discard($old, 'The confirmation link expired.'));
    }

    private function sendLink(GuestSubmissionConfirmation $submission, string $rawToken): bool
    {
        try {
            $payload = (array) $submission->payload;
            $guest = (array) ($payload['guest'] ?? []);
            $groups = collect((array) ($payload['groups'] ?? []));

            $products = FacilityProduct::query()
                ->whereIn('facility_product_id', $groups->pluck('facility_product_id')->filter()->map(fn ($id) => (int) $id)->all())
                ->get()
                ->keyBy('facility_product_id');

            $lines = $groups->map(fn (array $group): array => [
                'name' => $products->get((int) ($group['facility_product_id'] ?? 0))?->display_name ?? 'Facility',
                'quantity' => (int) ($group['quantity'] ?? 1),
                'check_in' => Carbon::parse((string) $group['check_in_date'])->format('M d, Y'),
                'check_out' => Carbon::parse((string) $group['check_out_date'])->format('M d, Y'),
            ])->values()->all();

            Mail::send('emails.guest.submission-confirmation', [
                'type' => $submission->type,
                'guestName' => trim(($guest['first_name'] ?? '').' '.($guest['last_name'] ?? '')),
                'link' => route('guest.submissions.confirm', ['token' => $rawToken]),
                'minutes' => self::LINK_MINUTES,
                'lines' => $lines,
                'planTotal' => (string) ($payload['plan_total'] ?? '0.00'),
                'paymentAmount' => (string) ($payload['payment_amount'] ?? '0.00'),
                'referenceNumber' => (string) ($payload['reference_number'] ?? ''),
            ], function ($message) use ($submission): void {
                $message
                    ->to($submission->email)
                    ->subject('Confirm your '.$submission->type.' - Olaer Spring Resort');
            });

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
