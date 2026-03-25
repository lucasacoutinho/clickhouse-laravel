<?php

namespace ClickHouse\Laravel\Tests\Unit\Eloquent;

use ClickHouse\Laravel\Eloquent\ClickHouseModel;
use ClickHouse\Laravel\Tests\TestCase;

class ConcreteTestModel extends ClickHouseModel
{
    protected $table = 'test_table';
}

class ClickHouseModelTest extends TestCase
{
    protected ConcreteTestModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ConcreteTestModel();
    }

    public function testConnectionIsClickhouse(): void
    {
        $this->assertSame('clickhouse', $this->model->getConnectionName());
    }

    public function testIncrementingIsFalse(): void
    {
        $this->assertFalse($this->model->getIncrementing());
    }

    public function testTimestampsIsFalse(): void
    {
        $this->assertFalse($this->model->usesTimestamps());
    }

    public function testKeyTypeIsString(): void
    {
        $this->assertSame('string', $this->model->getKeyType());
    }

    public function testPrimaryKeyIsId(): void
    {
        $this->assertSame('id', $this->model->getKeyName());
    }

    public function testGuardedIsEmpty(): void
    {
        $this->assertSame([], $this->model->getGuarded());
    }
}
