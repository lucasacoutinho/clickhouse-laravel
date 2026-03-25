<?php

namespace ClickHouse\Laravel\Tests;

use ClickHouse\Laravel\Query\ClickHouseQueryGrammar;
use ClickHouse\Laravel\Schema\ClickHouseBlueprint;
use ClickHouse\Laravel\Schema\ClickHouseSchemaGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Detect if Grammar constructor requires a Connection (Laravel 13+).
     */
    private function grammarRequiresConnection(): bool
    {
        $ctor = new \ReflectionMethod(\Illuminate\Database\Grammar::class, '__construct');
        return $ctor->getNumberOfRequiredParameters() > 0;
    }

    /**
     * Detect if Blueprint constructor's first arg is Connection (Laravel 13+).
     */
    private function blueprintRequiresConnection(): bool
    {
        $ctor = new \ReflectionMethod(Blueprint::class, '__construct');
        $firstParam = $ctor->getParameters()[0] ?? null;
        if (!$firstParam) return false;
        $type = $firstParam->getType();
        return $type instanceof \ReflectionNamedType && $type->getName() === Connection::class;
    }

    protected function createQueryGrammar(): ClickHouseQueryGrammar
    {
        if ($this->grammarRequiresConnection()) {
            return new ClickHouseQueryGrammar($this->createMock(Connection::class));
        }

        return new ClickHouseQueryGrammar();
    }

    protected function createSchemaGrammar(): ClickHouseSchemaGrammar
    {
        if ($this->grammarRequiresConnection()) {
            return new ClickHouseSchemaGrammar($this->createMock(Connection::class));
        }

        return new ClickHouseSchemaGrammar();
    }

    protected function createBlueprint(string $table = 'events'): ClickHouseBlueprint
    {
        if ($this->blueprintRequiresConnection()) {
            return new ClickHouseBlueprint($this->createMock(Connection::class), $table);
        }

        return new ClickHouseBlueprint($table);
    }
}
