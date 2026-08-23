<?php

namespace justinholtweb\waver\models;

use craft\base\Model;

/**
 * One entry from a Wave business's chart of accounts, flattened for the settings screen.
 */
class WaveAccount extends Model
{
    /**
     * Subtypes Wave will accept as a transaction's anchor. Wave documents these as "Asset
     * accounts in the Cash and Bank sub-type, and Liability accounts in the Credit Card and Loans
     * and Line of Credit sub-types", and warns that others exist but are best discovered by hand.
     * Waver uses the list to sort the payment-account menu, never to forbid a choice.
     */
    public const ANCHOR_SUBTYPES = ['CASH_AND_BANK', 'CREDIT_CARD', 'LOANS', 'MONEY_IN_TRANSIT'];

    public string $id = '';
    public string $name = '';
    public ?string $displayId = null;
    public string $type = '';
    public string $typeName = '';
    public string $subtype = '';
    public string $subtypeName = '';
    public string $normalBalanceType = '';
    public bool $isArchived = false;
    public ?string $currency = null;

    public static function fromNode(array $node): self
    {
        return new self([
            'id' => (string)($node['id'] ?? ''),
            'name' => (string)($node['name'] ?? ''),
            'displayId' => $node['displayId'] ?? null,
            'type' => (string)($node['type']['value'] ?? ''),
            'typeName' => (string)($node['type']['name'] ?? ''),
            'subtype' => (string)($node['subtype']['value'] ?? ''),
            'subtypeName' => (string)($node['subtype']['name'] ?? ''),
            'normalBalanceType' => (string)($node['normalBalanceType'] ?? ''),
            'isArchived' => (bool)($node['isArchived'] ?? false),
            'currency' => $node['currency']['code'] ?? null,
        ]);
    }

    public function isAnchorCandidate(): bool
    {
        return in_array($this->subtype, self::ANCHOR_SUBTYPES, true);
    }

    /**
     * The label for a select menu: the account name, disambiguated by its subtype, because a
     * merchant can easily have two accounts called "Sales".
     */
    public function getLabel(): string
    {
        $label = $this->name;

        if ($this->subtypeName !== '' && $this->subtypeName !== $this->name) {
            $label .= ' (' . $this->subtypeName . ')';
        }

        if ($this->isArchived) {
            $label .= ' — archived';
        }

        return $label;
    }
}
