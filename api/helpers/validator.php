<?php
/**
 * api/helpers/validator.php — Centralized input validation & pagination helpers.
 *
 * Provides:
 *   - Pagination utilities (parsePagination, paginationMeta)
 *   - Input validation functions
 *   - Common validation patterns for API endpoints
 *
 * Usage:
 *   require_once __DIR__ . '/validator.php';
 *   list($page, $limit, $offset) = parsePagination(20, 50);
 *   $meta = paginationMeta($page, $limit, $totalRecords);
 */

/**
 * Parse pagination parameters from query string.
 *
 * Extracts page and limit from $_GET, with sensible defaults and caps.
 *
 * @param int $defaultLimit Default limit if not provided (default: 20)
 * @param int $maxLimit Maximum allowed limit to prevent abuse (default: 50)
 *
 * @return array [page, limit, offset]
 *   - page: 1-based page number (minimum 1)
 *   - limit: items per page (between 1 and $maxLimit)
 *   - offset: SQL OFFSET value for pagination
 */
function parsePagination(int $defaultLimit = 20, int $maxLimit = 50): array {
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : $defaultLimit;

    // Enforce bounds
    if ($page < 1) {
        $page = 1;
    }
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > $maxLimit) {
        $limit = $maxLimit;
    }

    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

/**
 * Build pagination metadata for API responses.
 *
 * Creates standardized pagination information to include in responses.
 *
 * @param int $page Current 1-based page number
 * @param int $limit Items per page
 * @param int $total Total number of items across all pages
 *
 * @return array {page, limit, total, pages}
 *   - page: current page (1-based)
 *   - limit: items per page
 *   - total: total items
 *   - pages: total number of pages
 */
function paginationMeta(int $page, int $limit, int $total): array {
    $pages = (int) ceil($total > 0 ? $total / $limit : 0);

    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $pages
    ];
}

/**
 * Validate that a value is a positive integer.
 *
 * Throws an Exception if value is less than 1.
 *
 * @param int $value The value to validate
 * @param string $fieldName The field name for error messages
 *
 * @throws Exception if value < 1
 * @return void
 */
function validatePositive(int $value, string $fieldName): void {
    if ($value < 1) {
        throw new Exception("$fieldName must be a positive integer (got: $value)");
    }
}

/**
 * Validate that a value is non-negative.
 *
 * Throws an Exception if value is less than 0.
 *
 * @param int|float $value The value to validate
 * @param string $fieldName The field name for error messages
 *
 * @throws Exception if value < 0
 * @return void
 */
function validateNonNegative($value, string $fieldName): void {
    if ($value < 0) {
        throw new Exception("$fieldName must be non-negative (got: $value)");
    }
}

/**
 * Validate that an amount is positive (greater than 0).
 *
 * Commonly used for expenses, budgets, prices.
 *
 * @param float $amount The amount to validate
 * @param string $fieldName The field name for error messages
 *
 * @throws Exception if amount <= 0
 * @return void
 */
function validateAmount(float $amount, string $fieldName): void {
    if ($amount <= 0) {
        throw new Exception("$fieldName must be greater than 0 (got: $amount)");
    }
}

/**
 * H3/M6 (PENTEST/highly sever/ + moderately sever/): normalize a money input.
 *
 * Returns the value rounded to 2 decimals, or NULL when the input is not a
 * usable positive amount:
 *   - non-numeric strings (including locale commas such as '1,5');
 *   - values that round to 0.00 (sub-cent inputs such as 0.001 -- under
 *     strict SQL mode these would violate chk_expense_amount and crash);
 *   - anything above the 99,999,999.00 business ceiling (1e300 and
 *     9999999999999 were silently truncated by MariaDB in non-strict mode).
 *
 * Callers map NULL to their own existing validation-error response.
 */
function normalizeMoney($raw): ?float {
    if (!is_numeric($raw)) {
        return null;
    }
    $v = round((float) $raw, 2);
    if ($v < 0.01 || $v > 99999999.00) {
        return null;
    }
    return $v;
}

/**
 * Validate that a date string is in YYYY-MM-DD format.
 *
 * @param string $dateString The date to validate
 * @param string $fieldName The field name for error messages
 *
 * @throws Exception if format is invalid
 * @return void
 */
function validateDate(string $dateString, string $fieldName): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        throw new Exception("$fieldName must be in YYYY-MM-DD format (got: $dateString)");
    }

    // Also verify it's a valid date (Feb 30 should fail, etc.)
    $parts = explode('-', $dateString);
    $year = (int) $parts[0];
    $month = (int) $parts[1];
    $day = (int) $parts[2];

    if (!checkdate($month, $day, $year)) {
        throw new Exception("$fieldName is not a valid date (got: $dateString)");
    }
}


/**
 * M4 (PENTEST/moderately sever/M4.md): validate a YYYY-MM month parameter.
 * Shape AND range in one check (the loose \d{2} form accepted 2026-15).
 *
 * @throws Exception if the month is malformed or out of 01-12.
 */
function validateMonth(string $raw, string $fieldName = 'month'): string {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
        throw new Exception("$fieldName must be in YYYY-MM format (got: $raw)");
    }
    return $raw;
}

/**
 * L-2 (PENTEST/low severity/L-2.md): an ordered date range. Both bounds must
 * be calendar-valid and start <= end. Lexicographic comparison is safe here
 * because validateDate() guarantees canonical YYYY-MM-DD.
 *
 * @throws Exception on any violation.
 */
function validateDateRange(string $start, string $end, string $startField = 'start', string $endField = 'end'): void {
    validateDate($start, $startField);
    validateDate($end, $endField);
    if ($start > $end) {
        throw new Exception("$startField date is after $endField date");
    }
}
/**
 * Validate that a string is a valid email address.
 *
 * @param string $email The email to validate
 * @param string $fieldName The field name for error messages
 *
 * @throws Exception if not valid
 * @return void
 */
function validateEmail(string $email, string $fieldName): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("$fieldName is not a valid email (got: $email)");
    }
}

/**
 * Validate that a string is not empty and not too long.
 *
 * @param string $str The string to validate
 * @param string $fieldName The field name for error messages
 * @param int $minLength Minimum length (default: 1)
 * @param int $maxLength Maximum length (default: 255)
 *
 * @throws Exception if validation fails
 * @return void
 */
function validateString(string $str, string $fieldName, int $minLength = 1, int $maxLength = 255): void {
    $len = strlen($str);

    if ($len < $minLength) {
        throw new Exception("$fieldName must be at least $minLength character(s) (got: $len)");
    }

    if ($len > $maxLength) {
        throw new Exception("$fieldName must be at most $maxLength character(s) (got: $len)");
    }
}

/**
 * Validate that a value is one of the allowed options.
 *
 * @param mixed $value The value to validate
 * @param array $allowedValues List of allowed values
 * @param string $fieldName The field name for error messages
 *
 * @throws Exception if value not in allowed list
 * @return void
 */
function validateChoice($value, array $allowedValues, string $fieldName): void {
    if (!in_array($value, $allowedValues, true)) {
        $opts = implode(', ', $allowedValues);
        throw new Exception("$fieldName must be one of: $opts (got: $value)");
    }
}

/**
 * Sanitize a string for safe database storage and display.
 *
 * Trims whitespace and removes null bytes.
 * Does NOT remove special characters — that's the database's job via prepared statements.
 *
 * @param string $str The string to sanitize
 *
 * @return string Sanitized string
 */
function sanitizeString(string $str): string {
    return trim(str_replace("\x00", '', $str));
}

/**
 * Escape HTML special characters for safe display.
 *
 * Use this when outputting data in HTML context (not JSON).
 *
 * @param string $str The string to escape
 *
 * @return string HTML-escaped string
 */
function escapeHtml(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
