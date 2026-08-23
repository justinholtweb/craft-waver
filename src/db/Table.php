<?php

namespace justinholtweb\waver\db;

/**
 * Waver's database tables.
 */
abstract class Table
{
    public const RECORDS = '{{%waver_records}}';
    public const CUSTOMERS = '{{%waver_customers}}';
    public const PRODUCTS = '{{%waver_products}}';
    public const LOG = '{{%waver_log}}';
}
