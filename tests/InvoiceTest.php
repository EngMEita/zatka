<?php

namespace Zatca\Tests;

use PHPUnit\Framework\TestCase;
use Zatca\Invoice;
use DOMDocument;

/**
 * Class InvoiceTest
 *
 * Tests basic functionality of the Invoice class such as UUID generation
 * and XML rendering. These tests ensure that the invoice can be turned
 * into a minimal UBL document without errors.
 */
class InvoiceTest extends TestCase
{
    /**
     * Test that generateUuid returns a valid UUID v4.
     */
    public function testGenerateUuid(): void
    {
        $uuid = Invoice::generateUuid();
        // UUID v4 pattern: 8-4-4-4-12 hex characters with version 4 and variant bits set
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    /**
     * Test that toXml returns a valid XML string with expected elements.
     */
    public function testToXmlStructure(): void
    {
        $invoice = new Invoice([
            'seller_name'  => 'ACME Inc.',
            'seller_vat'   => '1234567890',
            'invoice_total'=> 115.00,
            'vat_total'    => 15.00,
            'items' => [
                ['name' => 'Item A', 'quantity' => 1, 'price' => 100.00],
            ],
        ]);
        $xml = $invoice->toXml();
        // Ensure XML contains root element and some expected values
        $this->assertStringContainsString('<Invoice', $xml);
        $this->assertStringContainsString('ACME Inc.', $xml);
        $this->assertStringContainsString('1234567890', $xml);
        // Parse the XML to verify it is well-formed
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
        $this->assertEquals('Invoice', $doc->documentElement->nodeName);
    }
}