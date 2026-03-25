<?php

namespace ClickHouse\Laravel\Tests;

use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a query grammar, handling Laravel 10-12 vs 13 constructor differences.
     */
    protected function createQueryGrammar(): ClickHouseQueryGrammar
    {
        try {
            return new ClickHouseQueryGrammar($this->createMock(\Illuminate\Database\Connection::class));
        } catch (\Throwable) {
            return new ClickHouseQueryGrammar();
        }
    }

    /**
     * Create a schema grammar, handling Laravel 10-12 vs 13 constructor differences.
     */
    protected function createSchemaGrammar(): ClickHouseSchemaGrammar
    {
        try {
            return new ClickHouseSchemaGrammar($this->createMock(\Illuminate\Database\Connection::class));
        } catch (\Throwable) {
            return new ClickHouseSchemaGrammar();
        }
    }

    /**
     * Create a blueprint, handling Laravel 10-12 vs 13 constructor differences.
     * Laravel 13: Blueprint($connection, $table)
     * Laravel 10-12: Blueprint($table, $callback, $prefix)
     */
    protected function createBlueprint(string $table = 'events'): ClickHouseBlueprint
    {
        try {
            $conn = $this->createMock(\Illuminate\Database\Connection::class);
            return new ClickHouseBlueprint($conn, $table);
        } catch (\Throwable) {
            return new ClickHouseBlueprint($table);
        }
    }
}
