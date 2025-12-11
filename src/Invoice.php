<?php

namespace Zatca;

use DOMDocument;
use Exception;

/**
 * Class Invoice
 *
 * Represents a single electronic invoice. It stores the necessary data fields
 * such as UUID, timestamps, seller information and line items, and provides
 * functionality to convert that data into a simple UBL XML representation.
 */
class Invoice
{
    /** @var array */
    protected $data = [];

    /**
     * Invoice constructor.
     *
     * @param array $data Initial invoice data. Supported keys include:
     *  - uuid: unique identifier (string)
     *  - issue_date: ISO8601 date/time (string)
     *  - seller_name: seller legal name (string)
     *  - seller_vat: seller VAT registration number (string)
     *  - invoice_total: total amount with VAT (float)
     *  - vat_total: VAT amount (float)
     *  - previous_hash: hash of the previous invoice (string|null)
     *  - items: array of line items (each item must have name, quantity, price)
     */
    public function __construct(array $data = [])
    {
        $defaults = [
            'uuid' => self::generateUuid(),
            'issue_date' => date('c'),
            'seller_name' => '',
            'seller_vat' => '',
            'invoice_total' => 0.0,
            'vat_total' => 0.0,
            'previous_hash' => null,
            'items' => [],
        ];
        $this->data = array_merge($defaults, $data);
    }

    /**
     * Generate a UUID v4.
     *
     * @return string
     */
    public static function generateUuid(): string
    {
        // Generate 16 random bytes and set variant/version bits
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Set the previous invoice hash. Needed to link invoices in a chain.
     *
     * @param string|null $hash
     * @return void
     */
    public function setPreviousInvoiceHash(?string $hash): void
    {
        $this->data['previous_hash'] = $hash;
    }

    /**
     * Get a value from the invoice data.
     *
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Convert invoice data into an XML string conforming to a simplified UBL
     * structure. This function can be extended to include additional elements
     * according to the official data dictionary.
     *
     * @return string
     * @throws Exception
     */
    public function toXml(): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;

        // Root element <Invoice>
        $invoice = $doc->createElement('Invoice');
        $invoice->setAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $invoice->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $invoice->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // UBL version
        $version = $doc->createElement('cbc:UBLVersionID', '2.1');
        $invoice->appendChild($version);

        // Customization ID (ZATCA specification for invoices)
        $invoice->appendChild($doc->createElement('cbc:CustomizationID', 'urn:ubl:specification:standard:1.0')); // placeholder

        // UUID
        $invoice->appendChild($doc->createElement('cbc:ID', $this->data['uuid']));

        // Issue date
        $invoice->appendChild($doc->createElement('cbc:IssueDate', substr($this->data['issue_date'], 0, 10)));
        $invoice->appendChild($doc->createElement('cbc:IssueTime', substr($this->data['issue_date'], 11)));

        // Seller (Accounting Supplier Party)
        $accountingSupplierParty = $doc->createElement('cac:AccountingSupplierParty');
        $party = $doc->createElement('cac:Party');
        $partyName = $doc->createElement('cac:PartyName');
        $partyName->appendChild($doc->createElement('cbc:Name', $this->data['seller_name']));
        $party->appendChild($partyName);
        $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
        $partyTaxScheme->appendChild($doc->createElement('cbc:CompanyID', $this->data['seller_vat']));
        $party->appendChild($partyTaxScheme);
        $accountingSupplierParty->appendChild($party);
        $invoice->appendChild($accountingSupplierParty);

        // Monetary totals
        $legalMonetaryTotal = $doc->createElement('cac:LegalMonetaryTotal');
        $legalMonetaryTotal->appendChild($doc->createElement('cbc:TaxInclusiveAmount', number_format($this->data['invoice_total'], 2, '.', '')));
        $legalMonetaryTotal->appendChild($doc->createElement('cbc:TaxExclusiveAmount', number_format($this->data['invoice_total'] - $this->data['vat_total'], 2, '.', '')));
        $legalMonetaryTotal->appendChild($doc->createElement('cbc:TaxAmount', number_format($this->data['vat_total'], 2, '.', '')));
        $invoice->appendChild($legalMonetaryTotal);

        // Line items
        foreach ($this->data['items'] as $item) {
            $invoiceLine = $doc->createElement('cac:InvoiceLine');
            $invoiceLine->appendChild($doc->createElement('cbc:ID', '1'));
            $invoiceLine->appendChild($doc->createElement('cbc:InvoicedQuantity', $item['quantity']));
            $invoiceLine->appendChild($doc->createElement('cbc:LineExtensionAmount', number_format($item['price'] * $item['quantity'], 2, '.', '')));
            $itemElement = $doc->createElement('cac:Item');
            $itemElement->appendChild($doc->createElement('cbc:Name', $item['name']));
            $invoiceLine->appendChild($itemElement);
            $invoice->appendChild($invoiceLine);
        }

        $doc->appendChild($invoice);
        return $doc->saveXML();
    }
}