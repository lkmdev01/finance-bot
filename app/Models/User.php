<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Carbon\CarbonInterface;
use App\Support\BrazilTaxId;

class User extends Authenticatable
{
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($user) {
            if ($user->phone_number) {
                $service = app(\App\Services\PhoneNumberService::class);
                $user->phone_number = $service->formatForStorage($user->phone_number);
            }

            if ($user->tax_id) {
                $user->tax_id = BrazilTaxId::normalize($user->tax_id);
            }
        });
    }
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'tax_id',
        'auth_provider',
        'google_id',
        'google_avatar',
        'abacatepay_customer_id',
        'billing_plan_code',
        'billing_plan_status',
        'billing_access_ends_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'billing_access_ends_at' => 'datetime',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function whatsappContacts(): HasMany
    {
        return $this->hasMany(WhatsAppContact::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function transactionDuplicates(): HasMany
    {
        return $this->hasMany(TransactionDuplicate::class);
    }

    public function dashboardWidgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }

    public function financialProjections(): HasMany
    {
        return $this->hasMany(FinancialProjection::class);
    }

    public function expensePlans(): HasMany
    {
        return $this->hasMany(ExpensePlan::class);
    }

    public function savingsGoalAlerts(): HasMany
    {
        return $this->hasMany(SavingsGoalAlert::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function mascotProfile(): HasOne
    {
        return $this->hasOne(MascotProfile::class);
    }

    public function mascotAchievementUnlocks(): HasMany
    {
        return $this->hasMany(MascotAchievementUnlock::class);
    }

    public function abacatePayCharges(): HasMany
    {
        return $this->hasMany(AbacatePayCharge::class);
    }

    public function abacatePaySubscriptions(): HasMany
    {
        return $this->hasMany(AbacatePaySubscription::class);
    }

    public function hasActivePaidPlan(): bool
    {
        if (blank($this->billing_plan_code) || $this->billing_plan_code === config('billing.default_plan', 'starter')) {
            return false;
        }

        if (! in_array($this->billing_plan_status, ['active', 'renewed', 'cancelled'], true)) {
            return false;
        }

        if (! $this->billing_access_ends_at instanceof CarbonInterface) {
            return false;
        }

        return $this->billing_access_ends_at->isFuture();
    }

    public function hasFeature(string $feature): bool
    {
        return app(\App\Services\BillingPlanService::class)->userHasFeature($this, $feature);
    }

    public function getBillingPlanStatusLabelAttribute(): string
    {
        return match ($this->billing_plan_status) {
            'active' => 'Ativo',
            'renewed' => 'Renovado',
            'cancelled' => 'Cancelado',
            'pending' => 'Pendente',
            default => 'Starter',
        };
    }
}
