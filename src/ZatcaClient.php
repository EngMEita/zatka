<?php

namespace Zatca;

use Exception;
use Zatca\Utilities\Signer;
use Zatca\Utilities\QrCodeGenerator;
use DOMDocument;
use DOMXPath;

/**
 * Class ZatcaClient
 *
 * Provides high level methods to prepare, sign and send invoices to the
 * ZATCA e‑invoicing platform. Supports sending both standard (B2B) and
 * simplified (B2C) invoices.
 */
class ZatcaClient
{
    /** @var string */
    protected $baseUrl;
    /** @var string */
    protected $clientId;
    /** @var string */
    protected $secret;
    /** @var string */
    protected $privateKeyPath;
    /** @var string|null */
    protected $certificatePath;

    /**
     * ZatcaClient constructor.
     *
     * Accepts an array of configuration options:
     *  - base_url: base URL of ZATCA API
     *  - client_id: CSID (binarySecurityToken)
     *  - secret: secret associated with the CSID
     *  - private_key_path: path to PEM private key used for signing
     *  - certificate_path: optional path to public certificate to include in QR
     *
     * @param array $config
     * @throws Exception
     */
    public function __construct(array $config)
    {
        foreach (['base_url', 'client_id', 'secret', 'private_key_path'] as $key) {
            if (empty($config[$key])) {
                throw new Exception("Missing configuration option: {$key}");
            }
        }
        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->clientId = $config['client_id'];
        $this->secret   = $config['secret'];
        $this->privateKeyPath = $config['private_key_path'];
        $this->certificatePath = $config['certificate_path'] ?? null;
    }

    /**
     * Prepare and sign an invoice. Returns an array with signed XML, hash,
     * signature, QR code and public key. This can be used for both
     * clearance and reporting.
     *
     * @param Invoice $invoice
     * @return array
     * @throws Exception
     */
    public function prepareSignedInvoice(Invoice $invoice): array
    {
        // Generate XML from invoice data
        $xml = $invoice->toXml();
        // Compute hash (hex string)
        $hash = Signer::hashInvoice($xml);
        // Sign the hash with private key
        $signature = Signer::signHash($hash, $this->privateKeyPath);
        // Extract public key
        $publicKey = Signer::getPublicKey($this->certificatePath ?: $this->privateKeyPath);
        // Remove PEM headers and line breaks for the QR
        $publicKeyClean = trim(str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"], '', $publicKey));
        // Build QR code fields (Tags 1-7, 8 optional)
        $fields = [
            1 => $invoice->get('seller_name'),
            2 => $invoice->get('seller_vat'),
            3 => $invoice->get('issue_date'),
            4 => (string)$invoice->get('invoice_total'),
            5 => (string)$invoice->get('vat_total'),
            6 => $hash,
            7 => $signature,
        ];
        if ($publicKeyClean) {
            $fields[8] = $publicKeyClean;
        }
        $qr = QrCodeGenerator::generate($fields);
        // Inject signature and QR into XML
        $signedXml = $this->insertSignatureAndQr($xml, $signature, $qr);
        return [
            'xml'       => $signedXml,
            'hash'      => $hash,
            'signature' => $signature,
            'qr'        => $qr,
            'publicKey' => $publicKeyClean,
        ];
    }

    /**
     * Insert signature and QR code into an invoice XML. This method adds a
     * minimalistic Signature element and a AdditionalDocumentReference with ID
     * "QR". It may not fully conform to XAdES specification but serves as a
     * placeholder for demonstration purposes.
     *
     * @param string $xml
     * @param string $signature Base64 encoded signature
     * @param string $qr Base64 encoded TLV payload
     * @return string
     */
    protected function insertSignatureAndQr(string $xml, string $signature, string $qr): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        // Register namespaces
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $root = $doc->documentElement;
        // Add a simple Signature element
        $signatureEl = $doc->createElement('Signature');
        $signatureValue = $doc->createElement('SignatureValue', $signature);
        $signatureEl->appendChild($signatureValue);
        $root->appendChild($signatureEl);

        // Add AdditionalDocumentReference for QR code
        $additionalDocRef = $doc->createElement('cac:AdditionalDocumentReference');
        $id = $doc->createElement('cbc:ID', 'QR');
        $additionalDocRef->appendChild($id);
        $attachment = $doc->createElement('cac:Attachment');
        $embedded = $doc->createElement('cbc:EmbeddedDocumentBinaryObject', $qr);
        $embedded->setAttribute('mimeCode', 'application/octet-stream');
        $embedded->setAttribute('encodingCode', 'Base64');
        $attachment->appendChild($embedded);
        $additionalDocRef->appendChild($attachment);

        $root->appendChild($additionalDocRef);

        return $doc->saveXML();
    }

    /**
     * Send a standard invoice (B2B) for clearance. The invoice will be signed
     * and a QR code will be embedded before sending.
     *
     * @param Invoice $invoice
     * @return array
     */
    public function sendStandardInvoice(Invoice $invoice): array
    {
        try {
            $prepared = $this->prepareSignedInvoice($invoice);
            // Build request payload
            $body = [
                'invoiceHash'        => $prepared['hash'],
                'uuid'               => $invoice->get('uuid'),
                'previousInvoiceHash'=> $invoice->get('previous_hash'),
                'invoice'            => base64_encode($prepared['xml']),
            ];
            $endpoint = $this->baseUrl . '/invoices/clearance';
            $response = $this->sendRequest($endpoint, $body);
            return $response;
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Send a simplified invoice (B2C) for reporting. The invoice will be
     * prepared with a signature and QR code but the QR code generated by the
     * package remains intact (ZATCA does not stamp simplified invoices).
     *
     * @param Invoice $invoice
     * @return array
     */
    public function sendSimplifiedInvoice(Invoice $invoice): array
    {
        try {
            $prepared = $this->prepareSignedInvoice($invoice);
            $body = [
                'invoiceHash'        => $prepared['hash'],
                'uuid'               => $invoice->get('uuid'),
                'previousInvoiceHash'=> $invoice->get('previous_hash'),
                'invoice'            => base64_encode($prepared['xml']),
            ];
            $endpoint = $this->baseUrl . '/invoices/reporting';
            $response = $this->sendRequest($endpoint, $body);
            return $response;
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Execute an HTTP POST request using cURL. Uses Basic Auth with
     * client ID and secret. Returns an associative array with keys
     * `status` (success|error), and other fields depending on response.
     *
     * @param string $url
     * @param array $body
     * @return array
     */
    protected function sendRequest(string $url, array $body): array
    {
        $payload = json_encode($body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_USERPWD => $this->clientId . ':' . $this->secret,
        ]);
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return [
                'status' => 'error',
                'errors' => ['cURL error: ' . $curlError],
            ];
        }
        // Attempt to decode JSON
        $data = json_decode($responseBody, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'status' => 'success',
                'response' => $data,
                // Some APIs return the stamped invoice in Base64; include raw response
            ];
        }
        return [
            'status' => 'error',
            'errors' => $data['errors'] ?? [$responseBody],
            'http_code' => $httpCode,
        ];
    }
}