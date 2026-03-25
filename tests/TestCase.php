<?php

namespace ClickHouse\Laravel\Tests;

use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Check if the base Grammar class requires a Connection in its constructor.
     * Laravel 13+: Grammar($connection) — required parameter.
     * Laravel 10-12: Grammar() — no constructor or no required params.
     */
    private static function grammarNeedsConnection(): bool
    {
        try {
            $ctor = new \ReflectionMethod(\Illuminate\Database\Grammar::class, '__construct');
            return $ctor->getNumberOfRequiredParameters() > 0;
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Check if Blueprint's first constructor param is a Connection.
     * Laravel 13+: Blueprint($connection, $table)
     * Laravel 10-12: Blueprint($table, $callback, $prefix)
     */
    private static function blueprintNeedsConnection(): bool
    {
        try {
            $ctor = new \ReflectionMethod(\Illuminate\Database\Schema\Blueprint::class, '__construct');
            $first = $ctor->getParameters()[0] ?? null;
            if (!$first) return false;
            $type = $first->getType();
            return $type instanceof \ReflectionNamedType && $type->getName() === Connection::class;
        } catch (\ReflectionException) {
            return false;
        }
    }

    protected function createQueryGrammar(): ClickHouseQueryGrammar
    {
        if (self::grammarNeedsConnection()) {
            return new ClickHouseQueryGrammar($this->createMock(Connection::class));
        }

        return new ClickHouseQueryGrammar();
    }

    protected function createSchemaGrammar(): ClickHouseSchemaGrammar
    {
        if (self::grammarNeedsConnection()) {
            return new ClickHouseSchemaGrammar($this->createMock(Connection::class));
        }

        return new ClickHouseSchemaGrammar();
    }

    /**
     * Create a Connection mock suitable for Blueprint construction.
     * Stubs getSchemaGrammar() and getSchemaBuilder() so Blueprint's
     * internal calls (e.g. defaultTimePrecision) don't fail.
     */
    protected function createConnectionMock(): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('getSchemaGrammar')->willReturn($this->createSchemaGrammar());

        // Laravel 12+ Blueprint::defaultTimePrecision() calls
        // $this->connection->getSchemaBuilder()::$defaultTimePrecision
        $schemaBuilder = $this->createMock(\Illuminate\Database\Schema\Builder::class);
        $conn->method('getSchemaBuilder')->willReturn($schemaBuilder);

        return $conn;
    }

    protected function createBlueprint(string $table = 'events'): ClickHouseBlueprint
    {
        if (self::blueprintNeedsConnection()) {
            return new ClickHouseBlueprint($this->createConnectionMock(), $table);
        }

        return new ClickHouseBlueprint($table);
    }
}
