<?php

namespace Meita\Zatca\Support;

use Exception;

/**
 * Class ZatcaException
 *
 * Represents an exception thrown when the ZATCA API returns an error.
 * It contains the error category and code returned by the platform.
 */
class ZatcaException extends Exception
{
    /**
     * Error category returned by ZATCA (e.g. BR_ERROR, BR_KSA_ERROR).
     *
     * @var string|null
     */
    protected ?string $category;

    /**
     * Error code returned by ZATCA (e.g. BR-CO-18, BR-KSA-03).
     *
     * @var string|null
     */
    protected ?string $code;

    /**
     * ZatcaException constructor.
     *
     * @param string      $message  Error message
     * @param string|null $code     Error code
     * @param string|null $category Error category
     */
    public function __construct(string $message, ?string $code = null, ?string $category = null)
    {
        parent::__construct($message);
        $this->code     = $code;
        $this->category = $category;
    }

    /**
     * Get the error category.
     *
     * @return string|null
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * Get the error code.
     *
     * @return string|null
     */
    public function getZatcaCode(): ?string
    {
        return $this->code;
    }
}