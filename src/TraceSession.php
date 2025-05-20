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

    public static function bootstrap($app = null, $key = null, $apiVersion = '1.0')
    {

        if (! isset($app) || ! isset($key)) {
            return;
        }

        self::register($app, $key, $apiVersion);
    }

    public static function register($app, $key, $apiVersion = '1.0', $socketPath = null)
    {
        if ($socketPath) {
            self::$socketPath = $socketPath;
        }
        self::$eventBuffer[] = [
            'Register' => [
                'app' => $app,
                'key' => $key,
                'api_version' => $apiVersion,
            ],
        ];
    }

    public static function startRequest()
    {
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

        self::$eventBuffer[] = [
            'TagRequest' => [
                'request_id' => self::$requestId,
                'tag' => 'user_agent',
                'value' =>  RequestInfo::userAgent(),
                'timestamp' => Timestamp::formatNow(),
            ]
        ];
    }

    public static function endRequest()
    {
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
        if (!$controllerSpanId) {
            return;
        }

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

        $spanId = 'span-' . UUID::v4();
        $operation = 'SQL/Query';

        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\s+.*?\bFROM\b\s+`?(\w+)`?/i', $sql, $m)) {
            $verb = strtolower($m[1]);
            $table = $m[2];
            $operation = "SQL/{$table}/{$verb}";
        } elseif (preg_match('/^\s*(INSERT|UPDATE|DELETE)\s+INTO\s+`?(\w+)`?/i', $sql, $m)) {
            $verb = strtolower($m[1]);
            $table = $m[2];
            $operation = "SQL/{$table}/{$verb}";
        }

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
        self::$eventBuffer[] = ['StopSpan' => [
            'request_id' => self::$requestId,
            'span_id' => $sqlSpan,
            'timestamp' => Timestamp::formatNow(),
        ]];
    }

    public static function flush()
    {
        Socket::make(self::$socketPath);

        $flushed = Socket::send(self::$eventBuffer);

        if (!$flushed) {
            error_log('[ScoutLite] Failed to flush events');
        }

        self::$eventBuffer = [];
    }
}
