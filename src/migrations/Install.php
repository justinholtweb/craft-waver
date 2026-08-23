<?php

namespace justinholtweb\waver\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\waver\db\Table;

/**
 * Waver install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::PRODUCTS);
        $this->dropTableIfExists(Table::CUSTOMERS);
        $this->dropTableIfExists(Table::RECORDS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::RECORDS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            // What this row represents in Wave: a money transaction, an invoice, or a refund.
            'kind' => $this->string(16)->notNull(),
            // The identity of the thing being recorded, derived from the order — never random.
            // The unique index on it *is* the idempotency guarantee, and for money transactions
            // it is also the `externalId` Wave stores, so a duplicate is visible from both ends.
            'externalId' => $this->string(120)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            // Wave's own id for whatever was created (transaction id or invoice id).
            'waveId' => $this->string(255),
            'businessId' => $this->string(255),
            'amount' => $this->decimal(14, 4),
            'currency' => $this->string(8),
            // The Commerce transaction a refund row was raised from.
            'sourceTransactionId' => $this->integer(),
            'invoiceNumber' => $this->string(64),
            'viewUrl' => $this->text(),
            'pdfUrl' => $this->text(),
            // The exact payload that was (or would be) sent, so a record can explain itself.
            'payload' => $this->mediumText(),
            'message' => $this->text(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CUSTOMERS, [
            'id' => $this->primaryKey(),
            'businessId' => $this->string(255)->notNull(),
            'waveCustomerId' => $this->string(255)->notNull(),
            // Wave has no customer lookup by anything but email, so email is the join key.
            'email' => $this->string(255)->notNull(),
            'name' => $this->string(255),
            'customerId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::PRODUCTS, [
            'id' => $this->primaryKey(),
            'businessId' => $this->string(255)->notNull(),
            'waveProductId' => $this->string(255)->notNull(),
            // SKU rather than element id: a merchant who rebuilds a product keeps its SKU, and
            // Wave's catalogue should not gain a duplicate every time they do.
            'sku' => $this->string(255)->notNull(),
            'name' => $this->string(255),
            'purchasableId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(32)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'orderId' => $this->integer(),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // The idempotency guarantee: a retried queue job cannot post a second transaction.
        $this->createIndex(null, Table::RECORDS, ['externalId'], true);
        $this->createIndex(null, Table::RECORDS, ['orderId'], false);
        $this->createIndex(null, Table::RECORDS, ['status'], false);
        $this->createIndex(null, Table::RECORDS, ['kind'], false);

        $this->createIndex(null, Table::CUSTOMERS, ['businessId', 'email'], true);
        $this->createIndex(null, Table::PRODUCTS, ['businessId', 'sku'], true);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::RECORDS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CUSTOMERS, ['customerId'], CraftTable::USERS, ['id'], 'SET NULL', null);
    }
}
