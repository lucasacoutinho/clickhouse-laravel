<?php

namespace ClickHouse\Laravel\Tests\Unit\Eloquent;

use ClickHouse\Laravel\Eloquent\Builder;
use ClickHouse\Laravel\Eloquent\ClickHouseModel;
use ClickHouse\Laravel\Eloquent\Model;
use ClickHouse\Laravel\Tests\TestCase;

class ConcreteTestModel extends ClickHouseModel
{
    protected $table = 'test_table';
}

class ConventionalTestModel extends Model
{
    protected $table = 'test_table';
}

class ClickHouseModelTest extends TestCase
{
    protected ConcreteTestModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ConcreteTestModel;
    }

    public function test_connection_is_clickhouse(): void
    {
        $this->assertSame('clickhouse', $this->model->getConnectionName());
    }

    public function test_incrementing_is_false(): void
    {
        $this->assertFalse($this->model->getIncrementing());
    }

    public function test_timestamps_is_false(): void
    {
        $this->assertFalse($this->model->usesTimestamps());
    }

    public function test_key_type_is_string(): void
    {
        $this->assertSame('string', $this->model->getKeyType());
    }

    public function test_primary_key_is_id(): void
    {
        $this->assertSame('id', $this->model->getKeyName());
    }

    public function test_mass_assignment_is_guarded_by_default(): void
    {
        $this->assertSame(['*'], $this->model->getGuarded());
    }

    public function test_model_uses_clickhouse_eloquent_builder(): void
    {
        $this->assertInstanceOf(Builder::class, $this->model->newQuery());
    }

    public function test_conventional_model_alias_has_clickhouse_defaults(): void
    {
        $model = new ConventionalTestModel;

        $this->assertInstanceOf(ClickHouseModel::class, $model);
        $this->assertSame('clickhouse', $model->getConnectionName());
        $this->assertFalse($model->getIncrementing());
        $this->assertFalse($model->usesTimestamps());
    }
}
