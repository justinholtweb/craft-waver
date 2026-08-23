<?php

namespace justinholtweb\waver\models;

use craft\base\Model;
use justinholtweb\waver\helpers\Money;

/**
 * One line of a Wave money transaction, plus the accounting decision behind it.
 *
 * Wave's line items carry a `balance` of `INCREASE` or `DECREASE` relative to the account's normal
 * balance — and, as Wave documents, that inverts for contra accounts, so `INCREASE` on a
 * `DISCOUNTS` account is a debit. `balance` alone therefore cannot tell Waver whether a line adds
 * to or subtracts from what the anchor received. The `role` does, and `sign()` is the whole of
 * that knowledge in one place.
 */
class EntryLine extends Model
{
    public const BALANCE_INCREASE = 'INCREASE';
    public const BALANCE_DECREASE = 'DECREASE';

    // Roles. Each one is a decision about where a piece of a Commerce order belongs in a ledger.
    public const ROLE_SALES = 'sales';
    public const ROLE_SHIPPING = 'shipping';
    public const ROLE_TAX = 'tax';
    public const ROLE_DISCOUNT = 'discount';
    public const ROLE_FEE = 'fee';
    public const ROLE_OTHER_INCOME = 'otherIncome';
    public const ROLE_ROUNDING = 'rounding';
    public const ROLE_REFUND = 'refund';

    /**
     * How each role moves the books, as `[balance, sign]`.
     *
     * `sign` is +1 for a credit and -1 for a debit, expressed from the anchor's point of view: the
     * signed line total must come to exactly what the anchor took in (or, for a withdrawal, paid
     * out). See `Entry::drift()`.
     */
    private const ROLES = [
        // Income earned. Credit.
        self::ROLE_SALES => [self::BALANCE_INCREASE, 1],
        self::ROLE_SHIPPING => [self::BALANCE_INCREASE, 1],
        // Sales tax collected on somebody else's behalf. A liability, so a credit.
        self::ROLE_TAX => [self::BALANCE_INCREASE, 1],
        // "More discount" on a contra-income account, which Wave books as a debit.
        self::ROLE_DISCOUNT => [self::BALANCE_INCREASE, -1],
        // An expense the merchant absorbed out of the same payment. Debit.
        self::ROLE_FEE => [self::BALANCE_INCREASE, -1],
        self::ROLE_OTHER_INCOME => [self::BALANCE_INCREASE, 1],
        // Income handed back. Debit against the income account.
        self::ROLE_REFUND => [self::BALANCE_DECREASE, -1],
        // Rounding is the only role whose direction is not fixed by what it is.
        self::ROLE_ROUNDING => [null, null],
    ];

    public string $role = self::ROLE_SALES;

    public string $accountId = '';

    /**
     * Always positive. Direction lives in `balance` and `sign`, never in the number — Wave
     * rejects signed amounts.
     */
    public float $amount = 0.0;

    public ?string $description = null;

    public ?string $customerId = null;

    /**
     * Set only for `rounding`, whose direction depends on which way the residual fell.
     */
    public ?int $forcedSign = null;

    public static function make(string $role, string $accountId, float $amount, ?string $description = null): self
    {
        return new self([
            'role' => $role,
            'accountId' => $accountId,
            'amount' => Money::round(abs($amount)),
            'description' => $description,
        ]);
    }

    /**
     * +1 for a credit, -1 for a debit, from the anchor's point of view.
     */
    public function sign(): int
    {
        if ($this->role === self::ROLE_ROUNDING) {
            return $this->forcedSign ?? 1;
        }

        return self::ROLES[$this->role][1] ?? 1;
    }

    /**
     * The `BalanceType` Wave should be sent.
     */
    public function balance(): string
    {
        if ($this->role === self::ROLE_ROUNDING) {
            // A residual that adds to income is an increase; one that takes away is a decrease.
            return $this->sign() > 0 ? self::BALANCE_INCREASE : self::BALANCE_DECREASE;
        }

        return self::ROLES[$this->role][0] ?? self::BALANCE_INCREASE;
    }

    /**
     * The amount as it contributes to the anchor total.
     */
    public function signedAmount(): float
    {
        return Money::round($this->amount) * $this->sign();
    }

    /**
     * @return array<string, mixed> The `MoneyTransactionCreateLineItemInput` for this line.
     */
    public function toWaveInput(): array
    {
        $input = [
            'accountId' => $this->accountId,
            'amount' => Money::format($this->amount),
            'balance' => $this->balance(),
        ];

        if ($this->description !== null && $this->description !== '') {
            $input['description'] = mb_substr($this->description, 0, 255);
        }

        if ($this->customerId !== null && $this->customerId !== '') {
            $input['customerId'] = $this->customerId;
        }

        // No `taxes` here, deliberately. `MoneyTransactionCreateSalesTaxInput.amount` is
        // *required* (unlike the invoice equivalent, where it is deprecated), which means Wave
        // expects the caller to state the tax — and nothing in the documentation says whether
        // that amount is extracted from the line or added on top of it. Either reading balances
        // differently. Waver books tax as its own line to a sales-tax account instead, which is
        // arithmetic it can prove. Wave sales-tax mapping is used by invoice mode, where Wave
        // computes the tax itself and the ambiguity does not arise.

        return $input;
    }

    /**
     * @return string[]
     */
    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }
}
