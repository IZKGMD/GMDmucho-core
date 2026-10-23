<?php

declare (strict_types=1);
namespace MuchoCore\Http;

final class Response
{
    public function __construct(string $body, int $status = 200, string $contentType = 'text/plain; charset=utf-8')
    {
        $this->body = $body;
        $this->status = $status;
        $this->contentType = $contentType;
    }
    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }
    public static function json(array $data, int $status = 200): self
    {
        return new self(\mucho7_json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $status, 'application/json; charset=utf-8');
    }
    /**
     * Return a payload-safe Response snapshot for plugin lifecycle events.
     *
     * Response bodies may contain account, message or protocol data, so only
     * transport metadata is exposed through the plugin event contract.
     */
    public function forPluginEvent(): self
    {
        return new self('', $this->status, $this->contentType);
    }
    public function send()
    {
        // Clear output buffers, BOMs and accidental whitespace.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        echo $this->body;
        exit;
    }
    public $body;
    public $status;
    public $contentType;
}
