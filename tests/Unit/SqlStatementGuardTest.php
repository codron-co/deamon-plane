<?php

namespace Tests\Unit;

use App\Services\Databases\SqlStatementGuard;
use App\Services\Databases\SqlStatementRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlStatementGuardTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function statements(): array
    {
        return [
            'select' => ['SELECT * FROM users', SqlStatementGuard::READ],
            'lowercase select with trailing semicolon' => ["select id from orders;\n", SqlStatementGuard::READ],
            'parenthesized select' => ['(SELECT 1)', SqlStatementGuard::READ],
            'cte' => ['WITH x AS (SELECT 1) SELECT * FROM x', SqlStatementGuard::READ],
            'show' => ['SHOW TABLES', SqlStatementGuard::READ],
            'describe' => ['DESCRIBE users', SqlStatementGuard::READ],
            'explain' => ['EXPLAIN SELECT 1', SqlStatementGuard::READ],
            'leading comment' => ["-- top rows\nSELECT 1", SqlStatementGuard::READ],
            'semicolon inside a string' => ["SELECT 'a;b' AS x", SqlStatementGuard::READ],
            'update' => ['UPDATE users SET name = 1', SqlStatementGuard::WRITE],
            'insert' => ['insert into t values (1)', SqlStatementGuard::WRITE],
            'delete' => ['DELETE FROM sessions', SqlStatementGuard::WRITE],
            'alter' => ['ALTER TABLE t ADD c INT', SqlStatementGuard::WRITE],
            'set' => ['SET SESSION sql_mode = ""', SqlStatementGuard::WRITE],
            'comment hiding a write keyword is still a write' => ['/* SELECT */ DROP TABLE t', SqlStatementGuard::WRITE],
        ];
    }

    #[DataProvider('statements')]
    public function test_classifies_statements(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlStatementGuard::classify($sql));
    }

    public function test_rejects_a_second_statement(): void
    {
        $this->expectException(SqlStatementRejected::class);

        SqlStatementGuard::classify('SELECT 1; DROP TABLE users');
    }

    public function test_rejects_an_empty_statement(): void
    {
        try {
            SqlStatementGuard::classify("  -- nothing\n ; ");
            $this->fail('Expected rejection');
        } catch (SqlStatementRejected $e) {
            $this->assertSame('empty', $e->reason);
        }
    }
}
