<?php

namespace App\Support;

/**
 * Shared validation rule fragments so the same column contract is enforced identically
 * everywhere it is validated.
 */
class ValidationRules
{
    /**
     * Rules for a money input aligned to the decimal(12,2) money column: at most two
     * decimal places and within the column's magnitude. Pass $min = 0.01 for amounts
     * that must be strictly positive (payments/refunds), 0 for prices that may be zero.
     *
     * @return list<string>
     */
    public static function money(float $min = 0): array
    {
        return ['numeric', 'min:'.$min, 'max:9999999999.99', 'decimal:0,2'];
    }

    /**
     * Rules for a free-text reason/explanation field. $max defaults to the common case
     * (cancel/no-show/offboard reasons); pass an explicit value where a field's own budget
     * differs (e.g. a required refund reason kept at 1000, a short schedule-exception reason
     * at 255).
     *
     * @return list<string>
     */
    public static function reason(int $max = 500): array
    {
        return ['string', 'max:'.$max];
    }
}
