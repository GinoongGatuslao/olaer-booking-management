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
    public function requestOtp(string $identifier): void
    {
        $normalizedIdentifier = trim($identifier);

        $user = User::query()
            ->where(function ($query) use ($normalizedIdentifier): void {
                $query->where('email', $normalizedIdentifier)
                    ->orWhere('username', $normalizedIdentifier);
            })
            ->where('status', 'Active')
            ->first();

        if (! $user) {
            Log::notice('Password reset requested for an unknown or inactive account.', [
                'identifier' => $normalizedIdentifier,
            ]);

            return;
        }

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
            Mail::raw(
                "Your Olaer Spring Resort password reset code is {$plainCode}. "
                .'It expires in '.self::OTP_LIFETIME_MINUTES.' minutes. '
                .'Do not share this code with anyone.',
                function ($message) use ($user): void {
                    $message->to($user->email, $user->full_name)
                        ->subject('Olaer Spring Resort Password Reset Code');
                }
            );
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

        if (! $otp || $otp->expires_at->isPast()) {
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

    public function resetPassword(string $identifier, string $rawToken, string $newPassword): void
    {
        DB::transaction(function () use ($identifier, $rawToken, $newPassword): void {
            $user = $this->findActiveUser($identifier);

            $otp = UserPasswordResetOtp::query()
                ->where('user_id', $user->user_id)
                ->whereNull('used_at')
                ->whereNotNull('verified_at')
                ->latest('password_reset_otp_id')
                ->lockForUpdate()
                ->first();

            $validToken = $otp
                && ! $otp->expires_at->isPast()
                && is_string($otp->reset_token_hash)
                && hash_equals($otp->reset_token_hash, hash('sha256', $rawToken));

            if (! $validToken) {
                throw new RuntimeException('This password reset session is invalid or expired. Request a new code.');
            }

            $user->forceFill([
                'password' => $newPassword,
                'remember_token' => Str::random(60),
            ])->save();

            $otp->forceFill([
                'used_at' => now(),
                'reset_token_hash' => null,
            ])->save();
        });
    }

    private function findActiveUser(string $identifier): User
    {
        $normalizedIdentifier = trim($identifier);

        $user = User::query()
            ->where(function ($query) use ($normalizedIdentifier): void {
                $query->where('email', $normalizedIdentifier)
                    ->orWhere('username', $normalizedIdentifier);
            })
            ->where('status', 'Active')
            ->first();

        if (! $user) {
            throw new RuntimeException('The password reset request is invalid or expired.');
        }

        return $user;
    }
}
