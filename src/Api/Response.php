<?php

namespace ShipPHP\Api;

/**
 * HTTP Response Builder
 * Builds JSON API responses
 */
class Response
{
    private $statusCode = 200;
    private $headers = [];
    private $data = [];

    public function __construct()
    {
        $this->headers = [
            'Content-Type' => 'application/json',
            'X-ShipPHP-Version' => SHIPPHP_VERSION ?? '2.1.1',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With'
        ];
    }

    /**
     * Set status code
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Set header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Send success response
     */
    public function success($data = [], string $message = 'Success'): void
    {
        $this->send([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * Send error response
     */
    public function error(string $message, int $code = 400, $data = []): void
    {
        $this->statusCode = $code;
        $this->send([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'data' => $data
        ]);
    }

    /**
     * Send JSON response
     */
    public function json($data): void
    {
        $this->send($data);
    }

    /**
     * Send the response
     */
    private function send($data): void
    {
        // Set status code
        http_response_code($this->statusCode);

        // Set headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Send JSON
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Handle CORS preflight
     */
    public function handleCors(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->status(204);
            $this->send([]);
        }
    }
}
