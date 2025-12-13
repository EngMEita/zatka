<?php

namespace Meita\Zatca;

use Exception;
use Meita\Zatca\Utilities\Signer;
use Meita\Zatca\Utilities\QrCodeGenerator;
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

    /** @var array|null Endpoints for clearance and reporting */
    protected ?array $endpoints = null;

    /** @var string Default invoice type to use when none provided */
    protected string $invoiceType = 'simplified';

    /** @var array Allowed invoice types for this client */
    protected array $allowedInvoiceTypes = ['simplified', 'standard'];

    /**
     * Optional callback invoked when the ZATCA API returns errors. If set,
     * this callback will receive the entire array of errors before a
     * ZatcaException is thrown. It can be used to log, transform or
     * otherwise handle the error list.
     *
     * @var callable|null
     */
    protected $errorCallback = null;

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
    public function __construct($config)
    {
        // If a configuration object is provided, extract company settings
        if ($config instanceof \Meita\Zatca\Support\ZatcaConfig) {
            $company = $config->all();
            // Ensure required values exist
            foreach (['client_id', 'client_secret', 'private_key_path'] as $key) {
                if (empty($company[$key])) {
                    throw new Exception("Missing configuration option: {$key}");
                }
            }
            $this->clientId = $company['client_id'];
            $this->secret   = $company['client_secret'];
            $this->privateKeyPath = $company['private_key_path'];
            $this->certificatePath = $company['certificate_path'] ?? null;
            // Determine environment and endpoints
            $env = $company['environment'] ?? 'sandbox';
            if (isset($company['endpoints'][$env])) {
                $this->endpoints = $company['endpoints'][$env];
            }
            // Default invoice type and allowed types
            $this->invoiceType = strtolower($company['invoice_type'] ?? 'simplified');
            $this->allowedInvoiceTypes = array_map('strtolower', $company['invoice_types'] ?? ['simplified', 'standard']);
            // Fallback baseUrl for backwards compatibility
            if ($this->endpoints && isset($this->endpoints['clearance'])) {
                // remove trailing /invoices/clearance
                $parts = explode('/invoices', $this->endpoints['clearance']);
                $this->baseUrl = rtrim($parts[0] ?? $this->endpoints['clearance'], '/');
            }
        } else {
            // Assume array configuration as before
            if (!is_array($config)) {
                throw new Exception('Configuration must be an array or an instance of ZatcaConfig');
            }
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
            // Build endpoints based on baseUrl
            $this->endpoints = [
                'clearance' => $this->baseUrl . '/invoices/clearance',
                'reporting' => $this->baseUrl . '/invoices/reporting',
            ];
            $this->invoiceType = strtolower($config['invoice_type'] ?? 'simplified');
            $this->allowedInvoiceTypes = array_map('strtolower', $config['invoice_types'] ?? ['simplified', 'standard']);
        }

        // Allow a custom error callback in configuration (pure PHP usage). It
        // must be callable; otherwise it is ignored. This is not supported
        // through Laravel config as callbacks cannot be serialized.
        if (is_array($config) && isset($config['on_error']) && is_callable($config['on_error'])) {
            $this->errorCallback = $config['on_error'];
        }
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
        // Auto-calculate totals if invoice_total or vat_total missing or zero
        $invoiceTotal = $invoice->get('invoice_total');
        $vatTotal = $invoice->get('vat_total');
        if (empty($invoiceTotal) || empty($vatTotal)) {
            // Compute totals from items using helper function
            if (function_exists('Meita\Zatca\Support\calculateTotals')) {
                $items = $invoice->get('items') ?? [];
                $totals = \Meita\Zatca\Support\calculateTotals($items);
                // update invoice data (via reflection of protected property)
                // We can't modify protected property directly; use reflection
                $ref = new \ReflectionClass($invoice);
                $prop = $ref->getProperty('data');
                $prop->setAccessible(true);
                $data = $prop->getValue($invoice);
                $data['invoice_total'] = $totals['gross_total'];
                $data['vat_total'] = $totals['vat_total'];
                $prop->setValue($invoice, $data);
            }
        }
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
        // Determine previous hash and invoice counter from invoice data if available
        $previousHash = $invoice->get('previous_hash');
        // Determine invoice counter value – if provided in data or default to 1
        $icv = $invoice->get('invoice_counter');
        // Inject signature, QR, previous hash and counter value into XML
        $signedXml = $this->insertSignatureAndQr($xml, $signature, $qr, $previousHash, $icv);
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
    protected function insertSignatureAndQr(string $xml, string $signature, string $qr, ?string $previousHash = null, ?string $counterValue = null): string
    {
        /**
         * Injects cryptographic stamp and auxiliary references into the invoice XML.
         *
         * ZATCA requires three AdditionalDocumentReference blocks: one for the
         * Invoice Counter Value (ICV), one for the Previous Invoice Hash (PIH)
         * and one for the QR Code【896391609891209†L2707-L2836】. Each must use the
         * correct ID (ICV, PIH, QR) and the embedded binary object must use the
         * MIME type "text/plain"【896391609891209†L2707-L2836】. See BR‑KSA‑CL‑03 for
         * details.
         */
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->loadXML($xml);
        $root = $doc->documentElement;

        // 1. Add simple Signature element with base64 signature value
        $signatureEl = $doc->createElement('Signature');
        $signatureValue = $doc->createElement('SignatureValue', $signature);
        $signatureEl->appendChild($signatureValue);
        $root->appendChild($signatureEl);

        // Determine defaults for previous hash and invoice counter value
        // The previous invoice hash must be base64 encoded SHA256 of the previous
        // invoice. If none provided, use the hash of "0" as per specification【896391609891209†L2765-L2773】.
        $prevHash = $previousHash;
        if (empty($prevHash)) {
            // Base64 encoded SHA256 hash of "0"
            $prevHash = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
        }
        $icvValue = $counterValue;
        if (empty($icvValue)) {
            // Default invoice counter value is 1. Must be numeric (digits only)
            $icvValue = '1';
        }

        // 2. Add AdditionalDocumentReference for Invoice Counter Value (ICV)
        $icvRef = $doc->createElement('cac:AdditionalDocumentReference');
        $icvRef->appendChild($doc->createElement('cbc:ID', 'ICV'));
        $icvRef->appendChild($doc->createElement('cbc:UUID', $icvValue));
        $root->appendChild($icvRef);

        // 3. Add AdditionalDocumentReference for Previous Invoice Hash (PIH)
        $pihRef = $doc->createElement('cac:AdditionalDocumentReference');
        $pihRef->appendChild($doc->createElement('cbc:ID', 'PIH'));
        $pihAttachment = $doc->createElement('cac:Attachment');
        $pihEmbedded = $doc->createElement('cbc:EmbeddedDocumentBinaryObject', $prevHash);
        $pihEmbedded->setAttribute('mimeCode', 'text/plain');
        // Do not specify encoding for PIH per spec – value is already base64
        $pihAttachment->appendChild($pihEmbedded);
        $pihRef->appendChild($pihAttachment);
        $root->appendChild($pihRef);

        // 4. Add AdditionalDocumentReference for QR Code (QR)
        $qrRef = $doc->createElement('cac:AdditionalDocumentReference');
        $qrRef->appendChild($doc->createElement('cbc:ID', 'QR'));
        $qrAttachment = $doc->createElement('cac:Attachment');
        $qrEmbedded = $doc->createElement('cbc:EmbeddedDocumentBinaryObject', $qr);
        // Use text/plain as required by ZATCA【896391609891209†L2707-L2836】
        $qrEmbedded->setAttribute('mimeCode', 'text/plain');
        $qrEmbedded->setAttribute('encodingCode', 'Base64');
        $qrAttachment->appendChild($qrEmbedded);
        $qrRef->appendChild($qrAttachment);
        $root->appendChild($qrRef);

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
            // Determine endpoint; fallback to baseUrl if endpoints not defined
            $endpoint = $this->endpoints['clearance'] ?? ($this->baseUrl . '/invoices/clearance');
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
            $endpoint = $this->endpoints['reporting'] ?? ($this->baseUrl . '/invoices/reporting');
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
     * Send an invoice selecting the appropriate API based on type. If no
     * type is provided, the client's default invoice type will be used.
     *
     * @param Invoice $invoice
     * @param string|null $type "simplified" or "standard"
     * @return array
     */
    public function sendInvoice(Invoice $invoice, ?string $type = null): array
    {
        $type = strtolower($type ?? $this->invoiceType);
        if ($type === 'simplified') {
            return $this->sendSimplifiedInvoice($invoice);
        }
        return $this->sendStandardInvoice($invoice);
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
        // If decoding fails, return raw body as error
        if ($data === null) {
            return [
                'status'    => 'error',
                'errors'    => [$responseBody],
                'http_code' => $httpCode,
            ];
        }

        // If HTTP code indicates success, still check for errors key
        if ($httpCode >= 200 && $httpCode < 300) {
            try {
                // Will throw ZatcaException if errors key is present
                \Meita\Zatca\Support\ZatcaErrorHandler::handle($data, $this->errorCallback);
            } catch (\Meita\Zatca\Support\ZatcaException $ex) {
                return [
                    'status' => 'error',
                    'errors' => [
                        [
                            'category' => $ex->getCategory(),
                            'code'     => $ex->getZatcaCode(),
                            'message'  => $ex->getMessage(),
                        ],
                    ],
                    'http_code' => $httpCode,
                ];
            }
            return [
                'status'   => 'success',
                'response' => $data,
            ];
        }

        // HTTP error codes. Attempt to handle errors key and throw exception
        try {
            \Meita\Zatca\Support\ZatcaErrorHandler::handle($data, $this->errorCallback);
            // If no errors key present, treat as unknown error
            return [
                'status'    => 'error',
                'errors'    => [$responseBody],
                'http_code' => $httpCode,
            ];
        } catch (\Meita\Zatca\Support\ZatcaException $ex) {
            return [
                'status' => 'error',
                'errors' => [
                    [
                        'category' => $ex->getCategory(),
                        'code'     => $ex->getZatcaCode(),
                        'message'  => $ex->getMessage(),
                    ],
                ],
                'http_code' => $httpCode,
            ];
        }
    }

    /**
     * Set a callback to be invoked when the ZATCA API returns errors. The
     * callback will receive an array of error objects. Calling this
     * method overrides any callback specified in the configuration array.
     *
     * @param callable $callback
     * @return $this
     */
    public function setErrorCallback(callable $callback): self
    {
        $this->errorCallback = $callback;
        return $this;
    }
}