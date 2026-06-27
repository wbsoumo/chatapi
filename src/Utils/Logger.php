<?php

namespace App\Utils;

class Logger {
    private static ?string $logFile = null;

    private static function getLogFile(): string {
        if (self::$logFile === null) {
            $logDir = dirname(__DIR__, 2) . '/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/app.log';
        }
        return self::$logFile;
    }

    public static function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $logMessage = "[$timestamp] [$level] $message$contextStr\n";
        
        file_put_contents(self::getLogFile(), $logMessage, FILE_APPEND);
    }

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }
}
