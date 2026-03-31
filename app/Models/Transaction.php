<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'whatsapp_contact_id',
        'category_id',
        'bank_account_id',
        'credit_card_id',
        'subscription_id',
        'type',
        'amount',
        'description',
        'date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function whatsappContact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Busca transação por descrição ou valor (para IA)
     */
    public static function findByDescriptionOrAmount(User $user, string $query): ?self
    {
        $query = strtolower($query);

        return self::where('user_id', $user->id)
            ->where(function ($q) use ($query) {
                $q->whereRaw('LOWER(description) LIKE ?', ["%{$query}%"])
                    ->orWhere('amount', 'LIKE', "%{$query}%");
            })
            ->latest('date')
            ->first();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (Transaction $transaction) {
            // Detectar duplicatas automaticamente
            if ($transaction->user) {
                app(\App\Services\TransactionDuplicateDetectionService::class)
                    ->detectDuplicates($transaction->user, 7);
            }

            // Disparar webhook
            if ($transaction->user) {
                app(\App\Services\WebhookService::class)->dispatch(
                    'transaction.created',
                    $transaction->user,
                    [
                        'transaction_id' => $transaction->id,
                        'type' => $transaction->type,
                        'amount' => $transaction->amount,
                        'description' => $transaction->description,
                        'date' => $transaction->date->toIso8601String(),
                    ]
                );
            }
        });

        static::updated(function (Transaction $transaction) {
            if ($transaction->user) {
                app(\App\Services\WebhookService::class)->dispatch(
                    'transaction.updated',
                    $transaction->user,
                    [
                        'transaction_id' => $transaction->id,
                        'type' => $transaction->type,
                        'amount' => $transaction->amount,
                        'description' => $transaction->description,
                        'date' => $transaction->date->toIso8601String(),
                    ]
                );
            }
        });

        static::deleted(function (Transaction $transaction) {
            if ($transaction->user) {
                app(\App\Services\WebhookService::class)->dispatch(
                    'transaction.deleted',
                    $transaction->user,
                    [
                        'transaction_id' => $transaction->id,
                    ]
                );
            }
        });
    }
}
