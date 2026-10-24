<?php

namespace App\Services\Databases;

/**
 * Classifies one console statement before it reaches a site database.
 *
 * The console runs exactly one statement as the site's own user. Reads run in a
 * READ ONLY transaction, so even a statement this guard misjudges cannot write;
 * the classification only decides which permission and confirmation apply.
 */
final class SqlStatementGuard
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** @var list<string> */
    private const READ_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];

    /**
     * @throws SqlStatementRejected
     */
    public static function classify(string $sql): string
    {
        $statement = self::normalize($sql);
        if ($statement === '') {
            throw new SqlStatementRejected('empty');
        }

        if (self::hasSecondStatement($statement)) {
            throw new SqlStatementRejected('multiple');
        }

        $keyword = strtoupper((string) strtok(ltrim($statement, '('), " \t\r\n("));

        return in_array($keyword, self::READ_KEYWORDS, true) ? self::READ : self::WRITE;
    }

    /**
     * Strip comments and one trailing semicolon.
     */
    public static function normalize(string $sql): string
    {
        $sql = (string) preg_replace('~/\*.*?\*/~s', ' ', $sql);
        $sql = (string) preg_replace('~(^|\s)(--|#)[^\n]*~', '$1', $sql);
        $sql = trim($sql);

        return rtrim(trim($sql), "; \t\r\n");
    }

    /**
     * A `;` outside quotes means a second statement.
     */
    private static function hasSecondStatement(string $sql): bool
    {
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === ';') {
                return true;
            }
        }

        return false;
    }
}
