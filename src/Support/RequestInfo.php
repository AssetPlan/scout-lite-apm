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

    public static function queueTimeStart()
    {
        foreach (['HTTP_X_QUEUE_START', 'HTTP_X_REQUEST_START'] as $header) {
            if (!isset($_SERVER[$header])) {
                continue;
            }

            $raw = $_SERVER[$header];

            // Strip `t=` if present
            if (strpos($raw, 't=') === 0) {
                $raw = substr($raw, 2);
            }

            $value = floatval($raw);

            if ($value > 1e18) {
                // nanoseconds
                return $value / 1e9;
            } elseif ($value > 1e15) {
                // microseconds
                return $value / 1e6;
            } elseif ($value > 1e12) {
                // milliseconds
                return $value / 1e3;
            } elseif ($value > 1000000000) {
                // seconds (Unix timestamp)
                return $value;
            }
        }

        return null;
    }
}
