DROP TABLE IF EXISTS benchmark_events SYNC;

CREATE TABLE benchmark_events
(
    id UInt64,
    category UInt16,
    value Float64,
    payload String
)
ENGINE = MergeTree()
ORDER BY id;

INSERT INTO benchmark_events
SELECT
    number AS id,
    number % 100 AS category,
    number / 2 AS value,
    repeat('x', 64) AS payload
FROM numbers(1000000);

DROP TABLE IF EXISTS benchmark_sink SYNC;

CREATE TABLE benchmark_sink
(
    id UInt64,
    category UInt16,
    value Float64,
    payload String
)
ENGINE = Null();
