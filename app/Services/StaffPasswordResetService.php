<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPasswordResetOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class StaffPasswordResetService
{
    private const OTP_LIFETIME_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /**
     * Always return normally so callers do not reveal whether an account exists.
     */
    public function requestOtp(string $identifier): array
    {
        $normalizedIdentifier = trim($identifier);

        $users = User::query()
            ->where('email', $normalizedIdentifier)
            ->where('status', 'Active')
            ->get();

        if ($users->isEmpty()) {
            Log::notice('Password reset requested for an unknown or inactive account.', [
                'identifier' => $normalizedIdentifier,
            ]);

            return [];
        }

        if ($users->count() > 1) {
            // Never guess which account to reset when an email is shared.
            Log::warning('Password reset blocked: the email belongs to more than one active account.', [
                'identifier' => $normalizedIdentifier,
                'user_ids' => $users->pluck('user_id')->all(),
            ]);

            throw new RuntimeException('This email address is used by more than one staff account. Ask the administrator to give each account its own email address.');
        }

        $user = $users->first();

        $plainCode = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $plainCode): void {
            UserPasswordResetOtp::query()
                ->where('user_id', $user->user_id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            UserPasswordResetOtp::create([
                'user_id' => $user->user_id,
                'code_hash' => Hash::make($plainCode),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::OTP_LIFETIME_MINUTES),
            ]);
        });

        try {
            Mail::send(
                [
                    'html' => 'emails.staff.password-reset-code',
                    'raw' => "Your Olaer Spring Resort password reset code is {$plainCode}. "
                        .'It expires in '.self::OTP_LIFETIME_MINUTES.' minutes. '
                        .'Do not share this code with anyone.',
                ],
                [
                    'name' => $user->first_name,
                    'code' => $plainCode,
                    'minutes' => self::OTP_LIFETIME_MINUTES,
                ],
                function ($message) use ($user): void {
                    $message->to($user->email, $user->full_name)
                        ->subject('Olaer Spring Resort Password Reset Code');
                }
            );
            return ['debug_otp' => app()->environment('local') ? $plainCode : null,];
        } catch (\Throwable $exception) {
            Log::error('Unable to send staff password reset OTP.', [
                'user_id' => $user->user_id,
                'exception' => $exception->getMessage(),
            ]);

            throw new RuntimeException('The reset code could not be sent. Please try again or contact the administrator.');
        }
    }

    /**
     * Verify the OTP and return a one-time raw reset token.
     */
    public function verifyOtp(string $identifier, string $plainCode): string
    {
        $user = $this->findActiveUser($identifier);

        $otp = UserPasswordResetOtp::query()
            ->where('user_id', $user->user_id)
            ->whereNull('used_at')
            ->latest('password_reset_otp_id')
            ->lockForUpdate()
            ->first();

        if (! $otp || $this->isExpired($otp)) {
            throw new RuntimeException('The reset code has expired. Request a new code.');
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('Too many incorrect attempts. Request a new code.');
        }

        if (! Hash::check($plainCode, $otp->code_hash)) {
            $otp->increment('attempts');

            throw new RuntimeException('The reset code is incorrect.');
        }

        $rawToken = Str::random(64);

        $otp->forceFill([
            'verified_at' => now(),
            'reset_token_hash' => hash('sha256', $rawToken),
        ])->save();

        return $rawToken;
    }

    /**
     * Returns the username of the account whose password was changed.
     */
    public function resetPassword(string $identifier, string $rawToken, string $newPassword): string
    {
        return DB::transaction(function () use ($identifier, $rawToken, $newPassword): string {
            $user = $this->findActiveUser($identifier);

            $otp = UserPasswordResetOtp::query()
                ->where('user_id', $user->user_id)
                ->whereNull('used_at')
                ->whereNotNull('verified_at')
                ->latest('password_reset_otp_id')
                ->lockForUpdate()
                ->first();

            $validToken = $otp
                && ! $this->isExpired($otp)
                && is_string($otp->reset_token_hash)
                && hash_equals($otp->reset_token_hash, hash('sha256', $rawToken));

            if (! $validToken) {
                throw new RuntimeException('This password reset session is invalid or expired. Request a new code.');
            }

            $user->forceFill([
                'password' => $newPassword,
                'remember_token' => Str::random(60),
            ])->save();

            // Make sure the new password was really stored.
            if (! Hash::check($newPassword, (string) $user->fresh()->password)) {
                throw new RuntimeException('The new password could not be saved. Please try again.');
            }

            $otp->forceFill([
                'used_at' => now(),
                'reset_token_hash' => null,
            ])->save();

            Log::info('Staff password reset completed.', ['user_id' => $user->user_id]);

            return (string) $user->username;
        });
    }

    /**
     * Codes expire after OTP_LIFETIME_MINUTES.
     */
    private function isExpired(UserPasswordResetOtp $otp): bool
    {
        return $otp->expires_at === null || $otp->expires_at->isPast();
    }

    private function findActiveUser(string $identifier): User
    {
        $normalizedIdentifier = trim($identifier);

        $users = User::query()
            ->where('email', $normalizedIdentifier)
            ->where('status', 'Active')
            ->get();

        if ($users->count() !== 1) {
            throw new RuntimeException('The password reset request is invalid or expired.');
        }

        return $users->first();
    }
}
