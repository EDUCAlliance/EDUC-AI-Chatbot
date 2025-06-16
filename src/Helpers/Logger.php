<?php

declare(strict_types=1);

namespace NextcloudBot\Helpers;

class Logger
{
    private string $logDirectory;

    public function __construct()
    {
        $this->logDirectory = APP_ROOT . '/logs/';
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0755, true);
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);
        $logFile = $this->logDirectory . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        
        $logEntry = "[{$timestamp}] [{$level}] {$message}";
        if (!empty($context)) {
            $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $logEntry .= PHP_EOL;

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
} 