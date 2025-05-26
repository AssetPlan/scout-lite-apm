<?php

namespace AssetPlan\ScoutLiteAPM;

use AssetPlan\ScoutLiteAPM\Support\RequestInfo;
use AssetPlan\ScoutLiteAPM\Support\Timestamp;
use AssetPlan\ScoutLiteAPM\Support\UUID;
use AssetPlan\ScoutLiteAPM\Transport\Socket;

class TraceSession
{
    protected static $requestId;
    protected static $eventBuffer = [];
    protected static $socketPath;
    protected static $openSpans = [];
    protected static $bootstrapped = false;
    protected static $hostname;

    public static function bootstrap($app = null, $key = null, $socketPath = 'tcp://127.0.0.1:6590', $apiVersion = '1.0', $hostname = null)
    {

        if (! isset($app) || ! isset($key) || ! isset($socketPath)) {
            return;
        }

        self::register($app, $key, $socketPath, $apiVersion, $hostname);

        self::$bootstrapped = true;
    }

    public static function register($app, $key, $socketPath = 'tcp://127.0.0.1:6590', $apiVersion = '1.0', $hostname = null)
    {
        if ($socketPath) {
            self::$socketPath = $socketPath;
        }

        if (! $hostname) {
            $hostname = gethostname();
        }

        self::$hostname = $hostname;

        self::$eventBuffer[] = [
            'Register' => [
                'app' => $app,
                'key' => $key,
                'api_version' => $apiVersion,
                'host' => $hostname,
            ],
        ];
    }

    public static function startRequest()
    {
        if (!self::$bootstrapped) {
            return;
        }

        self::$requestId = UUID::v4();

        $requestPath = RequestInfo::uri();


        self::$eventBuffer[] = [
            'StartRequest' => [
                'request_id' =>  self::$requestId,
                'timestamp' => Timestamp::formatNow(),
            ]
        ];

        if ($requestPath) {
            self::$eventBuffer[] = [
                'TagRequest' => [
                    'request_id' => self::$requestId,
                    'tag' => 'request_path',
                    'value' => $requestPath,
                    'timestamp' => Timestamp::formatNow(),
                ]
            ];
        }

        self::$eventBuffer[] = ['TagRequest' => [
            'request_id' => self::$requestId,
            'tag' => 'node',
            'value' => self::$hostname,
            'timestamp' => Timestamp::formatNow(),
        ]];

        self::$eventBuffer[] = [
            'TagRequest' => [
                'request_id' => self::$requestId,
                'tag' => 'user_agent',
                'value' =>  RequestInfo::userAgent(),
                'timestamp' => Timestamp::formatNow(),
            ]
        ];

        $queueStart = RequestInfo::queueTimeStart();
        if ($queueStart !== null) {
            $now = microtime(true);
            $queueTimeNs = ($now - $queueStart) * 1e9;

            self::$eventBuffer[] = [
                'TagRequest' => [
                    'request_id' => self::$requestId,
                    'tag' => 'queue_time_ns',
                    'value' => (int) $queueTimeNs,
                    'timestamp' => Timestamp::formatNow(),
                ]
            ];
        }
    }
    public static function endRequest()
    {
        if (!self::$bootstrapped) {
            return;
        }
        self::$eventBuffer[] = [
            'FinishRequest' => [
                'request_id' => self::$requestId,
                'timestamp' => Timestamp::formatNow(),
            ],
        ];
    }

    public static function startController($controllerName, $action)
    {

        $operation =  'Controller/' . $controllerName . '::' . $action;

        $spanId = 'span-' .  UUID::v4();

        if (!self::$bootstrapped) {
            return $spanId;
        }

        self::$openSpans[$spanId] = true;

        self::$eventBuffer[] = [
            'StartSpan' => [
                'request_id' => self::$requestId,
                'span_id' => $spanId,
                'operation' => $operation, // must start with "Controller/"
                'timestamp' =>  Timestamp::formatNow(),
                'parent_id' => null,
            ],
        ];

        return $spanId;
    }

    public static function endController($controllerSpanId)
    {

        if (!isset(self::$openSpans[$controllerSpanId]) || !self::$bootstrapped) {
            error_log('[ScoutLite] Span not found: ' . $controllerSpanId);
            return;
        }

        unset(self::$openSpans[$controllerSpanId]);

        self::$eventBuffer[] = [
            'StopSpan' => [
                'request_id' => self::$requestId,
                'span_id' => $controllerSpanId,
                'timestamp' => Timestamp::formatNow(),
            ],
        ];
    }

    public static function startSql($sql)
    {
        if (!self::$bootstrapped) {
            return;
        }

        $spanId = 'span-' . UUID::v4();

        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\s+.*?\bFROM\b\s+`?(\w+)`?/i', $sql, $m)) {
            $verb = strtolower($m[1]);
            $table = $m[2];
            $operation = "SQL/{$table}/{$verb}";
        } elseif (preg_match('/^\s*(INSERT|UPDATE|DELETE)\s+INTO\s+`?(\w+)`?/i', $sql, $m)) {
            $verb = strtolower($m[1]);
            $table = $m[2];
            $operation = "SQL/{$table}/{$verb}";
        }

        if (!isset($operation)) {
            return null;
        }

        self::$openSpans[$spanId] = true;

        $redacted = preg_replace("/('[^']*'|\b\d+\b)/", '?', $sql);

        self::$eventBuffer[] = ['StartSpan' => [
            'request_id' => self::$requestId,
            'span_id' => $spanId,
            'operation' => $operation,
            'timestamp' => Timestamp::formatNow(),
        ]];

        self::$eventBuffer[] = ['TagSpan' => [
            'request_id' => self::$requestId,
            'span_id' => $spanId,
            'tag' => 'db.statement',
            'value' => $redacted,
            'timestamp' => Timestamp::formatNow(),
        ]];

        return $spanId;
    }

    public static function endSql($sqlSpan)
    {
        if (!$sqlSpan || !self::$bootstrapped) {
            return;
        }

        if (!isset(self::$openSpans[$sqlSpan])) {
            error_log('[ScoutLite] Span not found: ' . $sqlSpan);
            return;
        }

        unset(self::$openSpans[$sqlSpan]);
        self::$eventBuffer[] = ['StopSpan' => [
            'request_id' => self::$requestId,
            'span_id' => $sqlSpan,
            'timestamp' => Timestamp::formatNow(),
        ]];
    }

    public static function startCustom($operation = '', $parentId = null)
    {
        if (!isset($operation) || $operation === '') {
            return;
        }

        if (!self::$bootstrapped) {
            return;
        }

        $spanId = 'span-' . UUID::v4();

        self::$openSpans[$spanId] = true;

        self::$eventBuffer[] = [
            'StartSpan' => [
                'request_id' => self::$requestId,
                'span_id' => $spanId,
                'parent_id' => $parentId,
                'operation' => $operation,
                'timestamp' => Timestamp::formatNow(),
            ]
        ];

        return $spanId;
    }

    public static function endCustom($spanId)
    {
        if (!isset(self::$openSpans[$spanId])) {
            error_log('[ScoutLite] Span not found: ' . $spanId);
            return;
        }

        unset(self::$openSpans[$spanId]);

        self::$eventBuffer[] = ['StopSpan' => [
            'request_id' => self::$requestId,
            'span_id' => $spanId,
            'timestamp' => Timestamp::formatNow(),
        ]];
    }

    public static function addContext($key, $value)
    {
        self::$eventBuffer[] = [
            'TagRequest' => [
                'request_id' => self::$requestId,
                'tag' => $key,
                'value' => $value,
                'timestamp' => Timestamp::formatNow(),
            ]
        ];
    }

    public static function instrument($name, $callback, $parentId = null)
    {
        $spanId = 'span-' . UUID::v4();
        self::$openSpans[$spanId] = true;

        self::$eventBuffer[] = ['StartSpan' => [
            'request_id' => self::$requestId,
            'span_id' => $spanId,
            'operation' => $name,
            'timestamp' => Timestamp::formatNow(),
            'parent_id' => $parentId,
        ]];

        try {
            return call_user_func($callback);
        } finally {
            self::endCustom($spanId);
        }
    }

    public static function flush()
    {
        $isValid = self::isValid();
        $hasEvents = count(self::$eventBuffer) > 0;

        if (!$hasEvents) {
            error_log('[ScoutLite] No events to flush');
        }

        if (!$isValid) {
            error_log('[ScoutLite] Trace session is not valid');
        }

        $shouldFlush = $isValid && $hasEvents;

        if (!$shouldFlush) {
            self::resetSession();
            return;
        }


        Socket::make(self::$socketPath);

        $flushed = Socket::send(self::$eventBuffer);

        if (!$flushed) {
            error_log('[ScoutLite] Failed to flush events');
        }

        self::resetSession();
    }

    public static function isValid()
    {
        if (!self::$bootstrapped) {
            return false;
        }

        if (!self::$requestId || empty(self::$eventBuffer)) {
            return false;
        }

        if (count(self::$openSpans) > 0) {
            return false;
        }

        $last = end(self::$eventBuffer);
        if (!isset($last['FinishRequest'])) {
            return false;
        }

        return true;
    }

    public static function resetSession()
    {
        self::$eventBuffer = [];
        self::$openSpans = [];
        self::$requestId = null;
        self::$bootstrapped = false;
    }

    public static function getOpenSpans()
    {
        return self::$openSpans;
    }

    public static function getEventBuffer()
    {
        return self::$eventBuffer;
    }

    public static function getRequestId()
    {
        return self::$requestId;
    }
}
