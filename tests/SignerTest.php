<?php

namespace Zatca\Tests;

use PHPUnit\Framework\TestCase;
use Zatca\Utilities\Signer;
use DOMDocument;

/**
 * Class SignerTest
 *
 * Contains unit tests for hashing and signing functionality provided
 * by the Signer utility. These tests ensure the hashing algorithm
 * produces deterministic results and that signatures created can be
 * verified with the corresponding public key.
 */
class SignerTest extends TestCase
{
    /**
     * Test that hashing an invoice produces the same digest as a manual
     * canonicalization followed by SHA‑256.
     */
    public function testHashInvoiceConsistency(): void
    {
        // Minimal invoice XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
             . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
             . '<cbc:ID>123</cbc:ID>'
             . '</Invoice>';
        // Hash using Signer helper
        $signerHash = Signer::hashInvoice($xml);
        // Manual canonicalization and hash
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML($xml);
        $canonical = $doc->C14N(true, false) ?: '';
        $manualHash = hash('sha256', $canonical);
        $this->assertEquals($manualHash, $signerHash);
        // Ensure it is a 64-character hex string
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signerHash);
    }

    /**
     * Test that signHash produces a base64 encoded signature that can
     * be verified with the corresponding public key.
     */
    public function testSignAndVerifyHash(): void
    {
        // Generate an EC key pair for testing
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ];
        $res = openssl_pkey_new($config);
        $this->assertIsResource($res);
        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];
        // Create a temporary file for the private key
        $tmpKeyPath = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($tmpKeyPath, $privateKeyPem);
        // Use a sample hash
        $hash = hash('sha256', 'abc123');
        // Sign
        $signatureBase64 = Signer::signHash($hash, $tmpKeyPath);
        $this->assertNotEmpty($signatureBase64);
        $signature = base64_decode($signatureBase64, true);
        // Verify signature using public key
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        $verify = openssl_verify(hex2bin($hash), $signature, $publicKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKey);
        unlink($tmpKeyPath);
        $this->assertEquals(1, $verify, 'Signature should verify correctly');
    }

    /**
     * Test that getPublicKey extracts the public key from either a
     * certificate or private key file.
     */
    public function testGetPublicKeyExtraction(): void
    {
        // Generate an RSA key pair for testing public key extraction
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];
        // Write keys to temp files
        $privFile = tempnam(sys_get_temp_dir(), 'priv');
        $pubFile = tempnam(sys_get_temp_dir(), 'pub');
        file_put_contents($privFile, $privateKeyPem);
        file_put_contents($pubFile, $publicKeyPem);
        // From private key
        $extractedFromPriv = Signer::getPublicKey($privFile);
        $this->assertStringContainsString('PUBLIC KEY', $extractedFromPriv);
        // From public key
        $extractedFromPub = Signer::getPublicKey($pubFile);
        $this->assertStringContainsString('PUBLIC KEY', $extractedFromPub);
        // Cleanup
        unlink($privFile);
        unlink($pubFile);
    }
}