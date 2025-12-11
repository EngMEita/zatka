<?php
class ZatcaConfig
{
    protected array $config;

    public function __construct(?string $company = null)
    {
        $config = $this->loadConfig();

        $this->config =
            $config['companies'][$company ?? $config['default']]
            ?? throw new Exception("Company config not found.");
    }

    protected function loadConfig(): array
    {
        // Laravel
        if (function_exists('config')) {
            return config('zatca');
        }

        // Pure PHP
        return require __DIR__ . '/../../config/zatca.php';
    }

    public function get(string $key)
    {
        return $this->config[$key] ?? null;
    }

    public function all(): array
    {
        return $this->config;
    }
}
