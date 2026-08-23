<?php

namespace justinholtweb\waver\models;

use craft\base\Model;
use justinholtweb\waver\helpers\Money;

/**
 * A complete, balanced Wave money transaction, before it is anything Wave has seen.
 *
 * Wave states the rule plainly: "The total of the line items must balance the deposit/withdrawal
 * to the Anchor account." An unbalanced entry is rejected — but a *balanced* entry that is
 * balanced for the wrong reason is accepted, and quietly wrong books are far more expensive than
 * a failed sync. So the arithmetic is done here, checked here, and an entry that does not balance
 * never reaches the wire.
 */
class Entry extends Model
{
    public const DIRECTION_DEPOSIT = 'DEPOSIT';
    public const DIRECTION_WITHDRAWAL = 'WITHDRAWAL';

    public string $externalId = '';

    public string $businessId = '';

    /**
     * `Y-m-d`. Wave's `Date` scalar has no time component.
     */
    public string $date = '';

    public string $description = '';

    public ?string $notes = null;

    public string $anchorAccountId = '';

    /**
     * Positive. `direction` carries the sign.
     */
    public float $anchorAmount = 0.0;

    public string $direction = self::DIRECTION_DEPOSIT;

    /**
     * @var EntryLine[]
     */
    public array $lines = [];

    /**
     * Problems that stop this entry being sent at all — a missing account mapping, an
     * unreconcilable residual. Collected rather than thrown so the CP can show every one of them
     * at once instead of the first.
     *
     * @var string[]
     */
    public array $problems = [];

    public function addLine(?EntryLine $line): void
    {
        if ($line === null || Money::isZero($line->amount) || $line->accountId === '') {
            return;
        }

        $this->lines[] = $line;
    }

    /**
     * The signed line total, from the anchor's point of view.
     */
    public function lineTotal(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += $line->signedAmount();
        }

        return Money::round($total);
    }

    /**
     * What the anchor contributes to the same sum. A deposit is money in, so the lines must add
     * up to it; a withdrawal is money out, so they must add up to its negative.
     */
    public function anchorSigned(): float
    {
        return Money::round($this->anchorAmount) * ($this->direction === self::DIRECTION_DEPOSIT ? 1 : -1);
    }

    /**
     * How far off balance this entry is. Zero, and only zero, is safe to send.
     */
    public function drift(): float
    {
        return Money::round($this->anchorSigned() - $this->lineTotal());
    }

    public function isBalanced(): bool
    {
        return Money::isZero($this->drift());
    }

    /**
     * Absorb a residual onto a rounding line so the entry balances exactly.
     *
     * Sub-cent drift is ordinary: Commerce keeps money in floats and Wave takes two decimals, so
     * a five-line order can land a cent away from its own total. Anything larger is not rounding,
     * it is a modelling mistake, and the caller is told rather than having it papered over.
     */
    public function absorbResidual(string $accountId, float $tolerance): bool
    {
        $drift = $this->drift();

        if (Money::isZero($drift)) {
            return true;
        }

        if (abs($drift) > $tolerance + 0.0001 || $accountId === '') {
            return false;
        }

        $line = EntryLine::make(EntryLine::ROLE_ROUNDING, $accountId, abs($drift), 'Rounding');
        $line->forcedSign = $drift > 0 ? 1 : -1;
        $this->lines[] = $line;

        return $this->isBalanced();
    }

    public function isSendable(): bool
    {
        return $this->problems === []
            && $this->lines !== []
            && $this->anchorAccountId !== ''
            && $this->businessId !== ''
            && !Money::isZero($this->anchorAmount)
            && $this->isBalanced();
    }

    /**
     * Every reason this entry cannot be sent, in the order a merchant would want to fix them.
     *
     * @return string[]
     */
    public function blockers(): array
    {
        $blockers = $this->problems;

        if ($this->businessId === '') {
            $blockers[] = 'No Wave business is selected.';
        }

        if ($this->anchorAccountId === '') {
            $blockers[] = 'No payment account is mapped, so there is nowhere for the money to land.';
        }

        if ($this->lines === []) {
            $blockers[] = 'The order produced no line items.';
        }

        if (Money::isZero($this->anchorAmount)) {
            $blockers[] = 'The order total is zero.';
        } elseif (!$this->isBalanced() && $this->problems === []) {
            // Only when nothing else explains it. An entry missing half its account mappings is
            // out of balance *because* of that, and saying both makes the real fault harder to see.

            $blockers[] = sprintf(
                'The line items are out by %s. Wave rejects an entry whose lines do not balance the anchor.',
                Money::format($this->drift())
            );
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return array<string, mixed> The `MoneyTransactionCreateInput`.
     */
    public function toWaveInput(): array
    {
        $input = [
            'businessId' => $this->businessId,
            'externalId' => $this->externalId,
            'date' => $this->date,
            'description' => mb_substr($this->description, 0, 255),
            'anchor' => [
                'accountId' => $this->anchorAccountId,
                'amount' => Money::format($this->anchorAmount),
                'direction' => $this->direction,
            ],
            'lineItems' => array_map(static fn(EntryLine $line) => $line->toWaveInput(), $this->lines),
        ];

        if ($this->notes !== null && $this->notes !== '') {
            $input['notes'] = $this->notes;
        }

        return $input;
    }

    /**
     * A human-readable rendering for the CP preview, in the shape an accountant reads.
     *
     * @return array<int, array{role: string, account: string, description: string|null, debit: string|null, credit: string|null}>
     */
    public function toRows(): array
    {
        $rows = [];

        foreach ($this->lines as $line) {
            $rows[] = [
                'role' => $line->role,
                'account' => $line->accountId,
                'description' => $line->description,
                'debit' => $line->sign() < 0 ? Money::format($line->amount) : null,
                'credit' => $line->sign() > 0 ? Money::format($line->amount) : null,
            ];
        }

        return $rows;
    }
}
