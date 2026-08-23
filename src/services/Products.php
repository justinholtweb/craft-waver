<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use craft\commerce\models\LineItem;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\waver\db\Table;
use justinholtweb\waver\helpers\Money;
use justinholtweb\waver\Plugin;

/**
 * Commerce purchasables, as Wave sees them. Invoice mode only.
 *
 * Two facts about Wave shape all of this:
 *
 * 1. **Every invoice line must name a `productId`.** `InvoiceCreateItemInput.productId` is `ID!` —
 *    Wave has no free-text line. An unmapped SKU is therefore a blocker, not a fallback.
 * 2. **Wave products have no SKU field.** Nothing on Wave's side can carry Commerce's identity, so
 *    the local map keyed on SKU is the only durable join. Without it, a merchant who rebuilds a
 *    product gets a second Wave product with the same name, forever.
 *
 * The name lookup is a last resort for merchants who already had a catalogue in Wave before
 * installing Waver, and it is exact-match only — a fuzzy match here would attach a sale to the
 * wrong income account.
 */
class Products extends Component
{
    /**
     * The Wave product for a line item, creating one if the settings allow it.
     *
     * @return array{id: string|null, message: string|null}
     */
    public function resolveForLineItem(LineItem $lineItem, string $businessId, ?int $orderId = null): array
    {
        $sku = trim((string)$lineItem->getSku());
        $name = $this->nameFor($lineItem);

        if ($businessId === '') {
            return ['id' => null, 'message' => Craft::t('waver', 'No Wave business is selected.')];
        }

        if ($sku !== '') {
            $cached = $this->getCachedProductId($businessId, $sku);

            if ($cached !== null) {
                return ['id' => $cached, 'message' => null];
            }
        }

        $found = Plugin::getInstance()->getWave()->findProductByName($businessId, $name);

        if ($found !== null) {
            $this->remember($businessId, $found['id'], $sku !== '' ? $sku : $name, $name, $lineItem->purchasableId ?? null);

            return ['id' => $found['id'], 'message' => null];
        }

        if (!Plugin::getInstance()->getSettings()->createProducts) {
            return ['id' => null, 'message' => Craft::t('waver', 'No Wave product is mapped for “{name}”, and creating products is switched off.', ['name' => $name])];
        }

        $settings = Plugin::getInstance()->getSettings();
        $incomeAccountId = $settings->getProductIncomeAccountId();

        if ($incomeAccountId === '') {
            return ['id' => null, 'message' => Craft::t('waver', 'No income account is set for products Waver creates.')];
        }

        $result = Plugin::getInstance()->getWave()->createProduct([
            'businessId' => $businessId,
            'name' => $name,
            // Non-null in the schema. The invoice line overrides it anyway, but Wave will not
            // create a product without one.
            'unitPrice' => Money::format((float)$lineItem->getSalePrice()),
            'description' => $sku !== '' ? Craft::t('waver', 'SKU {sku}', ['sku' => $sku]) : null,
            'incomeAccountId' => $incomeAccountId,
        ], $orderId);

        if (!$result['ok'] || $result['id'] === '') {
            return ['id' => null, 'message' => $result['message']];
        }

        $this->remember($businessId, $result['id'], $sku !== '' ? $sku : $name, $name, $lineItem->purchasableId ?? null);

        return ['id' => $result['id'], 'message' => null];
    }

    public function getCachedProductId(string $businessId, string $sku): ?string
    {
        if ($businessId === '' || $sku === '') {
            return null;
        }

        $id = (new Query())
            ->select(['waveProductId'])
            ->from([Table::PRODUCTS])
            ->where(['businessId' => $businessId, 'sku' => $sku])
            ->scalar();

        return $id !== false && $id !== null ? (string)$id : null;
    }

    public function clearMap(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::PRODUCTS)->execute();
    }

    public function countMapped(): int
    {
        return (int)(new Query())->from([Table::PRODUCTS])->count();
    }

    /**
     * Wave's product name is what a customer reads on the invoice, so the line item's own
     * description wins over its SKU.
     */
    public function nameFor(LineItem $lineItem): string
    {
        $name = trim((string)$lineItem->getDescription());

        if ($name === '') {
            $name = trim((string)$lineItem->getSku());
        }

        return mb_substr($name !== '' ? $name : Craft::t('waver', 'Item'), 0, 255);
    }

    private function remember(string $businessId, string $waveProductId, string $sku, string $name, ?int $purchasableId): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        try {
            Craft::$app->getDb()->createCommand()->upsert(Table::PRODUCTS, [
                'businessId' => $businessId,
                'sku' => mb_substr($sku, 0, 255),
                'waveProductId' => $waveProductId,
                'name' => mb_substr($name, 0, 255),
                'purchasableId' => $purchasableId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                'waveProductId' => $waveProductId,
                'name' => mb_substr($name, 0, 255),
                'purchasableId' => $purchasableId,
                'dateUpdated' => $now,
            ])->execute();
        } catch (\Throwable $e) {
            Craft::warning('Waver could not cache a Wave product id: ' . $e->getMessage(), __METHOD__);
        }
    }
}
