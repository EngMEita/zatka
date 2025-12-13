<?php

namespace Meita\Zatca\Support;

use Exception;

/**
 * Class ZatcaConfig
 *
 * Loads and provides access to the ZATCA configuration for a single company.
 * It supports both Laravel and pure PHP usage. In a Laravel application
 * configuration will be loaded using the global config helper. In a
 * standalone PHP environment it will attempt to load the configuration
 * directly from the package's config directory.
 */
class ZatcaConfig
{
    /**
     * The configuration array for the selected company.
     *
     * @var array
     */
    protected array $companyConfig;

    /**
     * ZatcaConfig constructor.
     *
     * @param string|null $companyId The company identifier. If null, the
     *                               default company configured will be used.
     * @throws Exception
     */
    public function __construct(?string $companyId = null)
    {
        $config = $this->loadConfig();
        $companyKey = $companyId ?: ($config['default'] ?? null);
        if (!$companyKey || !isset($config['companies'][$companyKey])) {
            throw new Exception("Zatca company configuration [{$companyKey}] not found.");
        }
        $this->companyConfig = $config['companies'][$companyKey];
    }

    /**
     * Load the base configuration array.
     *
     * This method first attempts to use Laravel's config helper if available.
     * If running in a pure PHP context, it will fallback to require the
     * configuration file directly. As a last resort it returns an empty
     * structure.
     *
     * @return array
     */
    protected function loadConfig(): array
    {
        // Use the Laravel configuration system if available
        if (function_exists('config')) {
            $laravelConfig = config('zatca');
            if (is_array($laravelConfig)) {
                return $laravelConfig;
            }
        }

        // Attempt to load configuration from application's config directory
        $localPath = __DIR__ . '/../../config/zatca.php';
        if (file_exists($localPath)) {
            return require $localPath;
        }

        // Fallback to package stub configuration
        $packageStub = __DIR__ . '/../config/zatca.php';
        if (file_exists($packageStub)) {
            return require $packageStub;
        }

        return ['default' => null, 'companies' => []];
    }

    /**
     * Get a configuration value for the selected company.
     *
     * @param string $key The key to retrieve
     * @param mixed $default Default value to return if not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->companyConfig[$key] ?? $default;
    }

    /**
     * Return the entire configuration array for the selected company.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->companyConfig;
    }
}