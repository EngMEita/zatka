<?php

namespace Meita\Zatca\Utilities;

use DOMDocument;
use DOMXPath;
use Exception;

/**
 * Class Signer
 *
 * Contains static helpers for hashing and signing invoices according to
 * ZATCA specifications. It uses XML canonicalization (C14N11) and SHA‑256
 * hashing, along with ECDSA digital signatures.
 */
class Signer
{
    /**
     * Generate a SHA‑256 hash of the invoice XML after removing UBLExtensions,
     * Signature and QR code elements. Returns the hash in hexadecimal format.
     *
     * @param string $xml Invoice XML string
     * @return string Hexadecimal SHA‑256 hash
     * @throws Exception
     */
    public static function hashInvoice(string $xml): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        if (!$doc->loadXML($xml)) {
            throw new Exception('Failed to load invoice XML for hashing');
        }

        $xpath = new DOMXPath($doc);
        // Register namespaces used in invoice. If missing, ignore
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        // Remove UBLExtensions completely
        foreach ($xpath->query('//*[local-name() = "UBLExtensions"]') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Remove any Signature element
        foreach ($xpath->query('//*[local-name() = "Signature"]') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Remove AdditionalDocumentReference elements where cbc:ID = 'QR'
        foreach ($xpath->query('//*[local-name() = "AdditionalDocumentReference"]') as $ref) {
            $idNode = $xpath->query('cbc:ID', $ref)->item(0);
            if ($idNode && trim($idNode->nodeValue) === 'QR') {
                $ref->parentNode->removeChild($ref);
            }
        }

        // Canonicalize the document using C14N11
        // Perform exclusive canonicalization without comments. We don't
        // provide xpath or namespace lists here; passing null will canonicalize
        // the full document.
        $canonical = $doc->C14N(true, false) ?: '';

        return hash('sha256', $canonical);
    }

    /**
     * Sign a hash using a private key. Returns the signature encoded in base64.
     *
     * @param string $hash Hexadecimal hash of the invoice
     * @param string $privateKeyPath Path to PEM encoded private key
     * @return string Base64 encoded signature
     * @throws Exception
     */
    public static function signHash(string $hash, string $privateKeyPath): string
    {
        $privateKeyPem = file_get_contents($privateKeyPath);
        if (!$privateKeyPem) {
            throw new Exception('Unable to read private key file');
        }
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new Exception('Invalid private key');
        }
        // Convert hex hash to binary
        $binaryHash = hex2bin($hash);
        $signature = '';
        $success = openssl_sign($binaryHash, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);
        if (!$success) {
            throw new Exception('Unable to sign invoice hash');
        }
        return base64_encode($signature);
    }

    /**
     * Extract and return the public key from a certificate or private key file
     * encoded in PEM. Returns the key in PEM format.
     *
     * @param string $path Path to certificate or private key
     * @return string
     * @throws Exception
     */
    public static function getPublicKey(string $path): string
    {
        $pem = file_get_contents($path);
        if (!$pem) {
            throw new Exception('Unable to read key file');
        }
        $resource = openssl_pkey_get_public($pem);
        if (!$resource) {
            // Try to derive from private key
            $resource = openssl_pkey_get_private($pem);
        }
        if (!$resource) {
            throw new Exception('Invalid key/certificate');
        }
        $keyDetails = openssl_pkey_get_details($resource);
        openssl_free_key($resource);
        if (!isset($keyDetails['key'])) {
            throw new Exception('Unable to extract public key');
        }
        return $keyDetails['key'];
    }
}