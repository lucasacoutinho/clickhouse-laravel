<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Laravel\Query\Concerns\HasArrayJoin;
use ClickHouse\Laravel\Query\Concerns\HasClickHouseJoin;
use ClickHouse\Laravel\Query\Concerns\HasFinal;
use ClickHouse\Laravel\Query\Concerns\HasFormat;
use ClickHouse\Laravel\Query\Concerns\HasLimitBy;
use ClickHouse\Laravel\Query\Concerns\HasPreWhere;
use ClickHouse\Laravel\Query\Concerns\HasSample;
use ClickHouse\Laravel\Query\Concerns\HasOnCluster;
use ClickHouse\Laravel\Query\Concerns\HasRemote;
use ClickHouse\Laravel\Query\Concerns\HasSettings;
use ClickHouse\Laravel\Query\Concerns\HasWithFill;
use Illuminate\Database\Query\Builder;

class ClickHouseQueryBuilder extends Builder
{
    use HasFinal;
    use HasSample;
    use HasArrayJoin;
    use HasPreWhere;
    use HasLimitBy;
    use HasClickHouseJoin;
    use HasFormat;
    use HasSettings;
    use HasWithFill;
    use HasOnCluster;
    use HasRemote;

    // Properties used by Grammar::compileComponents() dispatch.
    // Declared explicitly to avoid PHP 8.2 dynamic property deprecation.
    public array $arrayjoin = [];
    public array $settings = [];
    public array $prewhere = [];
    public array $limitby = [];
    public array $clickhousejoin = [];
    public ?string $format = null;

    /**
     * Insert rows in chunks. ClickHouse performs best with batches of 10k+ rows.
     *
     * Usage:
     *   DB::table('events')->insertChunked($millionRows, 50000);
     *   Event::query()->insertChunked($rows);
     */
    public function insertChunked(array $values, int $chunkSize = 10000): bool
    {
        if (empty($values)) {
            return true;
        }

        foreach (array_chunk($values, $chunkSize) as $chunk) {
            if (!$this->insert($chunk)) {
                return false;
            }
        }

        return true;
    }
}
