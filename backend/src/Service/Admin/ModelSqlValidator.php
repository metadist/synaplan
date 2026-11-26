<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Validates AI-produced SQL for BMODELS updates.
 *
 * Every rule is checked on the statement with its string literals blanked
 * out, so text inside a value can never satisfy or hide a rule. What is left
 * has to consist of a short list of known words: a few SQL keywords, the
 * table BMODELS and its columns. Anything else, another table, a function,
 * a subquery, is an unknown word and refuses the statement.
 *
 * Accepted shapes:
 * - INSERT INTO BMODELS (...) VALUES (...) [ON DUPLICATE KEY UPDATE ...]
 * - UPDATE BMODELS SET ... WHERE ...
 * - DELETE FROM BMODELS WHERE ...   (only with $allowDelete)
 *
 * UPDATE and DELETE need a WHERE that pins BSERVICE, BTAG and BPROVID to
 * values and contains no OR.
 */
final readonly class ModelSqlValidator
{
    private const int MAX_STATEMENTS = 200;
    private const int MAX_SQL_LENGTH = 200000;

    private const string TABLE = 'BMODELS';

    /** Stands in for a string literal once its content is blanked out. */
    private const string LITERAL = "''";

    /**
     * @var string[]
     */
    private const ALLOWED_COLUMNS = [
        'BID',
        'BSERVICE',
        'BNAME',
        'BTAG',
        'BSELECTABLE',
        'BACTIVE',
        'BPROVID',
        'BPRICEIN',
        'BINUNIT',
        'BPRICEOUT',
        'BOUTUNIT',
        'BQUALITY',
        'BRATING',
        'BISDEFAULT',
        'BDESCRIPTION',
        'BJSON',
    ];

    /**
     * @var string[]
     */
    private const ALLOWED_KEYWORDS = [
        'INSERT', 'INTO', 'VALUES', 'ON', 'DUPLICATE', 'KEY',
        'UPDATE', 'SET',
        'DELETE', 'FROM',
        'WHERE', 'AND', 'OR', 'NOT', 'IN', 'IS', 'LIKE',
        'NULL', 'TRUE', 'FALSE',
    ];

    /**
     * @var string[]
     */
    private const WHERE_KEY_COLUMNS = ['BSERVICE', 'BTAG', 'BPROVID'];

    /**
     * @return array{statements: string[], errors: string[]}
     */
    public function validateAndSplit(string $sql, bool $allowDelete = false): array
    {
        $sql = trim($sql);
        if ('' === $sql) {
            return ['statements' => [], 'errors' => ['SQL is empty']];
        }

        if (strlen($sql) > self::MAX_SQL_LENGTH) {
            return ['statements' => [], 'errors' => ['SQL is too large']];
        }

        $scan = $this->scan($sql);
        if (null !== $scan['error']) {
            return ['statements' => [], 'errors' => [$scan['error']]];
        }
        if (count($scan['statements']) > self::MAX_STATEMENTS) {
            return ['statements' => [], 'errors' => [sprintf('Too many statements (max %d)', self::MAX_STATEMENTS)]];
        }

        $errors = [];
        $statements = [];
        foreach ($scan['statements'] as $idx => $statement) {
            $problem = $this->problem($statement['code'], $allowDelete);
            if (null !== $problem) {
                $errors[] = sprintf('Statement %d %s', $idx + 1, $problem);
                continue;
            }
            $statements[] = $statement['sql'].';';
        }

        if ([] !== $errors) {
            return ['statements' => [], 'errors' => $errors];
        }

        return ['statements' => $statements, 'errors' => []];
    }

    /**
     * Why the statement is refused, or null. $code is the statement with its
     * literals blanked out.
     */
    private function problem(string $code, bool $allowDelete): ?string
    {
        if (!preg_match('/^(INSERT|UPDATE|DELETE)\b/i', $code, $m)) {
            return 'must start with INSERT, UPDATE, or DELETE';
        }
        $verb = strtoupper($m[1]);

        if ('DELETE' === $verb && !$allowDelete) {
            return 'uses DELETE but allowDelete=false';
        }

        if (preg_match('/[^A-Za-z0-9_\s(),=<>!.+\-*\/\']/', $code, $m)) {
            return sprintf('contains a character that is not allowed: %s', $m[0]);
        }

        if (preg_match('/\b(INTO|UPDATE|FROM)\s+'.self::TABLE.'\s*,/i', $code)) {
            return 'must not reference multiple tables';
        }
        if (preg_match('/\b(JOIN|USING)\b/i', $code)) {
            return 'must not reference other tables';
        }

        $word = $this->unknownWord($code);
        if (null !== $word) {
            return sprintf('uses a word that is not allowed: %s', $word);
        }

        return match ($verb) {
            'INSERT' => $this->insertProblem($code),
            'UPDATE' => $this->updateProblem($code),
            default => $this->deleteProblem($code),
        };
    }

    /**
     * The first word that is neither an allowed keyword, the table, one of
     * its columns nor a plain number.
     */
    private function unknownWord(string $code): ?string
    {
        preg_match_all('/[A-Za-z0-9_]+/', $code, $matches);
        foreach ($matches[0] as $word) {
            if (ctype_digit($word)) {
                continue;
            }
            $upper = strtoupper($word);
            if (self::TABLE === $upper
                || in_array($upper, self::ALLOWED_COLUMNS, true)
                || in_array($upper, self::ALLOWED_KEYWORDS, true)
            ) {
                continue;
            }

            return $word;
        }

        return null;
    }

    private function insertProblem(string $code): ?string
    {
        if (!preg_match('/^INSERT\s+INTO\s+'.self::TABLE.'\s*\(/i', $code)) {
            return 'must target table BMODELS as INSERT INTO BMODELS (...)';
        }
        if (preg_match('/\b(WHERE|FROM|DELETE|SET)\b/i', $code)) {
            return 'must be a plain INSERT ... VALUES';
        }

        return null;
    }

    private function updateProblem(string $code): ?string
    {
        if (!preg_match('/^UPDATE\s+'.self::TABLE.'\s+SET\s/i', $code)) {
            return 'must target table BMODELS as UPDATE BMODELS SET ...';
        }
        if (preg_match('/\b(INSERT|INTO|VALUES|DELETE|FROM)\b/i', $code)) {
            return 'must be a plain UPDATE ... SET ... WHERE';
        }

        return $this->whereProblem($code);
    }

    private function deleteProblem(string $code): ?string
    {
        if (!preg_match('/^DELETE\s+FROM\s+'.self::TABLE.'\s+WHERE\s/i', $code)) {
            return 'must target table BMODELS as DELETE FROM BMODELS WHERE ...';
        }
        if (preg_match('/\b(INSERT|INTO|VALUES|UPDATE|SET)\b/i', $code)) {
            return 'must be a plain DELETE ... WHERE';
        }

        return $this->whereProblem($code);
    }

    /**
     * The WHERE has to name one model: each key column is compared with a
     * value, and no OR widens the match again.
     */
    private function whereProblem(string $code): ?string
    {
        $parts = preg_split('/\bWHERE\b/i', $code);
        if (false === $parts || count($parts) < 2) {
            return 'must include a WHERE clause';
        }
        if (count($parts) > 2) {
            return 'must include exactly one WHERE clause';
        }
        $where = $parts[1];

        foreach (self::WHERE_KEY_COLUMNS as $column) {
            $pinned = '/\b'.$column.'\s*(=\s*'.self::LITERAL.'|IN\s*\(\s*'.self::LITERAL.'(\s*,\s*'.self::LITERAL.')*\s*\))/i';
            if (!preg_match($pinned, $where)) {
                return 'WHERE must include BSERVICE, BTAG and BPROVID, each compared with a value';
            }
        }

        if (preg_match('/\bOR\b/i', $where)) {
            return 'WHERE must not use OR';
        }

        return null;
    }

    /**
     * Split the script into statements and blank out every string literal.
     *
     * Only single-quoted strings with a doubled quote as the escape are
     * accepted. A backslash escape or a double-quoted string is read
     * differently depending on the server's SQL mode, so the text this
     * method takes for a value could be code to the database.
     *
     * @return array{statements: list<array{sql: string, code: string}>, error: ?string}
     */
    private function scan(string $sql): array
    {
        $statements = [];
        $raw = '';
        $code = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; ++$i) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ("'" === $ch) {
                $end = $this->literalEnd($sql, $i);
                if (null === $end) {
                    return ['statements' => [], 'error' => 'SQL contains an unterminated string'];
                }
                $literal = substr($sql, $i, $end - $i + 1);
                if (str_contains($literal, '\\')) {
                    return ['statements' => [], 'error' => 'String values must not contain a backslash; write a single quote as two single quotes'];
                }
                $raw .= $literal;
                $code .= self::LITERAL;
                $i = $end;
                continue;
            }

            if ('"' === $ch) {
                return ['statements' => [], 'error' => 'Use single quotes for string values'];
            }
            if ('#' === $ch || ('-' === $ch && '-' === $next) || ('/' === $ch && '*' === $next)) {
                return ['statements' => [], 'error' => 'SQL must not contain comments'];
            }

            if (';' === $ch) {
                if ('' !== trim($raw)) {
                    $statements[] = ['sql' => trim($raw), 'code' => trim($code)];
                }
                $raw = '';
                $code = '';
                continue;
            }

            $raw .= $ch;
            // A backticked name is the same name without the backticks.
            $code .= '`' === $ch ? ' ' : $ch;
        }

        if ('' !== trim($raw)) {
            $statements[] = ['sql' => trim($raw), 'code' => trim($code)];
        }

        return ['statements' => $statements, 'error' => null];
    }

    /**
     * Offset of the quote that closes the literal opened at $start.
     */
    private function literalEnd(string $sql, int $start): ?int
    {
        $len = strlen($sql);
        for ($i = $start + 1; $i < $len; ++$i) {
            if ("'" !== $sql[$i]) {
                continue;
            }
            if ($i + 1 < $len && "'" === $sql[$i + 1]) {
                ++$i;
                continue;
            }

            return $i;
        }

        return null;
    }
}
