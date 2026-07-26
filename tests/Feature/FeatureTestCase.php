<?php

namespace ClickHouse\Laravel\Tests\Feature;

use ClickHouse\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Base class for feature tests that need a running ClickHouse instance.
 */
#[Group('integration')]
abstract class FeatureTestCase extends TestCase
{
    //
}
