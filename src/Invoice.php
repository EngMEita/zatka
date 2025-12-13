<?php

namespace Meita\Zatca;

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
            // by default we only fill in the date portion; time should be supplied via issue_time
            'issue_date' => date('Y-m-d'),
            // separate issue time; default to current time in HH:MM:SS format
            'issue_time' => date('H:i:s'),
            'seller_name' => '',
            'seller_vat' => '',
            'invoice_total' => 0.0,
            'vat_total' => 0.0,
            'previous_hash' => null,
            // Sequential invoice counter value (KSA‑16). Default to 1.
            'invoice_counter' => '1',
            'invoice_number' => null,
            'items' => [],
            // Default invoice type (simplified or standard)
            'invoice_type' => 'simplified',
            // Default currency and tax settings (will be overridden by config or passed data)
            'currency' => 'SAR',
            'tax_percent' => 15,
            'tax_category_code' => 'S',
            // Default invoice transaction code (KSA‑2). 0100000 for tax invoice, 0200000 for simplified.
            'invoice_transaction_code' => null,
            // Profile execution ID (BT‑24). For ZATCA this must always be 1.0
            'profile_execution_id' => '1.0',
            'address' => [
                'street'      => '',
                'building_no' => '',
                'city'        => '',
                'postal_code' => '',
                'country'     => 'SA',
            ],
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

        // Determine invoice type and currency
        // Invoice type: "standard" (tax invoice) or "simplified" (simplified tax invoice).
        // According to the ZATCA specification the business process (BT‑23) must always be
        // "reporting:1.0"【896391609891209†L164-L171】, so we fix the ProfileID accordingly.
        $invoiceType = strtolower($this->data['invoice_type'] ?? 'simplified');
        // 388 = Tax Invoice, 389 = Simplified Tax Invoice
        $invoiceTypeCode = $invoiceType === 'standard' ? '388' : '389';
        // Use reporting:1.0 for both invoice types per BR‑KSA‑EN16931‑01
        $profileId = 'reporting:1.0';
        $currency = $this->data['currency'] ?? 'SAR';
        $taxPercent = $this->data['tax_percent'] ?? 15;
        $taxCategoryCode = $this->data['tax_category_code'] ?? 'S';
        $sellerAddress = $this->data['address'] ?? [
            'street' => '',
            'building_no' => '',
            'city' => '',
            'postal_code' => '',
            'country' => 'SA',
        ];

        // Root element <Invoice>
        $invoice = $doc->createElement('Invoice');
        $invoice->setAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $invoice->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $invoice->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // UBL version
        $invoice->appendChild($doc->createElement('cbc:UBLVersionID', '2.1'));
        // Customization ID (placeholder for now)
        $invoice->appendChild($doc->createElement('cbc:CustomizationID', 'urn:ubl:specification:standard:1.0'));
        // Business process / Profile ID (reporting:1.0) and Execution ID (always 1.0 for ZATCA)
        $invoice->appendChild($doc->createElement('cbc:ProfileID', $profileId));
        $invoice->appendChild($doc->createElement('cbc:ProfileExecutionID', $this->data['profile_execution_id']));
        // Invoice sequential number (ID) and UUID (KSA-1). Use invoice_number if provided, otherwise invoice_counter.
        $invoiceNumber = $this->data['invoice_number'] ?? $this->data['invoice_counter'] ?? $this->data['uuid'];
        $invoice->appendChild($doc->createElement('cbc:ID', $invoiceNumber));
        $invoice->appendChild($doc->createElement('cbc:UUID', $this->data['uuid']));
        // Invoice type code with transaction code as attribute
        $invoiceTypeElement = $doc->createElement('cbc:InvoiceTypeCode', $invoiceTypeCode);
        // Put transaction code on the name attribute if provided
        if (!empty($this->data['invoice_transaction_code'])) {
            $invoiceTypeElement->setAttribute('name', $this->data['invoice_transaction_code']);
        }
        $invoice->appendChild($invoiceTypeElement);
        // Issue date and time (KSA local or UTC time)
        $invoice->appendChild($doc->createElement('cbc:IssueDate', $this->data['issue_date']));
        $invoice->appendChild($doc->createElement('cbc:IssueTime', $this->data['issue_time']));
        // Currency codes
        $invoice->appendChild($doc->createElement('cbc:DocumentCurrencyCode', $currency));
        $invoice->appendChild($doc->createElement('cbc:TaxCurrencyCode', $currency));

        // Seller (Accounting Supplier Party)
        $accountingSupplierParty = $doc->createElement('cac:AccountingSupplierParty');
        $party = $doc->createElement('cac:Party');
        // Party name
        $partyName = $doc->createElement('cac:PartyName');
        $partyName->appendChild($doc->createElement('cbc:Name', $this->data['seller_name']));
        $party->appendChild($partyName);
        // Party tax scheme with registration address and tax scheme
        $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
        $partyTaxScheme->appendChild($doc->createElement('cbc:CompanyID', $this->data['seller_vat']));
        // Registration address
        $regAddress = $doc->createElement('cac:RegistrationAddress');
        $regAddress->appendChild($doc->createElement('cbc:StreetName', $sellerAddress['street']));
        $regAddress->appendChild($doc->createElement('cbc:BuildingNumber', $sellerAddress['building_no']));
        $regAddress->appendChild($doc->createElement('cbc:CityName', $sellerAddress['city']));
        $regAddress->appendChild($doc->createElement('cbc:PostalZone', $sellerAddress['postal_code']));
        $regCountry = $doc->createElement('cac:Country');
        $regCountry->appendChild($doc->createElement('cbc:IdentificationCode', $sellerAddress['country']));
        $regAddress->appendChild($regCountry);
        $partyTaxScheme->appendChild($regAddress);
        // Tax scheme
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($doc->createElement('cbc:ID', 'VAT'));
        $taxScheme->appendChild($doc->createElement('cbc:Name', 'VAT'));
        $partyTaxScheme->appendChild($taxScheme);
        $party->appendChild($partyTaxScheme);
        // Party legal entity (CRN) if present
        if (!empty($this->data['seller_crn'])) {
            $partyLegalEntity = $doc->createElement('cac:PartyLegalEntity');
            $companyID = $doc->createElement('cbc:CompanyID', $this->data['seller_crn']);
            $companyID->setAttribute('schemeID', 'CRN');
            $partyLegalEntity->appendChild($companyID);
            $party->appendChild($partyLegalEntity);
        }
        $accountingSupplierParty->appendChild($party);
        $invoice->appendChild($accountingSupplierParty);

        // Tax totals (BG-22) and VAT breakdown (BG-23)
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxAmount = $doc->createElement('cbc:TaxAmount', number_format($this->data['vat_total'], 2, '.', ''));
        $taxAmount->setAttribute('currencyID', $currency);
        $taxTotal->appendChild($taxAmount);
        // Tax subtotal
        $taxSubTotal = $doc->createElement('cac:TaxSubtotal');
        $taxableAmount = $doc->createElement('cbc:TaxableAmount', number_format($this->data['invoice_total'] - $this->data['vat_total'], 2, '.', ''));
        $taxableAmount->setAttribute('currencyID', $currency);
        $taxSubTotal->appendChild($taxableAmount);
        $taxAmountSub = $doc->createElement('cbc:TaxAmount', number_format($this->data['vat_total'], 2, '.', ''));
        $taxAmountSub->setAttribute('currencyID', $currency);
        $taxSubTotal->appendChild($taxAmountSub);
        $taxCategoryEl = $doc->createElement('cac:TaxCategory');
        $taxCategoryEl->appendChild($doc->createElement('cbc:ID', $taxCategoryCode));
        $taxCategoryEl->appendChild($doc->createElement('cbc:Percent', (string)$taxPercent));
        $taxSchemeEl = $doc->createElement('cac:TaxScheme');
        $taxSchemeEl->appendChild($doc->createElement('cbc:ID', 'VAT'));
        $taxSchemeEl->appendChild($doc->createElement('cbc:Name', 'VAT'));
        $taxCategoryEl->appendChild($taxSchemeEl);
        $taxSubTotal->appendChild($taxCategoryEl);
        $taxTotal->appendChild($taxSubTotal);
        $invoice->appendChild($taxTotal);

        // Monetary totals (LegalMonetaryTotal) including payable amount
        $legalMonetaryTotal = $doc->createElement('cac:LegalMonetaryTotal');
        $taxInclusive = $doc->createElement('cbc:TaxInclusiveAmount', number_format($this->data['invoice_total'], 2, '.', ''));
        $taxInclusive->setAttribute('currencyID', $currency);
        $legalMonetaryTotal->appendChild($taxInclusive);
        $taxExclusive = $doc->createElement('cbc:TaxExclusiveAmount', number_format($this->data['invoice_total'] - $this->data['vat_total'], 2, '.', ''));
        $taxExclusive->setAttribute('currencyID', $currency);
        $legalMonetaryTotal->appendChild($taxExclusive);
        $taxAmountTotal = $doc->createElement('cbc:TaxAmount', number_format($this->data['vat_total'], 2, '.', ''));
        $taxAmountTotal->setAttribute('currencyID', $currency);
        $legalMonetaryTotal->appendChild($taxAmountTotal);
        // Payable amount (Amount due) = total with VAT
        $payableAmount = $doc->createElement('cbc:PayableAmount', number_format($this->data['invoice_total'], 2, '.', ''));
        $payableAmount->setAttribute('currencyID', $currency);
        $legalMonetaryTotal->appendChild($payableAmount);
        $invoice->appendChild($legalMonetaryTotal);

        // Line items (BG-25)
        $lineId = 1;
        foreach ($this->data['items'] as $item) {
            // Normalise keys: support 'price' or 'unit_price' from adapter
            $price = $item['price'] ?? ($item['unit_price'] ?? 0);
            $vatPercentItem = $item['vat_percent'] ?? $taxPercent;
            $vatCategoryItem = $item['vat_category'] ?? $taxCategoryCode;
            $invoiceLine = $doc->createElement('cac:InvoiceLine');
            $invoiceLine->appendChild($doc->createElement('cbc:ID', (string)$lineId));
            $invoiceLine->appendChild($doc->createElement('cbc:InvoicedQuantity', $item['quantity']));
            $lineNet = $price * $item['quantity'];
            $lineNetEl = $doc->createElement('cbc:LineExtensionAmount', number_format($lineNet, 2, '.', ''));
            $lineNetEl->setAttribute('currencyID', $currency);
            $invoiceLine->appendChild($lineNetEl);
            // Item details
            $itemElement = $doc->createElement('cac:Item');
            $itemElement->appendChild($doc->createElement('cbc:Name', $item['name']));
            $invoiceLine->appendChild($itemElement);
            // Tax category for item
            $classifiedTax = $doc->createElement('cac:ClassifiedTaxCategory');
            $classifiedTax->appendChild($doc->createElement('cbc:ID', $vatCategoryItem));
            $classifiedTax->appendChild($doc->createElement('cbc:Percent', (string)$vatPercentItem));
            $taxSchemeIt = $doc->createElement('cac:TaxScheme');
            $taxSchemeIt->appendChild($doc->createElement('cbc:ID', 'VAT'));
            $taxSchemeIt->appendChild($doc->createElement('cbc:Name', 'VAT'));
            $classifiedTax->appendChild($taxSchemeIt);
            $invoiceLine->appendChild($classifiedTax);
            // Price amount (net price per unit)
            $priceEl = $doc->createElement('cac:Price');
            $priceAmount = $doc->createElement('cbc:PriceAmount', number_format($price, 2, '.', ''));
            $priceAmount->setAttribute('currencyID', $currency);
            $priceEl->appendChild($priceAmount);
            $invoiceLine->appendChild($priceEl);
            $invoice->appendChild($invoiceLine);
            $lineId++;
        }

        $doc->appendChild($invoice);
        return $doc->saveXML();
    }
}