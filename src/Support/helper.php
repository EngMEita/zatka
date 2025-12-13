<?php

namespace Meita\Zatca\Support;

/**
 * A set of helper functions for the ZATCA e‑invoice package.
 *
 * These functions provide common utilities such as generating UUIDs
 * and calculating invoice totals. They are loaded automatically via
 * Composer's autoload files directive.
 */

/**
 * Generate a UUID version 4.
 *
 * This helper can be used by applications to assign a unique
 * identifier to each invoice when one is not provided.
 *
 * @return string
 */
function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Calculate invoice totals (net, VAT and gross) from a list of items.
 *
 * Each item should be an associative array containing:
 *  - quantity: integer or float representing the number of units.
 *  - price: net unit price (exclusive of VAT). If missing, unit_price will be used.
 *  - vat_percent: VAT rate percentage for the item.
 *
 * The function returns an array with keys:
 *  - net_total: sum of net amounts (exclusive of VAT).
 *  - vat_total: sum of VAT amounts.
 *  - gross_total: net_total + vat_total.
 *
 * @param array $items
 * @return array<string,float>
 */
function calculateTotals(array $items): array
{
    $net = 0.0;
    $vat = 0.0;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? ($item['unit_price'] ?? 0));
        $vatPercent = (float)($item['vat_percent'] ?? 0);
        $lineNet = $price * $qty;
        $lineVat = $lineNet * ($vatPercent / 100);
        $net += $lineNet;
        $vat += $lineVat;
    }
    $net = round($net, 2);
    $vat = round($vat, 2);
    return [
        'net_total'   => $net,
        'vat_total'   => $vat,
        'gross_total' => round($net + $vat, 2),
    ];
}
