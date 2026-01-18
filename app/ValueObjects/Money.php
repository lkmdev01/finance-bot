<?php

namespace App\ValueObjects;

class Money
{
    public function __construct(
        private readonly int $amount, // em centavos
        private readonly string $currency = 'BRL'
    ) {}

    /**
     * Cria Money a partir de float (reais)
     */
    public static function fromFloat(float $amount, string $currency = 'BRL'): self
    {
        return new self((int) round($amount * 100), $currency);
    }

    /**
     * Converte para float (reais)
     */
    public function toFloat(): float
    {
        return $this->amount / 100.0;
    }

    /**
     * Formata para exibição
     */
    public function format(): string
    {
        return match ($this->currency) {
            'BRL' => 'R$ ' . number_format($this->toFloat(), 2, ',', '.'),
            default => number_format($this->toFloat(), 2, '.', ',') . ' ' . $this->currency,
        };
    }

    /**
     * Soma dois valores monetários
     */
    public function add(Money $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot add money with different currencies');
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtrai dois valores monetários
     */
    public function subtract(Money $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot subtract money with different currencies');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiplica por um número
     */
    public function multiply(float $multiplier): self
    {
        return new self((int) round($this->amount * $multiplier), $this->currency);
    }

    /**
     * Compara dois valores monetários
     */
    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Verifica se é maior que outro valor
     */
    public function greaterThan(Money $other): bool
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot compare money with different currencies');
        }

        return $this->amount > $other->amount;
    }

    /**
     * Verifica se é menor que outro valor
     */
    public function lessThan(Money $other): bool
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot compare money with different currencies');
        }

        return $this->amount < $other->amount;
    }

    /**
     * Retorna o valor absoluto
     */
    public function abs(): self
    {
        return new self(abs($this->amount), $this->currency);
    }

    /**
     * Retorna o valor em centavos
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * Retorna a moeda
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }
}
