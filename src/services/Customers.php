<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\waver\db\Table;
use justinholtweb\waver\Plugin;

/**
 * Commerce customers, as Wave sees them.
 *
 * Wave can only be asked for a customer **by email** — there is no lookup by name, by external id
 * or by anything else. An order with no email therefore cannot be matched to a Wave customer at
 * all, and Waver records it without one rather than inventing a duplicate on every order.
 *
 * The local map exists because Wave's own lookup is a *match*, not an equality test, and because
 * asking Wave on every order would spend a request on a question already answered.
 */
class Customers extends Component
{
    /**
     * The Wave customer for an order, creating one if the settings allow it.
     *
     * @return array{id: string|null, message: string|null}
     */
    public function resolveForOrder(Order $order, string $businessId): array
    {
        $email = trim((string)$order->getEmail());

        if ($businessId === '' || $email === '') {
            return ['id' => null, 'message' => null];
        }

        $cached = $this->getCachedCustomerId($businessId, $email);

        if ($cached !== null) {
            return ['id' => $cached, 'message' => null];
        }

        $found = Plugin::getInstance()->getWave()->findCustomerByEmail($businessId, $email);

        if ($found !== null) {
            $this->remember($businessId, $found['id'], $email, $found['name'], $order->customerId ?? null);

            return ['id' => $found['id'], 'message' => null];
        }

        if (!Plugin::getInstance()->getSettings()->createCustomers) {
            return ['id' => null, 'message' => Craft::t('waver', 'No Wave customer matches {email}, and creating customers is switched off.', ['email' => $email])];
        }

        $result = Plugin::getInstance()->getWave()->createCustomer($this->buildInput($order, $businessId, $email), $order->id);

        if (!$result['ok'] || $result['id'] === '') {
            return ['id' => null, 'message' => $result['message']];
        }

        $this->remember($businessId, $result['id'], $email, $this->nameFor($order, $email), $order->customerId ?? null);

        return ['id' => $result['id'], 'message' => null];
    }

    /**
     * The Wave customer id already known for this email, without touching the network.
     */
    public function getCachedCustomerId(string $businessId, string $email): ?string
    {
        $email = trim($email);

        if ($businessId === '' || $email === '') {
            return null;
        }

        $id = (new Query())
            ->select(['waveCustomerId'])
            ->from([Table::CUSTOMERS])
            ->where(['businessId' => $businessId, 'email' => $email])
            ->scalar();

        return $id !== false && $id !== null ? (string)$id : null;
    }

    public function forget(string $businessId, string $email): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::CUSTOMERS, ['businessId' => $businessId, 'email' => $email])
            ->execute();
    }

    public function clearMap(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::CUSTOMERS)->execute();
    }

    public function countMapped(): int
    {
        return (int)(new Query())->from([Table::CUSTOMERS])->count();
    }

    // Private
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function buildInput(Order $order, string $businessId, string $email): array
    {
        $input = [
            'businessId' => $businessId,
            // `name` is non-null in Wave's schema; an anonymous order still has to be called
            // something, and its email is the only thing guaranteed to be there.
            'name' => $this->nameFor($order, $email),
            'email' => $email,
        ];

        $address = $order->getBillingAddress();

        if ($address !== null) {
            $input['firstName'] = $address->firstName ?: null;
            $input['lastName'] = $address->lastName ?: null;

            $addressInput = array_filter([
                'addressLine1' => $address->addressLine1 ?: null,
                'addressLine2' => $address->addressLine2 ?: null,
                'city' => $address->locality ?: null,
                'postalCode' => $address->postalCode ?: null,
                'countryCode' => $address->countryCode ?: null,
            ], static fn($value) => $value !== null);

            if ($addressInput !== []) {
                // Wave wants ISO 3166-2 (`US-NC`), while Craft stores the subdivision on its
                // own (`NC`). Sending Craft's value straight through is rejected, and a province
                // is only meaningful alongside its country in the first place.
                $province = trim((string)($address->administrativeArea ?? ''));

                if ($province !== '' && isset($addressInput['countryCode'])) {
                    $addressInput['provinceCode'] = str_contains($province, '-')
                        ? $province
                        : $addressInput['countryCode'] . '-' . $province;
                }

                $input['address'] = $addressInput;
            }
        }

        if (($order->currency ?? '') !== '') {
            $input['currency'] = $order->currency;
        }

        return array_filter($input, static fn($value) => $value !== null && $value !== '');
    }

    private function nameFor(Order $order, string $email): string
    {
        $address = $order->getBillingAddress();

        if ($address !== null) {
            $name = trim((string)($address->fullName ?: trim(($address->firstName ?? '') . ' ' . ($address->lastName ?? ''))));

            if ($name !== '') {
                return mb_substr($name, 0, 255);
            }

            if (($address->organization ?? '') !== '') {
                return mb_substr((string)$address->organization, 0, 255);
            }
        }

        return mb_substr($email, 0, 255);
    }

    private function remember(string $businessId, string $waveCustomerId, string $email, ?string $name, ?int $customerId): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        try {
            Craft::$app->getDb()->createCommand()->upsert(Table::CUSTOMERS, [
                'businessId' => $businessId,
                'email' => $email,
                'waveCustomerId' => $waveCustomerId,
                'name' => $name !== null ? mb_substr($name, 0, 255) : null,
                'customerId' => $customerId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                // Only the columns that can legitimately change on a re-match. `Db::upsert()`
                // updates nothing but what is named here, so the insert half has to carry
                // everything the row needs.
                'waveCustomerId' => $waveCustomerId,
                'name' => $name !== null ? mb_substr($name, 0, 255) : null,
                'customerId' => $customerId,
                'dateUpdated' => $now,
            ])->execute();
        } catch (\Throwable $e) {
            Craft::warning('Waver could not cache a Wave customer id: ' . $e->getMessage(), __METHOD__);
        }
    }
}
