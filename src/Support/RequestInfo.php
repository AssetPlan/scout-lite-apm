<?php
namespace AssetPlan\ScoutLiteAPM\Support;

class RequestInfo
{
    public static function uri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public static function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    public static function queueStartTime(): ?float
    {
        foreach (['HTTP_X_QUEUE_START', 'HTTP_X_REQUEST_START'] as $header) {
            if (isset($_SERVER[$header])) {
                $value = $_SERVER[$header];
                if (strpos($value, 't=') === 0) {
                    $value = substr($value, 2);
                }
                return (float) $value;
            }
        }

        return null;
    }
}
