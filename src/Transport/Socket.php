<?php
namespace AssetPlan\ScoutLiteAPM\Transport;

use stdClass;

class Socket
{
    protected static $socket;

    public static function make(string $socketPath)
    {
        $scheme = parse_url($socketPath, PHP_URL_SCHEME);
        $host = parse_url($socketPath, PHP_URL_HOST);
        $port = parse_url($socketPath, PHP_URL_PORT);
        $path = parse_url($socketPath, PHP_URL_PATH);

        if ($scheme === 'tcp' && $host && $port) {
            self::$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!@socket_connect(self::$socket, $host, $port)) {
                error_log("[ScoutLite] Failed TCP socket connect to $host:$port");
                return false;
            }
        } elseif ($scheme === 'unix' && $path) {
            self::$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            if (!@socket_connect(self::$socket, $path)) {
                error_log("[ScoutLite] Failed UNIX socket connect to $path");
                return false;
            }
        } elseif (file_exists($socketPath)) {
            // Direct path fallback
            self::$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            if (!@socket_connect(self::$socket, $socketPath)) {
                error_log("[ScoutLite] Failed file path socket connect to $socketPath");
                return false;
            }
        } else {
            error_log("[ScoutLite] Invalid socket path: $socketPath");
            return false;
        }

        return true;
    }

    public static function send(array $buffer)
    {
        if (!self::$socket) {
            error_log('[ScoutLite] Cannot flush — no active socket');
            return false;
        }

        try {
            if (isset($buffer[0]['Register'])) {
                $register = json_encode($buffer[0], JSON_UNESCAPED_SLASHES);
                socket_send(self::$socket, pack('N', strlen($register)), 4, 0);
                socket_send(self::$socket, $register, strlen($register), 0);

                socket_set_block(self::$socket);
                $response = socket_read(self::$socket, 8192);
                error_log('[ScoutLite] Agent response: ' . $response);
            }

            $batch = json_encode(['BatchCommand' => [
                'commands' => array_slice($buffer, 1),
            ]], JSON_UNESCAPED_SLASHES);

            socket_send(self::$socket, pack('N', strlen($batch)), 4, 0);
            socket_send(self::$socket, $batch, strlen($batch), 0);

            $bye = json_encode(['Goodbye' => new stdClass()]);
            socket_send(self::$socket, pack('N', strlen($bye)), 4, 0);
            socket_send(self::$socket, $bye, strlen($bye), 0);

            usleep(100_000);
            socket_close(self::$socket);
            self::$socket = null;

            return true;
        } catch (\Throwable $e) {
            error_log('[ScoutLite] Flush error: ' . $e->getMessage());
            return false;
        }
    }
}
