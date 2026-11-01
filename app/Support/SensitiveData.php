<?php

namespace App\Support;

final class SensitiveData
{
    public static function redact($value, int $depth = 0)
    {
        if ($depth > 8) return '[TRUNCATED]';
        if ($value instanceof \Throwable) return ['exception' => get_class($value), 'code' => $value->getCode()];
        if (is_object($value)) return '[OBJECT ' . get_class($value) . ']';
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $key => $item) {
            $sensitive = preg_match('/password|passwd|pwd|token|secret|authorization|cookie|signature|private.?key|api.?key|auth_data|session|(?:^|_)key$|(?:^|_)sign$/i', (string) $key);
            $result[$key] = $sensitive ? '[REDACTED]' : self::redact($item, $depth + 1);
        }
        return $result;
    }
}
