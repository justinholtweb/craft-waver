<?php

namespace justinholtweb\waver\services;

use Craft;
use craft\base\Component;
use justinholtweb\waver\models\Entry;
use justinholtweb\waver\models\WaveAccount;
use justinholtweb\waver\Plugin;

/**
 * The Wave operations Waver actually performs, with the GraphQL kept in one place.
 *
 * Two shapes recur and are easy to get wrong:
 *
 * - Anything that varies per business hangs off `business(id:)`, not off the root.
 * - `sort` is **non-null** on `customers`, `products` and `invoices` (`[CustomerSort!]!`).
 *   Leaving it out is a validation error, not a default.
 */
class Wave extends Component
{
    /**
     * Wave's page ceiling for the lists Waver walks. Chart of accounts and tax lists are small;
     * customers and products are not, which is why neither is ever listed in full.
     */
    public const PAGE_SIZE = 200;

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getBusinesses(): array
    {
        $result = Plugin::getInstance()->getApi()->query('businesses', <<<'GQL'
            query($page: Int, $pageSize: Int) {
                businesses(page: $page, pageSize: $pageSize) {
                    edges { node { id name isArchived } }
                }
            }
            GQL, ['page' => 1, 'pageSize' => 50]);

        $businesses = [];

        foreach ($result['data']['businesses']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];

            if (($node['isArchived'] ?? false) === true) {
                continue;
            }

            $businesses[] = ['id' => (string)($node['id'] ?? ''), 'name' => (string)($node['name'] ?? '')];
        }

        return $businesses;
    }

    /**
     * The chart of accounts, cached: the settings screen renders eight menus off it and Wave
     * should not be asked eight times for the same list.
     *
     * @return WaveAccount[]
     */
    public function getAccounts(string $businessId, bool $flush = false): array
    {
        if ($businessId === '') {
            return [];
        }

        $key = 'waver.accounts.' . md5($businessId);
        $cache = Craft::$app->getCache();

        if ($flush) {
            $cache->delete($key);
        } else {
            $cached = $cache->get($key);

            if (is_array($cached)) {
                return array_map(static fn(array $row) => new WaveAccount($row), $cached);
            }
        }

        $accounts = [];
        $page = 1;

        do {
            $result = Plugin::getInstance()->getApi()->query('accounts', <<<'GQL'
                query($businessId: ID!, $page: Int, $pageSize: Int) {
                    business(id: $businessId) {
                        accounts(page: $page, pageSize: $pageSize, isArchived: false) {
                            pageInfo { currentPage totalPages }
                            edges {
                                node {
                                    id name displayId normalBalanceType isArchived
                                    currency { code }
                                    type { name value }
                                    subtype { name value }
                                }
                            }
                        }
                    }
                }
                GQL, ['businessId' => $businessId, 'page' => $page, 'pageSize' => self::PAGE_SIZE]);

            if (!$result['ok']) {
                return $accounts;
            }

            $connection = $result['data']['business']['accounts'] ?? [];

            foreach ($connection['edges'] ?? [] as $edge) {
                $accounts[] = WaveAccount::fromNode($edge['node'] ?? []);
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $page++;
        } while (($pageInfo['currentPage'] ?? 1) < ($pageInfo['totalPages'] ?? 1));

        $cache->set($key, array_map(static fn(WaveAccount $a) => $a->toArray(), $accounts), 3600);

        return $accounts;
    }

    /**
     * Account names by id, from the cache only. The preview uses this, and a preview must never
     * wait on Wave or fail because Wave is down — an id it cannot name is shown as the id.
     *
     * @return array<string, string>
     */
    public function getCachedAccountNames(string $businessId): array
    {
        $cached = $businessId !== '' ? Craft::$app->getCache()->get('waver.accounts.' . md5($businessId)) : false;

        if (!is_array($cached)) {
            return [];
        }

        $names = [];

        foreach ($cached as $row) {
            if (isset($row['id'], $row['name'])) {
                $names[(string)$row['id']] = (string)$row['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<int, array{id: string, name: string, abbreviation: string, rate: string}>
     */
    public function getSalesTaxes(string $businessId, bool $flush = false): array
    {
        if ($businessId === '') {
            return [];
        }

        $key = 'waver.taxes.' . md5($businessId);
        $cache = Craft::$app->getCache();

        if ($flush) {
            $cache->delete($key);
        } else {
            $cached = $cache->get($key);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = Plugin::getInstance()->getApi()->query('salesTaxes', <<<'GQL'
            query($businessId: ID!, $pageSize: Int) {
                business(id: $businessId) {
                    salesTaxes(pageSize: $pageSize, isArchived: false) {
                        edges { node { id name abbreviation rate } }
                    }
                }
            }
            GQL, ['businessId' => $businessId, 'pageSize' => self::PAGE_SIZE]);

        $taxes = [];

        foreach ($result['data']['business']['salesTaxes']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];
            $taxes[] = [
                'id' => (string)($node['id'] ?? ''),
                'name' => (string)($node['name'] ?? ''),
                'abbreviation' => (string)($node['abbreviation'] ?? ''),
                'rate' => (string)($node['rate'] ?? ''),
            ];
        }

        if ($result['ok']) {
            $cache->set($key, $taxes, 3600);
        }

        return $taxes;
    }

    /**
     * Whether the business can be posted to at all, and what it says about itself.
     *
     * `moneyTransactionCreate` is documented as requiring `isClassicAccounting: false`. A merchant
     * on classic accounting gets a clear answer here rather than a rejected mutation later.
     *
     * @return array{ok: bool, name: string, currency: string, isClassicAccounting: bool, emailSendEnabled: bool, message: string}
     */
    public function describeBusiness(string $businessId): array
    {
        $result = Plugin::getInstance()->getApi()->query('business', <<<'GQL'
            query($businessId: ID!) {
                business(id: $businessId) {
                    id name isArchived isClassicAccounting emailSendEnabled
                    currency { code }
                }
            }
            GQL, ['businessId' => $businessId]);

        $node = $result['data']['business'] ?? null;

        if (!$result['ok'] || !is_array($node)) {
            return [
                'ok' => false,
                'name' => '',
                'currency' => '',
                'isClassicAccounting' => false,
                'emailSendEnabled' => false,
                'message' => $result['message'] ?: Craft::t('waver', 'Wave does not recognise that business id.'),
            ];
        }

        return [
            'ok' => true,
            'name' => (string)($node['name'] ?? ''),
            'currency' => (string)($node['currency']['code'] ?? ''),
            'isClassicAccounting' => (bool)($node['isClassicAccounting'] ?? false),
            'emailSendEnabled' => (bool)($node['emailSendEnabled'] ?? false),
            'message' => '',
        ];
    }

    // Customers
    // =========================================================================

    /**
     * Wave can only look a customer up by email, so an order without one cannot be matched.
     *
     * @return array{id: string, name: string}|null
     */
    public function findCustomerByEmail(string $businessId, string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        $result = Plugin::getInstance()->getApi()->query('customerLookup', <<<'GQL'
            query($businessId: ID!, $email: String) {
                business(id: $businessId) {
                    customers(email: $email, page: 1, pageSize: 5, sort: [CREATED_AT_DESC]) {
                        edges { node { id name email isArchived } }
                    }
                }
            }
            GQL, ['businessId' => $businessId, 'email' => $email]);

        foreach ($result['data']['business']['customers']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];

            if (($node['isArchived'] ?? false) === true) {
                continue;
            }

            // Wave's `email` argument is a match, not necessarily an exact one.
            if (strcasecmp((string)($node['email'] ?? ''), $email) !== 0) {
                continue;
            }

            return ['id' => (string)($node['id'] ?? ''), 'name' => (string)($node['name'] ?? '')];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id: string, message: string}
     */
    public function createCustomer(array $input, ?int $orderId = null): array
    {
        $result = Plugin::getInstance()->getApi()->mutate('customerCreate', <<<'GQL'
            mutation($input: CustomerCreateInput!) {
                customerCreate(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                    customer { id name email }
                }
            }
            GQL, 'customerCreate', $input, $orderId);

        return [
            'ok' => $result['ok'],
            'id' => (string)($result['data']['customer']['id'] ?? ''),
            'message' => $result['message'],
        ];
    }

    // Products
    // =========================================================================

    /**
     * @return array{id: string, name: string}|null
     */
    public function findProductByName(string $businessId, string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        $page = 1;

        do {
            $result = Plugin::getInstance()->getApi()->query('productLookup', <<<'GQL'
                query($businessId: ID!, $page: Int, $pageSize: Int) {
                    business(id: $businessId) {
                        products(page: $page, pageSize: $pageSize, isSold: true, isArchived: false, sort: [NAME_ASC]) {
                            pageInfo { currentPage totalPages }
                            edges { node { id name } }
                        }
                    }
                }
                GQL, ['businessId' => $businessId, 'page' => $page, 'pageSize' => self::PAGE_SIZE]);

            if (!$result['ok']) {
                return null;
            }

            $connection = $result['data']['business']['products'] ?? [];

            foreach ($connection['edges'] ?? [] as $edge) {
                $node = $edge['node'] ?? [];

                if (strcasecmp((string)($node['name'] ?? ''), $name) === 0) {
                    return ['id' => (string)($node['id'] ?? ''), 'name' => (string)($node['name'] ?? '')];
                }
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $page++;
        } while (($pageInfo['currentPage'] ?? 1) < ($pageInfo['totalPages'] ?? 1));

        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id: string, message: string}
     */
    public function createProduct(array $input, ?int $orderId = null): array
    {
        $result = Plugin::getInstance()->getApi()->mutate('productCreate', <<<'GQL'
            mutation($input: ProductCreateInput!) {
                productCreate(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                    product { id name }
                }
            }
            GQL, 'productCreate', $input, $orderId);

        return [
            'ok' => $result['ok'],
            'id' => (string)($result['data']['product']['id'] ?? ''),
            'message' => $result['message'],
        ];
    }

    // Money transactions
    // =========================================================================

    /**
     * @return array{ok: bool, id: string, message: string, ambiguous: bool}
     */
    public function createMoneyTransaction(Entry $entry, ?int $orderId = null): array
    {
        $result = Plugin::getInstance()->getApi()->mutate('moneyTransactionCreate', <<<'GQL'
            mutation($input: MoneyTransactionCreateInput!) {
                moneyTransactionCreate(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                    transaction { id }
                }
            }
            GQL, 'moneyTransactionCreate', $entry->toWaveInput(), $orderId);

        return [
            'ok' => $result['ok'],
            'id' => (string)($result['data']['transaction']['id'] ?? ''),
            'message' => $result['message'],
            'ambiguous' => $result['ambiguous'],
        ];
    }

    // Invoices
    // =========================================================================

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id: string, number: string, viewUrl: string, pdfUrl: string, message: string, ambiguous: bool}
     */
    public function createInvoice(array $input, ?int $orderId = null): array
    {
        $result = Plugin::getInstance()->getApi()->mutate('invoiceCreate', <<<'GQL'
            mutation($input: InvoiceCreateInput!) {
                invoiceCreate(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                    invoice {
                        id status invoiceNumber viewUrl pdfUrl
                        total { value }
                        currency { code }
                    }
                }
            }
            GQL, 'invoiceCreate', $input, $orderId);

        $invoice = $result['data']['invoice'] ?? [];

        return [
            'ok' => $result['ok'],
            'id' => (string)($invoice['id'] ?? ''),
            'number' => (string)($invoice['invoiceNumber'] ?? ''),
            'viewUrl' => (string)($invoice['viewUrl'] ?? ''),
            'pdfUrl' => (string)($invoice['pdfUrl'] ?? ''),
            'message' => $result['message'],
            'ambiguous' => $result['ambiguous'],
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function approveInvoice(string $invoiceId, ?int $orderId = null): array
    {
        $result = Plugin::getInstance()->getApi()->mutate('invoiceApprove', <<<'GQL'
            mutation($input: InvoiceApproveInput!) {
                invoiceApprove(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                    invoice { id status }
                }
            }
            GQL, 'invoiceApprove', ['invoiceId' => $invoiceId], $orderId);

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string}
     */
    public function recordInvoicePayment(array $input, ?int $orderId = null): array
    {
        $result = Plugin::getInstance()->getApi()->mutate('invoicePaymentCreateManual', <<<'GQL'
            mutation($input: InvoicePaymentCreateManualInput!) {
                invoicePaymentCreateManual(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                }
            }
            GQL, 'invoicePaymentCreateManual', $input, $orderId);

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }

    /**
     * @param string[] $to
     * @return array{ok: bool, message: string}
     */
    public function sendInvoice(string $invoiceId, array $to, ?string $subject = null, ?string $message = null, ?int $orderId = null): array
    {
        $input = [
            'invoiceId' => $invoiceId,
            'to' => array_values($to),
            // Both non-null in the schema; omitting either is a validation error, not a default.
            'attachPDF' => true,
        ];

        if ($subject !== null && $subject !== '') {
            $input['subject'] = $subject;
        }

        if ($message !== null && $message !== '') {
            $input['message'] = $message;
        }

        $result = Plugin::getInstance()->getApi()->mutate('invoiceSend', <<<'GQL'
            mutation($input: InvoiceSendInput!) {
                invoiceSend(input: $input) {
                    didSucceed
                    inputErrors { path message code }
                }
            }
            GQL, 'invoiceSend', $input, $orderId);

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }
}
