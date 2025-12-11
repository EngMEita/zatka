<?php

/*
 |--------------------------------------------------------------------------
 | ZATCA Configuration
 |--------------------------------------------------------------------------
 |
 | This file defines the configuration structure for the ZATCA e‑invoicing
 | package. When publishing to a Laravel application, this file will be
 | copied into your application's config directory where you can define
 | multiple companies/tenants. For pure PHP usage, you can copy this
 | template to a suitable location and return an array of configuration
 | options.
 |
 | Each company array contains the seller details, invoice preferences,
 | paths to keys, API credentials and environment specific endpoints. The
 | "invoice_type" defines the default type to use when calling
 | sendInvoice() without specifying a type. The "invoice_types"
 | defines which types this company can issue (standard and/or
 | simplified).
 */

return [
    // The default company/tenant identifier. This determines which
    // configuration set will be used when no company is specified.
    'default' => 'company_1',

    // A list of company configurations keyed by an identifier. You can
    // define as many companies as required.
    'companies' => [
        'company_1' => [
            // Legal name of the seller
            'seller_name'      => '',
            // VAT registration number of the seller
            'seller_vat'       => '',
            // Commercial registration number (CRN) of the seller (optional)
            'seller_crn'       => '',
            // Default invoice type for this company ("simplified" or "standard")
            'invoice_type'     => 'simplified',
            // A list of allowed invoice types this company may issue
            'invoice_types'    => ['simplified', 'standard'],
            // Currency code for all monetary amounts
            'currency'         => 'SAR',

            // Seller postal address details
            'address' => [
                'street'        => '',
                'building_no'   => '',
                'city'          => '',
                'postal_code'   => '',
                'country'       => 'SA',
            ],

            // Tax settings for the VAT rate and category code. Adjust these
            // values according to your needs. For example, category_code
            // "S" stands for standard rated (15%). See ZATCA docs for others.
            'tax' => [
                'percent'       => 15,
                'category_code' => 'S',
            ],

            // Paths to the cryptographic material used to sign invoices. The
            // private key must be in PEM format and correspond to the
            // certificate (public key) issued by ZATCA.
            'certificate_path' => '',
            'private_key_path' => '',

            // Credentials issued by ZATCA when onboarding your EGS unit. The
            // client_id corresponds to the CSID and the client_secret is the
            // associated secret.
            'client_id'        => '',
            'client_secret'    => '',

            // The environment to use for API calls. Use "sandbox" for
            // development and testing. Use "production" for live calls.
            'environment'      => 'sandbox',

            // API endpoints for clearance and reporting in each environment.
            // Update these if ZATCA publishes new versions of the API.
            'endpoints'        => [
                'sandbox' => [
                    'clearance' => 'https://sandbox.zatca.gov.sa/e-invoicing/core/invoices/clearance',
                    'reporting' => 'https://sandbox.zatca.gov.sa/e-invoicing/core/invoices/reporting',
                ],
                'production' => [
                    'clearance' => 'https://gw-apic-gov.gazt.gov.sa/e-invoicing/core/invoices/clearance',
                    'reporting' => 'https://gw-apic-gov.gazt.gov.sa/e-invoicing/core/invoices/reporting',
                ],
            ],
        ],
    ],
];