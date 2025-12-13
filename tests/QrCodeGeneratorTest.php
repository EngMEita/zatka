<?php

namespace Meita\Zatca\Tests;

use PHPUnit\Framework\TestCase;
use Meita\Zatca\Utilities\QrCodeGenerator;

/**
 * Class QrCodeGeneratorTest
 *
 * Tests the TLV encoding and base64 generation performed by the QR code
 * generator. Ensures that input fields are correctly serialized and
 * retrievable when decoded.
 */
class QrCodeGeneratorTest extends TestCase
{
    /**
     * Provide a set of fields and ensure that the TLV encoding can be
     * reversed to the original values.
     */
    public function testGenerateAndDecodeTlv(): void
    {
        $fields = [
            1 => 'Seller Name',
            2 => '1234567890',
            3 => '2025-12-11T10:00:00+03:00',
            4 => '115.00',
            5 => '15.00',
            6 => str_repeat('a', 64), // 64‑char hex hash
            7 => base64_encode('signature'),
        ];
        $encoded = QrCodeGenerator::generate($fields);
        $this->assertNotEmpty($encoded);
        $decodedBinary = base64_decode($encoded, true);
        $this->assertNotFalse($decodedBinary);
        // Parse TLV and reconstruct fields
        $index = 0;
        $result = [];
        $length = strlen($decodedBinary);
        while ($index + 2 <= $length) {
            $tag = ord($decodedBinary[$index]);
            $len = ord($decodedBinary[$index + 1]);
            $value = substr($decodedBinary, $index + 2, $len);
            $result[$tag] = $value;
            $index += 2 + $len;
        }
        // Compare first 5 fields directly
        for ($tag = 1; $tag <= 5; $tag++) {
            $this->assertEquals($fields[$tag], $result[$tag]);
        }
        // Field 6 may be truncated if >255 bytes but our sample is 64 bytes
        $this->assertEquals($fields[6], $result[6]);
        // Field 7 is base64 encoded, so its value should match encoded string
        $this->assertEquals($fields[7], $result[7]);
    }
}