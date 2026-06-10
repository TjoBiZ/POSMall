<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Index;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Collection;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\SortOrder;
use KodZero\POSMall\Classes\Index\PostgreSQL\PostgreSQL;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PostgreSQLTest extends TestCase
{
    public function test_category_filter_uses_indexable_jsonb_containment_sql(): void
    {
        $query = $this->compileWithPostgresGrammar(function (PostgreSQL $index, $query): void {
            $filters = new Collection([
                'category_id' => new SetFilter('category_id', [1]),
            ]);

            $this->callProtected($index, 'applySpecialFilters', [$filters, $query]);
        });

        $this->assertStringContainsString('category_id @> ?::jsonb', $query->toSql());
        $this->assertStringNotContainsString('category_id::jsonb @>', $query->toSql());
        $this->assertSame(['[1]'], $query->getBindings());
    }

    public function test_property_set_filter_uses_indexable_jsonb_containment_sql(): void
    {
        $query = $this->compileWithPostgresGrammar(function (PostgreSQL $index, $query): void {
            $filters = new Collection([
                new SetFilter((object)['id' => 17], ['red']),
            ]);

            $this->callProtected($index, 'applyCustomFilters', [$filters, $query]);
        });

        $this->assertStringContainsString('property_values @> ?::jsonb', $query->toSql());
        $this->assertStringNotContainsString('property_values::jsonb @>', $query->toSql());
        $this->assertSame(['{"17":["red"]}'], $query->getBindings());
    }

    public function test_excluded_product_id_filter_uses_compact_postgresql_array_predicate(): void
    {
        $query = $this->compileWithPostgresGrammar(function (PostgreSQL $index, $query): void {
            $filters = new Collection([
                'product_id' => new SetFilter('product_id', [10, 11], true),
            ]);

            $this->callProtected($index, 'applySpecialFilters', [$filters, $query]);
        });

        $this->assertStringContainsString('product_id <> ALL (?::int[])', $query->toSql());
        $this->assertSame(['{10,11}'], $query->getBindings());
    }

    public function test_unsafe_custom_sort_property_falls_back_to_id_order(): void
    {
        $query = $this->compileWithPostgresGrammar(function (PostgreSQL $index, $query): void {
            $this->callProtected($index, 'handleOrder', [
                $this->fakeSortOrder('prices); drop table kodzero_posmall_index; --.USD', 'desc; drop'),
                $query,
            ]);
        });

        $sql = $query->toSql();

        $this->assertStringContainsString('order by "id" asc', $sql);
        $this->assertStringNotContainsString('drop table', strtolower($sql));
        $this->assertSame([], $query->getBindings());
    }

    public function test_builtin_price_sort_still_uses_bound_json_keys(): void
    {
        $query = $this->compileWithPostgresGrammar(function (PostgreSQL $index, $query): void {
            $this->callProtected($index, 'handleOrder', [
                $this->fakeSortOrder('prices.USD', 'desc'),
                $query,
            ]);
        });

        $sql = $query->toSql();

        $this->assertStringContainsString('COALESCE((prices->>?)::numeric, (parent_prices->>?)::numeric) desc NULLS LAST', $sql);
        $this->assertStringContainsString(', "id" asc', $sql);
        $this->assertSame(['USD', 'USD'], $query->getBindings());
    }

    public function test_builtin_column_sort_adds_stable_id_tiebreaker(): void
    {
        $query = $this->compileWithPostgresGrammar(function (PostgreSQL $index, $query): void {
            $this->callProtected($index, 'handleOrder', [
                $this->fakeSortOrder('name', 'asc'),
                $query,
            ]);
        });

        $this->assertStringContainsString('order by "name" asc, "id" asc', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    private function compileWithPostgresGrammar(callable $callback)
    {
        $connection = new PostgresConnection($this->postgresPdo());
        $connection->setQueryGrammar(new PostgresGrammar($connection));

        $query = $connection->table('kodzero_posmall_index');
        $index = (new ReflectionClass(PostgreSQL::class))->newInstanceWithoutConstructor();

        $callback($index, $query);

        return $query;
    }

    private function postgresPdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $database = getenv('DB_DATABASE') ?: 'posmall_test';
        $username = getenv('DB_USERNAME') ?: null;
        $password = getenv('DB_PASSWORD') ?: null;

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            $username ?: null,
            $password ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function callProtected(object $object, string $method, array $arguments = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function fakeSortOrder(string $property, string $direction): SortOrder
    {
        return new class($property, $direction) extends SortOrder {
            public function __construct(private string $sortProperty, private string $sortDirection)
            {
            }

            public function property(): string
            {
                return $this->sortProperty;
            }

            public function direction(): string
            {
                return $this->sortDirection;
            }

            public function key(): string
            {
                return 'test';
            }
        };
    }
}
