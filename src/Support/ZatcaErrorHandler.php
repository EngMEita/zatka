<?php

namespace Meita\Zatca\Support;

/**
 * Class ZatcaErrorHandler
 *
 * Provides a helper to interpret responses returned by the ZATCA API. When
 * the API returns errors, this class can convert them into ZatcaException
 * instances and optionally invoke a user‑supplied callback. Using this
 * handler is optional but recommended to provide consistent error
 * handling throughout your application.
 */
class ZatcaErrorHandler
{
    /**
     * Handle a response array. If the response contains errors it will
     * optionally call the provided callback and throw a ZatcaException for
     * the first error. The callback can be used to log or transform
     * errors before they are thrown.
     *
     * @param array         $response The decoded API response (array)
     * @param callable|null $callback Optional callback invoked with the
     *                                array of error details
     * @throws ZatcaException
     *
     * @return array The response array if no errors are present
     */
    public static function handle(array $response, ?callable $callback = null): array
    {
        // If the response contains an "errors" key and it is non‑empty
        if (isset($response['errors']) && is_array($response['errors']) && !empty($response['errors'])) {
            // Invoke callback with the entire list of errors
            if ($callback !== null) {
                $callback($response['errors']);
            }
            // Use the first error to build the exception. ZATCA typically
            // returns an array of error objects with keys "category",
            // "code" and "message".
            $first = $response['errors'][0];
            $message  = is_array($first) && isset($first['message']) ? $first['message'] : 'Unknown error';
            $code     = is_array($first) && isset($first['code']) ? $first['code'] : null;
            $category = is_array($first) && isset($first['category']) ? $first['category'] : null;
            throw new ZatcaException($message, $code, $category);
        }
        // If no errors key, the response is considered successful
        return $response;
    }
}