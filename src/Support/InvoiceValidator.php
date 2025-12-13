<?php

namespace Meita\Zatca\Support;

/**
 * Class InvoiceValidator
 *
 * Provides a simple mechanism to validate invoice input data before it
 * is passed to the Invoice class for XML generation. This helps
 * centralise validation logic and ensures that required fields are
 * present and properly formatted. The validator can be extended or
 * customised to suit different business rules or invoice types.
 */
class InvoiceValidator
{
    /**
     * Validate the given invoice data for the specified type.
     *
     * The method returns an array of missing field names. If the array
     * is empty, the data contains all required fields and can be
     * considered valid. You can customise the required fields by
     * extending this class or passing your own list into the method.
     *
     * @param array  $data Invoice data as associative array
     * @param string $type Invoice type: simplified or standard
     * @param array  $customRequired Optional list of required fields
     * @return array List of missing or invalid fields
     */
    public static function validate(array $data, string $type = 'simplified', array $customRequired = []): array
    {
        // Base required fields for all invoice types
        $required = [
            'seller_name',
            'seller_vat',
            'invoice_total',
            'vat_total',
            'items',
            'issue_date',
        ];
        // Additional fields for standard invoices (e.g., B2B)
        if (strtolower($type) === 'standard') {
            $required[] = 'buyer_name';
            $required[] = 'buyer_vat';
        }
        // Merge with custom required fields if provided
        if (!empty($customRequired)) {
            $required = array_unique(array_merge($required, $customRequired));
        }
        $missing = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $missing[] = $field;
            }
        }
        // Ensure items is a non‑empty array
        if (array_key_exists('items', $data)) {
            if (!is_array($data['items']) || count($data['items']) === 0) {
                if (!in_array('items', $missing, true)) {
                    $missing[] = 'items';
                }
            }
        }
        return $missing;
    }
}