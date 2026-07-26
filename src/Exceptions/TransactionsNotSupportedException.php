<?php

namespace ClickHouse\Laravel\Exceptions;

use LogicException;

final class TransactionsNotSupportedException extends LogicException
{
    public static function make(): self
    {
        return new self(
            'ClickHouse does not support transactions. '
            .'Set transactions to "passthrough" only when intentional non-transactional execution is acceptable.'
        );
    }
}
