<?php

namespace App\Support;

final class PaymentCallback
{
    public static function isFlatPayload($params): bool
    {
        if (!is_array($params)) return false;
        foreach ($params as $value) {
            if ($value !== null && !is_scalar($value)) return false;
        }
        return true;
    }

    /** Parse a gateway's decimal money value without floating point rounding. */
    public static function amountMinor($amount): ?int
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) return null;
        if (!preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,2})0*)?\z/', (string) $amount, $match)) return null;
        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }
}
