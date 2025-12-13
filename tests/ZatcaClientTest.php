<?php

namespace Meita\Zatca\Tests;

use PHPUnit\Framework\TestCase;
use Meita\Zatca\ZatcaClient;
use Meita\Zatca\Invoice;

/**
 * Class ZatcaClientTest
 *
 * Tests the high level client for preparing signed invoices. These
 * tests focus on verifying that the client produces valid output and
 * embeds signatures and QR codes into the XML without attempting
 * actual network calls.
 */
class ZatcaClientTest extends TestCase
{
    /**
     * Test that prepareSignedInvoice returns all expected keys and that
     * the returned XML contains a signature and QR code reference.
     */
    public function testPrepareSignedInvoice(): void
    {
        // Generate a temporary EC key pair for signing
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privPem);
        $details = openssl_pkey_get_details($res);
        $pubPem = $details['key'];
        // Write private key to a temporary file
        $privPath = tempnam(sys_get_temp_dir(), 'zatcakey');
        file_put_contents($privPath, $privPem);
        // Write certificate/public key to a temp file (optional)
        $certPath = tempnam(sys_get_temp_dir(), 'zatcacert');
        file_put_contents($certPath, $pubPem);
        // Prepare client
        $client = new ZatcaClient([
            'base_url'         => 'https://api.example.com',
            'client_id'        => 'dummy_id',
            'secret'           => 'dummy_secret',
            'private_key_path' => $privPath,
            'certificate_path' => $certPath,
        ]);
        // Create a simple invoice
        $invoice = new Invoice([
            'seller_name' => 'Test Seller',
            'seller_vat'  => '1234567890',
            'invoice_total' => 230.00,
            'vat_total'   => 30.00,
            'items' => [
                ['name' => 'Product', 'quantity' => 2, 'price' => 100.00],
            ],
        ]);
        $result = $client->prepareSignedInvoice($invoice);
        // Validate keys exist
        $this->assertArrayHasKey('xml', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('qr', $result);
        $this->assertArrayHasKey('publicKey', $result);
        // Basic format checks
        $this->assertNotEmpty($result['hash']);
        $this->assertNotEmpty($result['signature']);
        $this->assertNotEmpty($result['qr']);
        $this->assertNotEmpty($result['publicKey']);
        // Ensure the XML contains our signature element and QR reference
        $this->assertStringContainsString('<Signature>', $result['xml']);
        $this->assertStringContainsString('QR', $result['xml']);
        // Decode QR to ensure base64 is valid
        $qrDecoded = base64_decode($result['qr'], true);
        $this->assertNotFalse($qrDecoded);
        // Cleanup temp files
        unlink($privPath);
        unlink($certPath);
    }
}