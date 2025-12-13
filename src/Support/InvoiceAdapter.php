<?php

namespace Meita\Zatca\Support;

/**
 * Class InvoiceAdapter
 *
 * Provides a simple mechanism to normalise incoming invoice data from
 * various sources. Different systems may use different key names for
 * standard invoice fields (e.g. `sellerName` instead of `seller_name`).
 * This adapter maps alternative keys to the canonical keys expected by
 * the Invoice class. You can provide your own mapping to customise
 * behaviour or override defaults.
 */
class InvoiceAdapter
{
    /**
     * Adapt the given input array into the canonical invoice format.
     *
     * The adapter applies a mapping from alternative keys to the
     * canonical keys used by the Invoice class. Any keys that are not
     * present in the mapping are copied as‑is. You can override or
     * extend the default mapping by passing the `$map` parameter.
     *
     * @param array $input The raw invoice data from an external source
     * @param array $map   Optional mapping of input keys to canonical keys
     * @return array The normalised invoice data
     */
    public static function adapt(array $input, array $map = []): array
    {
        // Define a default mapping of common alternative keys to our
        // canonical invoice keys. These cover typical variations.
        $defaultMap = [
            'sellerName'    => 'seller_name',
            'seller_vat_no' => 'seller_vat',
            'vatNumber'     => 'seller_vat',
            'total'         => 'invoice_total',
            'total_amount'  => 'invoice_total',
            'vatTotal'      => 'vat_total',
            'tax_total'     => 'vat_total',
            'invoiceDate'   => 'issue_date',
            'issueDate'     => 'issue_date',
            'invoice_type'  => 'invoice_type',
        ];
        // Merge custom mapping with defaults (custom overrides defaults)
        $mapping = array_merge($defaultMap, $map);
        $result  = [];
        foreach ($input as $key => $value) {
            // If the key exists in our mapping, map it
            if (isset($mapping[$key])) {
                $result[$mapping[$key]] = $value;
            } else {
                // Otherwise copy as is
                $result[$key] = $value;
            }
        }
        return $result;
    }
}