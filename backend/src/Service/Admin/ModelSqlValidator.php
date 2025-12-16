<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Validates AI-produced SQL for BMODELS updates.
 *
 * Security goals:
 * - Only allow INSERT/UPDATE/DELETE against table BMODELS
 * - For UPDATE/DELETE require a WHERE that includes BSERVICE + BTAG + BPROVID
 * - Disallow multi-statement separators other than ";" line ends
 * - Disallow other tables, DDL, SELECT, UNION, comments
 */
final class ModelSqlValidator
{
    private const int MAX_STATEMENTS = 200;
    private const int MAX_SQL_LENGTH = 200000;

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

        $split = $this->splitStatements($sql);
        if (!empty($split['errors'])) {
            return ['statements' => [], 'errors' => $split['errors']];
        }
        $parts = $split['statements'];
        if (count($parts) > self::MAX_STATEMENTS) {
            return ['statements' => [], 'errors' => [sprintf('Too many statements (max %d)', self::MAX_STATEMENTS)]];
        }

        $errors = [];
        $statements = [];

        foreach ($parts as $idx => $stmt) {
            $stmtTrim = trim($stmt);

            // Must start with a DML statement
            if (!preg_match('/^(INSERT|UPDATE|DELETE)\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must start with INSERT, UPDATE, or DELETE', $idx + 1);
                continue;
            }

            if (!$allowDelete && preg_match('/^DELETE\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d uses DELETE but allowDelete=false', $idx + 1);
                continue;
            }

            // Must target BMODELS only (single-table DML)
            // Reject multi-table syntax: UPDATE t1, t2 / DELETE FROM t1, t2 / DELETE FROM t1 USING t2
            if (!preg_match('/\b(INTO|UPDATE|FROM)\s+`?BMODELS`?\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must target table BMODELS', $idx + 1);
                continue;
            }

            // Detect multi-table DML (comma after table name or USING clause)
            // e.g. "DELETE FROM BMODELS, USERS" or "UPDATE BMODELS, USERS SET" or "DELETE FROM BMODELS USING USERS"
            if (preg_match('/\b(INTO|UPDATE|FROM)\s+`?BMODELS`?\s*,/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must not reference multiple tables', $idx + 1);
                continue;
            }
            if (preg_match('/\bUSING\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must not use USING clause', $idx + 1);
                continue;
            }

            // Must not mention JOINs (we only allow single-table DML against BMODELS).
            // NOTE: We deliberately do NOT try to detect "<identifier>.<identifier>" because
            // provider ids like "gpt-4.1" appear inside string literals and would false-positive.
            if (preg_match('/\bJOIN\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must not reference other tables', $idx + 1);
                continue;
            }

            // For UPDATE/DELETE require WHERE and the unique key fields
            if (preg_match('/^(UPDATE|DELETE)\b/i', $stmtTrim)) {
                if (!preg_match('/\bWHERE\b/i', $stmtTrim)) {
                    $errors[] = sprintf('Statement %d must include a WHERE clause', $idx + 1);
                    continue;
                }
                $where = preg_split('/\bWHERE\b/i', $stmtTrim, 2);
                $wherePart = $where[1] ?? '';
                if (!preg_match('/\bBSERVICE\b/i', $wherePart) || !preg_match('/\bBTAG\b/i', $wherePart) || !preg_match('/\bBPROVID\b/i', $wherePart)) {
                    $errors[] = sprintf('Statement %d WHERE must include BSERVICE, BTAG and BPROVID', $idx + 1);
                    continue;
                }
            }

            // Column allowlist check (best-effort): if we see "B<WORD>" tokens, they must be allowed.
            preg_match_all('/\bB[A-Z0-9_]+\b/i', $stmtTrim, $matches);
            $tokens = array_unique(array_map('strtoupper', $matches[0]));
            foreach ($tokens as $token) {
                if (!in_array($token, self::ALLOWED_COLUMNS, true) && 'BMODELS' !== $token) {
                    $errors[] = sprintf('Statement %d uses disallowed column/token: %s', $idx + 1, $token);
                }
            }

            if (preg_match('/\b(SELECT|UNION|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|LOCK|UNLOCK|SHOW|DESCRIBE|EXPLAIN)\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d contains a forbidden keyword', $idx + 1);
                continue;
            }

            $statements[] = rtrim($stmtTrim, ';').';';
        }

        if (!empty($errors)) {
            return ['statements' => [], 'errors' => $errors];
        }

        return ['statements' => $statements, 'errors' => []];
    }

    /**
     * Split SQL into statements by semicolons outside of string literals/backticks.
     * Rejects SQL that contains comments outside of string literals.
     *
     * @return array{statements: string[], errors: string[]}
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        $len = strlen($sql);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Detect comments outside strings/backticks
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ('-' === $ch && '-' === $next) {
                    return ['statements' => [], 'errors' => ['SQL must not contain comments']];
                }
                if ('#' === $ch) {
                    return ['statements' => [], 'errors' => ['SQL must not contain comments']];
                }
                if ('/' === $ch && '*' === $next) {
                    return ['statements' => [], 'errors' => ['SQL must not contain comments']];
                }
            }

            // Toggle quoting modes
            if (!$inDouble && !$inBacktick && "'" === $ch) {
                if ($inSingle && "'" === $next) {
                    // Escaped single quote in MySQL (''), keep both
                    $current .= "''";
                    ++$i;
                    continue;
                }
                $inSingle = !$inSingle;
                $current .= $ch;
                continue;
            }
            if (!$inSingle && !$inBacktick && '"' === $ch) {
                $inDouble = !$inDouble;
                $current .= $ch;
                continue;
            }
            if (!$inSingle && !$inDouble && '`' === $ch) {
                $inBacktick = !$inBacktick;
                $current .= $ch;
                continue;
            }

            // Handle backslash escapes inside quotes (best-effort)
            if (($inSingle || $inDouble) && '\\' === $ch && '' !== $next) {
                $current .= $ch.$next;
                ++$i;
                continue;
            }

            // Statement delimiter
            if (!$inSingle && !$inDouble && !$inBacktick && ';' === $ch) {
                $stmt = trim($current);
                if ('' !== $stmt) {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $tail = trim($current);
        if ('' !== $tail) {
            $statements[] = $tail;
        }

        return ['statements' => $statements, 'errors' => []];
    }
}
