<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppActivationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserAccountReconciliationService
{
    public function reconcileLegacyPhoneOwner(User $currentUser, string $normalizedPhone): User
    {
        $legacyUser = User::query()
            ->where('phone_number', $normalizedPhone)
            ->where('id', '!=', $currentUser->id)
            ->first();

        if (! $legacyUser) {
            return $currentUser;
        }

        if (! $this->canAdoptLegacyAccount($currentUser, $legacyUser)) {
            throw new \RuntimeException(
                'Esse numero ja esta vinculado a outra conta. Fale com o suporte para recuperar o acesso sem perder seu historico.'
            );
        }

        return DB::transaction(function () use ($currentUser, $legacyUser) {
            $googleEmail = $currentUser->email;
            $googleId = $currentUser->google_id;
            $googleAvatar = $currentUser->google_avatar;
            $googleName = $currentUser->name;
            $googleVerifiedAt = $currentUser->email_verified_at;

            $currentUser->forceFill([
                'email' => $this->mergedPlaceholderEmail($currentUser->id),
                'google_id' => null,
                'google_avatar' => null,
            ])->save();

            $legacyUser->forceFill([
                'name' => $legacyUser->name ?: $googleName,
                'email' => $googleEmail,
                'auth_provider' => 'google',
                'google_id' => $googleId,
                'google_avatar' => $googleAvatar,
                'email_verified_at' => $legacyUser->email_verified_at ?? $googleVerifiedAt ?? now(),
            ])->save();

            WhatsAppActivationCode::query()
                ->where('user_id', $currentUser->id)
                ->update(['user_id' => $legacyUser->id]);

            $currentUser->delete();

            return $legacyUser->fresh();
        });
    }

    public function canAdoptLegacyAccount(User $currentUser, User $legacyUser): bool
    {
        if (filled($legacyUser->google_id)) {
            return false;
        }

        if (! $this->isDisposableUser($currentUser)) {
            return false;
        }

        return true;
    }

    public function isDisposableUser(User $user): bool
    {
        if ($user->whatsapp_verified_at !== null) {
            return false;
        }

        if ($user->transactions()->exists()
            || $user->categories()->exists()
            || $user->bankAccounts()->exists()
            || $user->creditCards()->exists()
            || $user->subscriptions()->exists()
            || $user->openFinanceConnections()->exists()
            || $user->driveFiles()->exists()
            || $user->budgets()->exists()
            || $user->savingsGoals()->exists()
            || $user->recurringTransactions()->exists()
            || $user->reminders()->exists()
            || $user->notes()->exists()) {
            return false;
        }

        return true;
    }

    private function mergedPlaceholderEmail(int $userId): string
    {
        return sprintf('merged+%d+%s@inovafinance.local', $userId, Str::lower(Str::random(8)));
    }
}
