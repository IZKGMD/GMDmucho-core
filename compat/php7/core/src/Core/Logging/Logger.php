<?php

declare (strict_types=1);
namespace MuchoCore\Core\Logging;

final class Logger
{
    public function __construct(string $file)
    {
        $this->file = $file;
    }
    public function info(string $message, array $context = [])
    {
        $this->write('INFO', $message, $context);
    }
    public function warning(string $message, array $context = [])
    {
        $this->write('WARNING', $message, $context);
    }
    public function error(string $message, array $context = [])
    {
        $this->write('ERROR', $message, $context);
    }
    private function write(string $level, string $message, array $context)
    {
        $record = ['time' => gmdate('c'), 'level' => $level, 'message' => $message, 'context' => $context];
        file_put_contents($this->file, \mucho7_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    private $file;
}
