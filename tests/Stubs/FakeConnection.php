<?php

namespace ClickHouse\Laravel\Tests\Stubs;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;

/**
 * Minimal ConnectionInterface implementation for unit tests.
 *
 * Avoids mocking issues with final/non-existent methods across Laravel versions.
 */
class FakeConnection implements ConnectionInterface
{
    private Grammar $grammar;
    private Processor $processor;

    public function __construct(Grammar $grammar, ?Processor $processor = null)
    {
        $this->grammar = $grammar;
        $this->processor = $processor ?? new Processor();
    }

    public function getQueryGrammar(): Grammar { return $this->grammar; }
    public function getPostProcessor(): Processor { return $this->processor; }
    public function table($table, $as = null) {}
    public function raw($value) { return new Expression($value); }
    public function selectOne($query, $bindings = [], $useReadPdo = true) {}
    public function scalar($query, $bindings = [], $useReadPdo = true) {}
    public function select($query, $bindings = [], $useReadPdo = true) { return []; }
    public function cursor($query, $bindings = [], $useReadPdo = true) {}
    public function insert($query, $bindings = []) { return true; }
    public function update($query, $bindings = []) { return 0; }
    public function delete($query, $bindings = []) { return 0; }
    public function statement($query, $bindings = []) { return true; }
    public function affectingStatement($query, $bindings = []) { return 0; }
    public function unprepared($query) { return true; }
    public function prepareBindings(array $bindings) { return $bindings; }
    public function transaction(\Closure $callback, $attempts = 1) { return $callback($this); }
    public function beginTransaction() {}
    public function commit() {}
    public function rollBack() {}
    public function transactionLevel() { return 0; }
    public function pretend(\Closure $callback) { return []; }
    public function getDatabaseName() { return 'default'; }
}
