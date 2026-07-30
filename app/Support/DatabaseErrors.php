<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class DatabaseErrors
{
    /**
     * Whether the exception is a unique / integrity constraint failure.
     * SQLSTATE 23000 covers MySQL, SQLite, and Postgres.
     */
    public static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23000' || $e->getCode() === '23000';
    }
}
