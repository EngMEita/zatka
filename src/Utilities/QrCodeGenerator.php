<?php

namespace Meita\Zatca\Utilities;

/**
 * Class QrCodeGenerator
 *
 * Generates a QR code payload (Base64 encoded) according to the Tag‑Length‑Value
 * specification defined by ZATCA. This implementation does not generate the
 * actual QR code image, but instead returns the encoded payload which can be
 * converted to an image by external libraries.
 */
class QrCodeGenerator
{
    /**
     * Build a TLV-encoded byte string from tag/value pairs. Each tag is a
     * one-byte integer, the length is one byte representing the length of the
     * UTF‑8 encoded value, and the value is the UTF‑8 encoded string. For hash
     * values and signatures which are longer than 255 bytes, the length field
     * remains one byte and truncation may occur; ZATCA specification limits
     * the QR code to 700 characters, so make sure your inputs respect this.
     *
     * @param array $fields Associative array where keys are integer tags and
     *                      values are strings
     * @return string Binary representation of TLV-encoded data
     */
    protected static function buildTlv(array $fields): string
    {
        $binary = '';
        foreach ($fields as $tag => $value) {
            // Convert value to UTF-8 bytes
            $bytes = mb_convert_encoding($value, 'UTF-8');
            $len = strlen($bytes);
            if ($len > 255) {
                // Truncate values longer than 255 bytes
                $bytes = substr($bytes, 0, 255);
                $len = 255;
            }
            // Append tag (1 byte), length (1 byte), and value
            $binary .= pack('C', (int)$tag) . pack('C', $len) . $bytes;
        }
        return $binary;
    }

    /**
     * Generate a Base64 encoded QR payload according to ZATCA fields.
     *
     * The fields expected are:
     * 1 => seller name
     * 2 => seller VAT number
     * 3 => invoice timestamp (ISO 8601)
     * 4 => invoice total (with VAT)
     * 5 => VAT total
     * 6 => invoice hash (hexadecimal)
     * 7 => digital signature (base64)
     * 8 => public key (PEM without headers, optional)
     *
     * @param array $fields
     * @return string Base64-encoded TLV string
     */
    public static function generate(array $fields): string
    {
        $binary = self::buildTlv($fields);
        return base64_encode($binary);
    }
}