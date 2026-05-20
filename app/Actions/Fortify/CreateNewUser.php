<?php

namespace App\Actions\Fortify;

use App\Models\Category;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppActivationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        /** @var PhoneNumberService $phoneNumberService */
        $phoneNumberService = app(PhoneNumberService::class);
        $rawPhoneNumber = (string) ($input['phone_number'] ?? '');
        $phoneNumber = $phoneNumberService->formatForStorage($rawPhoneNumber);
        $activationService = app(WhatsAppActivationService::class);

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'confirmed',
                'max:255',
                Rule::unique(User::class),
            ],
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($phoneNumberService, $phoneNumber) {
                    if (! $phoneNumberService->isValid((string) $value)) {
                        $fail('Informe um número válido com DDD.');
                        return;
                    }

                    if (User::query()->where('phone_number', $phoneNumber)->exists()) {
                        $fail('Esse número já está sendo usado por outra conta.');
                    }
                },
            ],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
            'category_setup' => ['nullable', Rule::in(['recommended', 'custom'])],
            'activation_code' => ['required', 'string'],
        ]);

        $validator->after(function ($validator) use ($activationService, $input, $phoneNumber) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $activationService->assertVerifiedForRegistration(
                    session()->getId(),
                    (string) $input['activation_code'],
                    $phoneNumber
                );
            } catch (\RuntimeException $exception) {
                $validator->errors()->add('activation_code', $exception->getMessage());
            }
        });

        $validator->validate();

        $activation = $activationService->assertVerifiedForRegistration(
            session()->getId(),
            (string) $input['activation_code'],
            $phoneNumber
        );

        return DB::transaction(function () use ($input, $phoneNumber, $activation, $activationService) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'phone_number' => $phoneNumber,
                'whatsapp_verified_at' => $activation->verified_at,
                'password' => $input['password'],
                'onboarding_tutorial_seen_at' => now(),
            ]);

            if (($input['category_setup'] ?? 'recommended') === 'recommended') {
                $this->createRecommendedCategories($user);
            }

            $activationService->consume($activation, $user, $phoneNumber);

            return $user;
        });
    }

    private function createRecommendedCategories(User $user): void
    {
        $expenseCategories = [
            ['name' => 'Alimentação', 'color' => '#FF6B6B', 'icon' => '🍔'],
            ['name' => 'Transporte', 'color' => '#4ECDC4', 'icon' => '🚗'],
            ['name' => 'Moradia', 'color' => '#45B7D1', 'icon' => '🏠'],
            ['name' => 'Saúde', 'color' => '#96CEB4', 'icon' => '💊'],
            ['name' => 'Educação', 'color' => '#FFEAA7', 'icon' => '📚'],
            ['name' => 'Lazer', 'color' => '#DDA0DD', 'icon' => '🎬'],
            ['name' => 'Compras', 'color' => '#F39C12', 'icon' => '🛒'],
            ['name' => 'Assinaturas', 'color' => '#E74C3C', 'icon' => '📱'],
            ['name' => 'Outros', 'color' => '#95A5A6', 'icon' => '📦'],
        ];

        $incomeCategories = [
            ['name' => 'Salário', 'color' => '#2ECC71', 'icon' => '💰'],
            ['name' => 'Freelance', 'color' => '#3498DB', 'icon' => '💼'],
            ['name' => 'Investimentos', 'color' => '#9B59B6', 'icon' => '📈'],
            ['name' => 'Vendas', 'color' => '#E67E22', 'icon' => '🛍️'],
            ['name' => 'Outros', 'color' => '#95A5A6', 'icon' => '💵'],
        ];

        foreach ($expenseCategories as $category) {
            Category::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $category['name'],
                    'type' => 'expense',
                ],
                [
                    'color' => $category['color'],
                    'icon' => $category['icon'],
                ],
            );
        }

        foreach ($incomeCategories as $category) {
            Category::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $category['name'],
                    'type' => 'income',
                ],
                [
                    'color' => $category['color'],
                    'icon' => $category['icon'],
                ],
            );
        }
    }
}
