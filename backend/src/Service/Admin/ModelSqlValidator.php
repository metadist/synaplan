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
    public function validateAndSplit(string $sql): array
    {
        $sql = trim($sql);
        if ('' === $sql) {
            return ['statements' => [], 'errors' => ['SQL is empty']];
        }

        // Hard block comments and obvious injection / multi-command tricks
        if (preg_match('/(--|#|\/\*|\*\/)/', $sql)) {
            return ['statements' => [], 'errors' => ['SQL must not contain comments']];
        }
        if (preg_match('/\b(SELECT|UNION|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|LOCK|UNLOCK|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
            return ['statements' => [], 'errors' => ['Only INSERT/UPDATE/DELETE statements are allowed']];
        }

        // Split by ';' but keep it simple and conservative: we don't support semicolons inside strings.
        $parts = array_filter(array_map('trim', explode(';', $sql)), fn ($p) => '' !== $p);

        $errors = [];
        $statements = [];

        foreach ($parts as $idx => $stmt) {
            $stmtTrim = trim($stmt);

            // Must start with a DML statement
            if (!preg_match('/^(INSERT|UPDATE|DELETE)\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must start with INSERT, UPDATE, or DELETE', $idx + 1);
                continue;
            }

            // Must target BMODELS only
            if (!preg_match('/\b(INTO|UPDATE|FROM)\s+`?BMODELS`?\b/i', $stmtTrim)) {
                $errors[] = sprintf('Statement %d must target table BMODELS', $idx + 1);
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
            $tokens = array_unique(array_map('strtoupper', $matches[0] ?? []));
            foreach ($tokens as $token) {
                if (!in_array($token, self::ALLOWED_COLUMNS, true) && 'BMODELS' !== $token) {
                    $errors[] = sprintf('Statement %d uses disallowed column/token: %s', $idx + 1, $token);
                }
            }

            $statements[] = $stmtTrim.';';
        }

        if (!empty($errors)) {
            return ['statements' => [], 'errors' => $errors];
        }

        return ['statements' => $statements, 'errors' => []];
    }
}


