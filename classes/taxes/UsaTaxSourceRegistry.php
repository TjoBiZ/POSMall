<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use KodZero\POSMall\Classes\Taxes\Parsers\CaliforniaTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\ColoradoTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\DelawareTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\FloridaTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\IllinoisTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\ManualTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\MinnesotaTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\NewYorkTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\OregonTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\OfficialPageTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\SstTaxabilityMatrixParser;
use KodZero\POSMall\Classes\Taxes\Parsers\SstTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\TexasTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\UsaTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\WashingtonDorZip4Parser;
use KodZero\POSMall\Classes\Taxes\Parsers\WashingtonLocalRateTableParser;
use KodZero\POSMall\Classes\Taxes\Parsers\WashingtonTaxSourceParser;

class UsaTaxSourceRegistry
{
    public static function states(): array
    {
        $states = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];

        return collect($states)->map(fn ($name, $code) => [
            'code' => $code,
            'name' => $name,
            'description' => self::description($code),
            'sources' => self::sources($code),
        ])->all();
    }

    public static function taxGroups(): array
    {
        return [
            'PHYSICAL_TPP' => ['Physical tangible personal property', 'Physical goods shipped or handed to a customer.', 'Scarves, boxes, printed products, accessories, equipment, packaged goods.'],
            'DIGITAL_FILE_ONLY' => ['Digital product delivered electronically only', 'Digital file, data, ebook, app or download without physical media.', 'Downloadable vector templates, PDF guides, digital art files, ebooks.'],
            'DIGITAL_ON_PHYSICAL_MEDIA' => ['Digital product with physical media', 'Digital product delivered with USB, disc, printed copy or another physical medium.', 'USB drive with files, printed backup copy, boxed software media.'],
            'PREWRITTEN_SOFTWARE_ELECTRONIC' => ['Prewritten software delivered electronically', 'Ready-made software delivered by download or electronic transmission.', 'Ready-made app, plugin, theme, license key, downloadable software.'],
            'PREWRITTEN_SOFTWARE_PHYSICAL' => ['Prewritten software on physical media', 'Ready-made software on disc, USB or other physical media.', 'USB software package, disc installer, physical license kit.'],
            'SAAS_REMOTE_ACCESS' => ['SaaS / remote access software', 'Hosted software, subscription access or remote license to use software.', 'Hosted dashboard, subscription app, remote design tool, cloud account.'],
            'CUSTOM_SOFTWARE_DEV' => ['Custom software development', 'Software designed and developed to a specific purchaser specification.', 'Custom plugin work, custom integration, custom automation project.'],
            'CUSTOM_MODIFICATION_SEPARATE' => ['Separately stated custom modification', 'Separately invoiced custom modification of software or digital work.', 'Logo change, color change, custom file adjustment, separate design modification.'],
            'DIGITAL_AUTOMATED_SERVICE' => ['Digital automated service', 'Digital service where software performs most of the work.', 'Automated file conversion, automated image processing, generated report.'],
            'DIGITAL_AUDIOVISUAL_AUDIO_SERVICE' => ['Digital audiovisual or audio service', 'Streaming, subscription or electronically delivered digital audiovisual or audio service when the state taxes that service category.', 'Streaming video, digital audio subscription, paid online audiovisual service.'],
            'HUMAN_PROFESSIONAL_SERVICE' => ['Human professional service', 'Consulting, design, setup or other work led by human effort.', 'Consultation, design review, setup help, print-material advice.'],
            'RETAIL_REPAIR_INSTALLATION_SERVICE' => ['Retail repair, installation and cleaning service', 'Installing, cleaning, repairing, decorating, beautifying or altering tangible personal property for consumers.', 'Auto repair labor, appliance repair, field technician repair, equipment installation, car detailing.'],
            'REAL_PROPERTY_CONSTRUCTION_REPAIR_SERVICE' => ['Real property construction, repair and handyman service', 'Constructing, improving, repairing, cleaning or altering real property for consumers.', 'Handyman work, construction labor, furnace repair, septic repair, painting, snow removal.'],
            'LANDSCAPING_MAINTENANCE_SERVICE' => ['Landscaping and landscape maintenance service', 'Landscaping, lawn maintenance and related outdoor maintenance services.', 'Landscape installation, lawn maintenance, pruning, yard cleanup.'],
            'INFORMATION_TECHNOLOGY_SERVICE' => ['Information technology service', 'IT services, technical support, IT training and assistance with information technology infrastructure.', 'IT support, help desk, field IT technician, infrastructure support, technical training.'],
            'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE' => ['Custom website development service', 'Custom website design, development and related website services.', 'Custom website build, website redesign, custom web implementation.'],
            'ADVERTISING_SERVICE' => ['Advertising service', 'Advertising services newly taxable or specifically taxable in a jurisdiction.', 'Digital ads, campaign setup, advertising placement, marketing creative tied to advertising services.'],
            'TEMPORARY_STAFFING_SERVICE' => ['Temporary staffing service', 'Temporary staffing services when taxable under the destination jurisdiction.', 'Temporary workers, staffing agency labor, contract staffing.'],
            'SECURITY_INVESTIGATION_SERVICE' => ['Security and investigation service', 'Security, investigation and related protection services.', 'Security guard, investigation, armored car service.'],
            'LIVE_PRESENTATION_SERVICE' => ['Live presentation service', 'Live presentations and similar events when taxable under the destination jurisdiction.', 'Live seminar, presentation, workshop, training event.'],
            'DATA_PROCESSING_SERVICE' => ['Data processing service', 'Entering, storing, manipulating, retrieving, hosting or processing customer data.', 'File cleanup, data conversion, hosting, batch preparation, customer data processing.'],
            'INFORMATION_SERVICE' => ['Information service', 'Access to databases, reports, research, market data or mailing lists.', 'Research report, curated supplier list, market data, production information package.'],
            'GIFT_CARD_STORED_VALUE' => ['Gift card / stored value', 'Gift card, certificate or stored value, often taxed later at redemption.', 'Gift card, stored balance, prepaid shop credit, digital gift certificate.'],
            'SHIPPING_HANDLING' => ['Shipping and handling', 'Freight, delivery, shipping or handling charges.', 'Postal delivery, courier shipping, handling, packaging and dispatch.'],
            'CLOTHING_FOOTWEAR' => ['Clothing and footwear', 'Apparel and footwear with state-specific exemptions or thresholds.', 'Scarves used as apparel, shirts, shoes, wearable accessories.'],
            'FOOD_GROCERY' => ['Food / grocery', 'Food, grocery and prepared-food distinction.', 'Grocery items, prepared food, edible gift items.'],
            'MEDICAL_DURABLE_EQUIPMENT' => ['Medical / durable medical equipment', 'Medical equipment and prescription-related product groups.', 'Medical devices, mobility aids, prescription-related equipment.'],
            'EXEMPT_CUSTOMER_RESALE' => ['Exempt customer / resale certificate', 'Customer-level exemption or resale-certificate override.', 'Wholesale resale customer, nonprofit exemption, government exemption.'],
        ];
    }

    public static function taxGroupExamples(string $code): string
    {
        return self::taxGroups()[$code][2] ?? 'Review this tax group against the official state source before production use.';
    }

    public static function seedRules(): array
    {
        $rules = [
            ['AL', 'PHYSICAL_TPP', 4.00, 'AL_REVENUE_TAX_RATES', 'Alabama Sales Tax - Physical Goods'],
            ['AL', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 4.00, 'AL_COMPUTER_SOFTWARE_TAXABILITY', 'Alabama Sales Tax - Computer Software'],
            ['AL', 'SAAS_REMOTE_ACCESS', 4.00, 'AL_COMPUTER_SOFTWARE_TAXABILITY', 'Alabama Sales Tax - Software Licensure'],
            ['AL', 'CUSTOM_SOFTWARE_DEV', 0, 'AL_COMPUTER_SOFTWARE_TAXABILITY', 'Alabama Separately Stated Software Programming Services'],
            ['AL', 'RETAIL_REPAIR_INSTALLATION_SERVICE', 0, 'AL_REPAIR_INSTALLATION_LABOR', 'Alabama Separately Stated Repair/Installation Labor'],
            ['AK', 'PHYSICAL_TPP', 0, 'AK_LOCAL_SALES_TAX_INFORMATION', 'Alaska - No State Sales Tax'],
            ['AR', 'PHYSICAL_TPP', 6.50, 'SST_RATES_BOUNDARIES', 'Arkansas Sales Tax - Physical Goods'],
            ['AZ', 'PHYSICAL_TPP', 5.60, 'AZ_TPT_RATE_TABLE', 'Arizona TPT - Retail Physical Goods'],
            ['AZ', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 5.60, 'AZ_SOFTWARE_DATA_SERVICES', 'Arizona TPT - Prewritten Software Remote Transfer'],
            ['AZ', 'CUSTOM_SOFTWARE_DEV', 0, 'AZ_SOFTWARE_DATA_SERVICES', 'Arizona TPT - Custom Computer Programming Review'],
            ['CA', 'PHYSICAL_TPP', 7.25, 'CA_CDTFA_ARCGIS_JSON', 'California Sales Tax - Physical Goods'],
            ['CA', 'DIGITAL_FILE_ONLY', 0, 'CA_CDTFA_DIGITAL_GOODS', 'California Sales Tax - Digital File Only'],
            ['CO', 'PHYSICAL_TPP', 2.90, 'CO_GIS_API_AND_RATE_LOOKUP', 'Colorado Sales Tax - Physical Goods'],
            ['CO', 'DIGITAL_FILE_ONLY', 2.90, 'CO_SALES_TAX_GUIDE', 'Colorado Sales Tax - Digital Goods'],
            ['CO', 'HUMAN_PROFESSIONAL_SERVICE', 0, 'CO_SALES_TAX_GUIDE', 'Colorado Sales Tax - Professional Services Review'],
            ['CT', 'PHYSICAL_TPP', 6.35, 'CT_DRS_SALES_TAX', 'Connecticut Sales Tax - Physical Goods'],
            ['CT', 'DIGITAL_FILE_ONLY', 6.35, 'CT_DIGITAL_GOODS_DATA_PROCESSING', 'Connecticut Sales Tax - Digital Goods'],
            ['CT', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.35, 'CT_DIGITAL_GOODS_DATA_PROCESSING', 'Connecticut Sales Tax - Prewritten Software Personal Use'],
            ['CT', 'SAAS_REMOTE_ACCESS', 1.00, 'CT_DIGITAL_GOODS_DATA_PROCESSING', 'Connecticut Sales Tax - Business Software/Data Processing Review'],
            ['CT', 'DATA_PROCESSING_SERVICE', 1.00, 'CT_DIGITAL_GOODS_DATA_PROCESSING', 'Connecticut Sales Tax - Computer and Data Processing Services'],
            ['DC', 'PHYSICAL_TPP', 6.00, 'DC_OTR_TAX_RATES', 'District of Columbia Sales Tax - Physical Goods'],
            ['DC', 'DIGITAL_FILE_ONLY', 6.00, 'DC_DIGITAL_GOODS', 'District of Columbia Sales Tax - Digital Goods'],
            ['DC', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.00, 'DC_DIGITAL_GOODS', 'District of Columbia Sales Tax - Software'],
            ['DC', 'SAAS_REMOTE_ACCESS', 6.00, 'DC_DIGITAL_GOODS', 'District of Columbia Sales Tax - Software Services'],
            ['DC', 'CUSTOM_SOFTWARE_DEV', 6.00, 'DC_DIGITAL_GOODS', 'District of Columbia Sales Tax - Custom Software'],
            ['DC', 'DATA_PROCESSING_SERVICE', 6.00, 'DC_TAXABLE_SERVICES', 'District of Columbia Sales Tax - Data Processing Services'],
            ['DC', 'INFORMATION_SERVICE', 6.00, 'DC_TAXABLE_SERVICES', 'District of Columbia Sales Tax - Information Services'],
            ['DC', 'SECURITY_INVESTIGATION_SERVICE', 6.00, 'DC_TAXABLE_SERVICES', 'District of Columbia Sales Tax - Security Services'],
            ['DE', 'PHYSICAL_TPP', 0, 'DE_GROSS_RECEIPTS_NO_SALES_TAX', 'Delaware - No State or Local Sales Tax'],
            ['DE', 'DIGITAL_FILE_ONLY', 0, 'DE_EXEMPTION_CERTIFICATES', 'Delaware Digital Goods - No Sales Tax'],
            ['GA', 'PHYSICAL_TPP', 4.00, 'SST_RATES_BOUNDARIES', 'Georgia Sales Tax - Physical Goods'],
            ['HI', 'PHYSICAL_TPP', 4.00, 'HI_GET_INFORMATION', 'Hawaii GET - General Business Activity'],
            ['HI', 'DIGITAL_FILE_ONLY', 4.00, 'HI_GET_INFORMATION', 'Hawaii GET - Digital Products'],
            ['HI', 'HUMAN_PROFESSIONAL_SERVICE', 4.00, 'HI_GET_INFORMATION', 'Hawaii GET - Services'],
            ['ID', 'PHYSICAL_TPP', 6.00, 'ID_SALES_TAX_INFORMATION', 'Idaho Sales Tax - Physical Goods'],
            ['ID', 'DIGITAL_FILE_ONLY', 6.00, 'ID_SALES_TAX_INFORMATION', 'Idaho Sales Tax - Digital Products With Permanent Use'],
            ['ID', 'CUSTOM_MODIFICATION_SEPARATE', 6.00, 'ID_SALES_TAX_INFORMATION', 'Idaho Sales Tax - Production/Fabrication/Imprinting Labor'],
            ['IN', 'PHYSICAL_TPP', 7.00, 'SST_RATES_BOUNDARIES', 'Indiana Sales Tax - Physical Goods'],
            ['IA', 'PHYSICAL_TPP', 6.00, 'SST_RATES_BOUNDARIES', 'Iowa Sales Tax - Physical Goods'],
            ['KS', 'PHYSICAL_TPP', 6.50, 'SST_RATES_BOUNDARIES', 'Kansas Sales Tax - Physical Goods'],
            ['KY', 'PHYSICAL_TPP', 6.00, 'SST_RATES_BOUNDARIES', 'Kentucky Sales Tax - Physical Goods'],
            ['WA', 'PHYSICAL_TPP', 6.50, 'WA_DOR_XML_OR_TXT', 'Washington Sales Tax - Physical Goods'],
            ['WA', 'DIGITAL_FILE_ONLY', 6.50, 'WA_DOR_DIGITAL_GOODS', 'Washington Sales Tax - Digital Goods'],
            ['WA', 'SAAS_REMOTE_ACCESS', 6.50, 'WA_DOR_DIGITAL_GOODS', 'Washington Sales Tax - SaaS'],
            ['WA', 'RETAIL_REPAIR_INSTALLATION_SERVICE', 6.50, 'WA_DOR_RETAIL_SERVICES', 'Washington Sales Tax - Retail Repair, Installation and Cleaning Services'],
            ['WA', 'REAL_PROPERTY_CONSTRUCTION_REPAIR_SERVICE', 6.50, 'WA_DOR_RETAIL_SERVICES', 'Washington Sales Tax - Construction, Repair and Handyman Services'],
            ['WA', 'LANDSCAPING_MAINTENANCE_SERVICE', 6.50, 'WA_DOR_RETAIL_SERVICES', 'Washington Sales Tax - Landscaping and Maintenance Services'],
            ['WA', 'INFORMATION_TECHNOLOGY_SERVICE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Information Technology Services'],
            ['WA', 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Custom Website Development Services'],
            ['WA', 'CUSTOM_SOFTWARE_DEV', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Custom Software Development'],
            ['WA', 'CUSTOM_MODIFICATION_SEPARATE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Software Customization'],
            ['WA', 'ADVERTISING_SERVICE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Advertising Services'],
            ['WA', 'TEMPORARY_STAFFING_SERVICE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Temporary Staffing Services'],
            ['WA', 'SECURITY_INVESTIGATION_SERVICE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Security and Investigation Services'],
            ['WA', 'LIVE_PRESENTATION_SERVICE', 6.50, 'WA_DOR_ESSB_5814_SERVICES', 'Washington Sales Tax - Live Presentation Services'],
            ['WA', 'GIFT_CARD_STORED_VALUE', 0, 'WA_DOR_GIFT_CARDS', 'Washington Gift Card - Tax at Redemption'],
            ['IL', 'PHYSICAL_TPP', 6.25, 'IL_MACHINE_READABLE_FILES', 'Illinois Sales Tax - Physical Goods'],
            ['IL', 'DIGITAL_FILE_ONLY', 0, 'IL_SOFTWARE_DIGITAL_SERVICE_TAXABILITY', 'Illinois Digital Goods - No Tangible Personal Property Transfer'],
            ['IL', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.25, 'IL_SOFTWARE_DIGITAL_SERVICE_TAXABILITY', 'Illinois Canned Software - Download/Agent Transfer Review'],
            ['IL', 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE', 0, 'IL_SOFTWARE_DIGITAL_SERVICE_TAXABILITY', 'Illinois Custom Website/SaaS Service Review'],
            ['IL', 'FOOD_GROCERY', 0, 'IL_GROCERY_MACHINE_READABLE_FILES', 'Illinois Grocery Tax - Location Review'],
            ['LA', 'PHYSICAL_TPP', 5.00, 'LA_GENERAL_SALES_USE_TAX', 'Louisiana Sales Tax - Physical Goods'],
            ['LA', 'DIGITAL_FILE_ONLY', 5.00, 'LA_DIGITAL_PRODUCTS_SERVICES', 'Louisiana Sales Tax - Digital Products'],
            ['LA', 'SAAS_REMOTE_ACCESS', 5.00, 'LA_DIGITAL_PRODUCTS_SERVICES', 'Louisiana Sales Tax - Prewritten Software Access Services'],
            ['LA', 'INFORMATION_SERVICE', 5.00, 'LA_DIGITAL_PRODUCTS_SERVICES', 'Louisiana Sales Tax - Information Services'],
            ['ME', 'PHYSICAL_TPP', 5.50, 'ME_SALES_USE_RATES', 'Maine Sales Tax - Physical Goods'],
            ['ME', 'DIGITAL_FILE_ONLY', 5.50, 'ME_DIGITAL_PRODUCTS_AND_TAXABLE_SERVICES', 'Maine Sales Tax - Products Transferred Electronically'],
            ['ME', 'DIGITAL_AUDIOVISUAL_AUDIO_SERVICE', 5.50, 'ME_DIGITAL_PRODUCTS_AND_TAXABLE_SERVICES', 'Maine Sales Tax - Digital Audiovisual and Audio Services'],
            ['MD', 'PHYSICAL_TPP', 6.00, 'MD_SALES_USE_TAX_GUIDANCE', 'Maryland Sales Tax - Physical Goods'],
            ['MD', 'DIGITAL_FILE_ONLY', 6.00, 'MD_DIGITAL_PRODUCTS', 'Maryland Sales Tax - Digital Products'],
            ['MD', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.00, 'MD_DIGITAL_PRODUCTS', 'Maryland Sales Tax - Digital Products and Codes'],
            ['MD', 'HUMAN_PROFESSIONAL_SERVICE', 0, 'MD_TAXABLE_SERVICES', 'Maryland Services - Generally Exempt Review'],
            ['MD', 'SECURITY_INVESTIGATION_SERVICE', 6.00, 'MD_TAXABLE_SERVICES', 'Maryland Sales Tax - Security Services'],
            ['MA', 'PHYSICAL_TPP', 6.25, 'MA_SALES_USE_TAX', 'Massachusetts Sales Tax - Physical Goods'],
            ['MA', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.25, 'MA_SOFTWARE_TAXABILITY', 'Massachusetts Sales Tax - Prewritten Software'],
            ['MA', 'SAAS_REMOTE_ACCESS', 6.25, 'MA_SOFTWARE_TAXABILITY', 'Massachusetts Sales Tax - Remote Software Access'],
            ['MA', 'CUSTOM_SOFTWARE_DEV', 0, 'MA_SOFTWARE_TAXABILITY', 'Massachusetts Custom Software - Professional Service Exemption'],
            ['MA', 'HUMAN_PROFESSIONAL_SERVICE', 0, 'MA_SOFTWARE_TAXABILITY', 'Massachusetts Professional Services - Exempt Review'],
            ['MI', 'PHYSICAL_TPP', 6.00, 'SST_RATES_BOUNDARIES', 'Michigan Sales Tax - Physical Goods'],
            ['MN', 'PHYSICAL_TPP', 6.875, 'MN_SALES_TAX_API', 'Minnesota Sales Tax - Physical Goods'],
            ['MS', 'PHYSICAL_TPP', 7.00, 'MS_SALES_TAX_RATES', 'Mississippi Sales Tax - Physical Goods'],
            ['MS', 'DIGITAL_FILE_ONLY', 7.00, 'MS_DIGITAL_AND_SOFTWARE_SERVICES', 'Mississippi Sales Tax - Digital and Electronic Goods'],
            ['MS', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 7.00, 'MS_DIGITAL_AND_SOFTWARE_SERVICES', 'Mississippi Sales Tax - Prewritten Software'],
            ['MS', 'CUSTOM_SOFTWARE_DEV', 7.00, 'MS_DIGITAL_AND_SOFTWARE_SERVICES', 'Mississippi Sales Tax - Computer Software Services'],
            ['MS', 'FOOD_GROCERY', 5.00, 'MS_SALES_TAX_RATES', 'Mississippi Sales Tax - Groceries'],
            ['MO', 'PHYSICAL_TPP', 4.225, 'MO_SALES_USE_TAX_RATES', 'Missouri Sales Tax - Physical Goods'],
            ['MO', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 0, 'MO_SOFTWARE_TAXABILITY', 'Missouri Digitally Delivered Canned Software'],
            ['MO', 'CUSTOM_SOFTWARE_DEV', 0, 'MO_SOFTWARE_TAXABILITY', 'Missouri Custom Software'],
            ['MT', 'PHYSICAL_TPP', 0, 'MT_NO_GENERAL_SALES_TAX', 'Montana - No General Sales Tax'],
            ['NE', 'PHYSICAL_TPP', 5.50, 'SST_RATES_BOUNDARIES', 'Nebraska Sales Tax - Physical Goods'],
            ['NV', 'PHYSICAL_TPP', 6.85, 'SST_RATES_BOUNDARIES', 'Nevada Sales Tax - Physical Goods'],
            ['NH', 'PHYSICAL_TPP', 0, 'NH_NO_GENERAL_SALES_TAX', 'New Hampshire - No General Sales Tax'],
            ['NJ', 'PHYSICAL_TPP', 6.625, 'SST_RATES_BOUNDARIES', 'New Jersey Sales Tax - Physical Goods'],
            ['NM', 'PHYSICAL_TPP', 4.875, 'NM_GROSS_RECEIPTS_TAX', 'New Mexico GRT - Physical Goods'],
            ['NM', 'DIGITAL_FILE_ONLY', 4.875, 'NM_GROSS_RECEIPTS_TAX', 'New Mexico GRT - Digital Products'],
            ['NM', 'HUMAN_PROFESSIONAL_SERVICE', 4.875, 'NM_GROSS_RECEIPTS_TAX', 'New Mexico GRT - Services'],
            ['NC', 'PHYSICAL_TPP', 4.75, 'SST_RATES_BOUNDARIES', 'North Carolina Sales Tax - Physical Goods'],
            ['ND', 'PHYSICAL_TPP', 5.00, 'SST_RATES_BOUNDARIES', 'North Dakota Sales Tax - Physical Goods'],
            ['NY', 'PHYSICAL_TPP', 4.00, 'NY_SALES_USE_TAX_RATES', 'New York Sales Tax - Physical Goods'],
            ['NY', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 4.00, 'NY_SOFTWARE_TAXABILITY', 'New York Sales Tax - Prewritten Software'],
            ['NY', 'SAAS_REMOTE_ACCESS', 4.00, 'NY_SOFTWARE_TAXABILITY', 'New York Sales Tax - SaaS'],
            ['NY', 'CUSTOM_SOFTWARE_DEV', 0, 'NY_SOFTWARE_TAXABILITY', 'New York Sales Tax - Custom Software'],
            ['OH', 'PHYSICAL_TPP', 5.75, 'SST_RATES_BOUNDARIES', 'Ohio Sales Tax - Physical Goods'],
            ['OK', 'PHYSICAL_TPP', 4.50, 'SST_RATES_BOUNDARIES', 'Oklahoma Sales Tax - Physical Goods'],
            ['TX', 'PHYSICAL_TPP', 6.25, 'TX_COMPTROLLER_TXT_XLSX_OR_LOCATOR', 'Texas Sales Tax - Physical Goods'],
            ['TX', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.25, 'TX_SOFTWARE_TAXABILITY', 'Texas Sales Tax - Prewritten Software'],
            ['TX', 'SAAS_REMOTE_ACCESS', 5.00, 'TX_DATA_PROCESSING', 'Texas Sales Tax - SaaS/Data Processing'],
            ['TX', 'DATA_PROCESSING_SERVICE', 5.00, 'TX_DATA_PROCESSING', 'Texas Sales Tax - Data Processing Service'],
            ['TX', 'INFORMATION_SERVICE', 5.00, 'TX_INFORMATION_SERVICE', 'Texas Sales Tax - Information Service'],
            ['FL', 'PHYSICAL_TPP', 6.00, 'FL_POINTMATCH', 'Florida Sales Tax - Physical Goods'],
            ['FL', 'DIGITAL_FILE_ONLY', 0, 'FL_ELECTRONIC_SOFTWARE', 'Florida Sales Tax - Purely Electronic Digital/Software Transfer Review'],
            ['FL', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 0, 'FL_ELECTRONIC_SOFTWARE', 'Florida Sales Tax - Electronically Delivered Software'],
            ['FL', 'SAAS_REMOTE_ACCESS', 0, 'FL_CLOUD_COMPUTING_SERVICES', 'Florida Sales Tax - Cloud Computing Services'],
            ['FL', 'CUSTOM_SOFTWARE_DEV', 0, 'FL_CLOUD_COMPUTING_SERVICES', 'Florida Sales Tax - Customized Software Services'],
            ['FL', 'INFORMATION_SERVICE', 0, 'FL_ELECTRONIC_INFORMATION_REPORTS', 'Florida Sales Tax - Electronic Information Reports'],
            ['OR', 'PHYSICAL_TPP', 0, 'OR_NO_GENERAL_SALES_TAX', 'Oregon - No General Sales Tax'],
            ['OR', 'DIGITAL_FILE_ONLY', 0, 'OR_NO_GENERAL_SALES_TAX', 'Oregon Digital Goods - No General Sales Tax'],
            ['PA', 'PHYSICAL_TPP', 6.00, 'PA_SALES_USE_HOTEL_OCCUPANCY', 'Pennsylvania Sales Tax - Physical Goods'],
            ['PA', 'DIGITAL_FILE_ONLY', 6.00, 'PA_DIGITAL_PRODUCTS', 'Pennsylvania Sales Tax - Digital Products'],
            ['PA', 'PREWRITTEN_SOFTWARE_ELECTRONIC', 6.00, 'PA_DIGITAL_PRODUCTS', 'Pennsylvania Sales Tax - Canned Software'],
            ['PA', 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE', 6.00, 'PA_CANNED_SOFTWARE_DIGITAL_RELATED_SERVICES', 'Pennsylvania Website Development - Transferred Website/Canned Software Access'],
            ['PA', 'DATA_PROCESSING_SERVICE', 6.00, 'PA_CANNED_SOFTWARE_DIGITAL_RELATED_SERVICES', 'Pennsylvania Data Conversion/Processing Related to Canned Software'],
            ['PA', 'INFORMATION_SERVICE', 6.00, 'PA_CANNED_SOFTWARE_DIGITAL_RELATED_SERVICES', 'Pennsylvania Information Retrieval and Data Sales'],
            ['RI', 'PHYSICAL_TPP', 7.00, 'SST_RATES_BOUNDARIES', 'Rhode Island Sales Tax - Physical Goods'],
            ['SC', 'PHYSICAL_TPP', 6.00, 'SC_SALES_TAX', 'South Carolina Sales Tax - Physical Goods'],
            ['SC', 'SAAS_REMOTE_ACCESS', 6.00, 'SC_TAXABLE_COMMUNICATION_SERVICES', 'South Carolina Sales Tax - Cloud Based Services'],
            ['SC', 'INFORMATION_SERVICE', 6.00, 'SC_TAXABLE_COMMUNICATION_SERVICES', 'South Carolina Sales Tax - Database Access Transmission Services'],
            ['SD', 'PHYSICAL_TPP', 4.20, 'SST_RATES_BOUNDARIES', 'South Dakota Sales Tax - Physical Goods'],
            ['TN', 'PHYSICAL_TPP', 7.00, 'SST_RATES_BOUNDARIES', 'Tennessee Sales Tax - Physical Goods'],
            ['UT', 'PHYSICAL_TPP', 4.85, 'SST_RATES_BOUNDARIES', 'Utah Sales Tax - Physical Goods'],
            ['VT', 'PHYSICAL_TPP', 6.00, 'SST_RATES_BOUNDARIES', 'Vermont Sales Tax - Physical Goods'],
            ['VA', 'PHYSICAL_TPP', 5.30, 'VA_RETAIL_SALES_USE_TAX', 'Virginia Sales Tax - Physical Goods'],
            ['VA', 'FOOD_GROCERY', 1.00, 'VA_RETAIL_SALES_USE_TAX', 'Virginia Grocery Tax - Food and Personal Hygiene'],
            ['WV', 'PHYSICAL_TPP', 6.00, 'SST_RATES_BOUNDARIES', 'West Virginia Sales Tax - Physical Goods'],
            ['WI', 'PHYSICAL_TPP', 5.00, 'SST_RATES_BOUNDARIES', 'Wisconsin Sales Tax - Physical Goods'],
            ['WY', 'PHYSICAL_TPP', 4.00, 'SST_RATES_BOUNDARIES', 'Wyoming Sales Tax - Physical Goods'],
        ];

        return array_merge(
            $rules,
            self::zeroServiceRules('CA', 'CA_CDTFA_SERVICES_NONTAXABLE', 'California Sales Tax - Pure Services'),
            self::zeroServiceRules('OR', 'OR_NO_GENERAL_SALES_TAX', 'Oregon Services - No General Sales Tax'),
            self::zeroVirtualRules('DE', 'DE_GROSS_RECEIPTS_NO_SALES_TAX', 'Delaware Virtual Products - No Sales Tax'),
            self::zeroServiceRules('DE', 'DE_GROSS_RECEIPTS_NO_SALES_TAX', 'Delaware Services - No Sales Tax'),
            self::zeroVirtualRules('MT', 'MT_NO_GENERAL_SALES_TAX', 'Montana Virtual Products - No General Sales Tax'),
            self::zeroServiceRules('MT', 'MT_NO_GENERAL_SALES_TAX', 'Montana Services - No General Sales Tax'),
            self::zeroVirtualRules('NH', 'NH_NO_GENERAL_SALES_TAX', 'New Hampshire Virtual Products - No Sales Tax'),
            self::zeroServiceRules('NH', 'NH_NO_GENERAL_SALES_TAX', 'New Hampshire Services - No Sales Tax'),
            self::zeroVirtualRules('VA', 'VA_ELECTRONIC_SERVICES_EXEMPT', 'Virginia Electronically Delivered Software/Data/Content'),
            self::zeroServiceRules('VA', 'VA_SERVICES_EXEMPT', 'Virginia Pure Services - No Tangible Personal Property')
        );
    }

    protected static function zeroVirtualRules(string $state, string $sourceCode, string $namePrefix): array
    {
        return collect([
            'DIGITAL_FILE_ONLY',
            'PREWRITTEN_SOFTWARE_ELECTRONIC',
            'SAAS_REMOTE_ACCESS',
        ])->map(fn (string $code) => [$state, $code, 0, $sourceCode, $namePrefix . ' - ' . $code])
            ->all();
    }

    protected static function zeroServiceRules(string $state, string $sourceCode, string $namePrefix): array
    {
        return collect([
            'DIGITAL_AUTOMATED_SERVICE',
            'DIGITAL_AUDIOVISUAL_AUDIO_SERVICE',
            'HUMAN_PROFESSIONAL_SERVICE',
            'RETAIL_REPAIR_INSTALLATION_SERVICE',
            'REAL_PROPERTY_CONSTRUCTION_REPAIR_SERVICE',
            'LANDSCAPING_MAINTENANCE_SERVICE',
            'INFORMATION_TECHNOLOGY_SERVICE',
            'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE',
            'CUSTOM_SOFTWARE_DEV',
            'CUSTOM_MODIFICATION_SEPARATE',
            'ADVERTISING_SERVICE',
            'TEMPORARY_STAFFING_SERVICE',
            'SECURITY_INVESTIGATION_SERVICE',
            'LIVE_PRESENTATION_SERVICE',
            'DATA_PROCESSING_SERVICE',
            'INFORMATION_SERVICE',
        ])->map(fn (string $code) => [$state, $code, 0, $sourceCode, $namePrefix . ' - ' . $code])
            ->all();
    }

    public static function starterStateCodes(): array
    {
        return collect(self::seedRules())
            ->pluck(0)
            ->unique()
            ->values()
            ->all();
    }

    public static function sourceByCode(string $code): array
    {
        foreach (self::sourceCatalog() as $source) {
            if ($source['code'] === $code) {
                return $source;
            }
        }

        return [
            'code' => $code,
            'name' => 'Manual official source',
            'type' => 'MANUAL',
            'url' => null,
        ];
    }

    public static function parserFor(array $source): UsaTaxSourceParser
    {
        $class = $source['parser'] ?? ManualTaxSourceParser::class;

        return app($class);
    }

    public static function sources(string $stateCode): array
    {
        $stateCode = strtoupper($stateCode);
        $sources = collect(self::sourceCatalog())->filter(function ($source) use ($stateCode) {
            return ($source['state'] ?? null) === $stateCode
                || in_array($stateCode, $source['states'] ?? [], true);
        })->values()->all();

        if ($sources) {
            return $sources;
        }

        return [[
            'code' => $stateCode . '_MANUAL_OFFICIAL_DOC',
            'name' => 'Manual official state review',
            'type' => 'MANUAL',
            'url' => null,
        ]];
    }

    protected static function description(string $stateCode): string
    {
        if ($stateCode === 'OR') {
            return 'No general sales tax. Keep a zero-rate rule for state restriction and checkout fallback.';
        }

        return 'Use official state or SST sources, then review staged records before import.';
    }

    public static function washingtonDestinationTaxGroupCodes(): array
    {
        return [
            'PHYSICAL_TPP',
            'DIGITAL_FILE_ONLY',
            'SAAS_REMOTE_ACCESS',
            'RETAIL_REPAIR_INSTALLATION_SERVICE',
            'REAL_PROPERTY_CONSTRUCTION_REPAIR_SERVICE',
            'LANDSCAPING_MAINTENANCE_SERVICE',
            'INFORMATION_TECHNOLOGY_SERVICE',
            'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE',
            'CUSTOM_SOFTWARE_DEV',
            'CUSTOM_MODIFICATION_SEPARATE',
            'ADVERTISING_SERVICE',
            'TEMPORARY_STAFFING_SERVICE',
            'SECURITY_INVESTIGATION_SERVICE',
            'LIVE_PRESENTATION_SERVICE',
        ];
    }

    protected static function sourceCatalog(): array
    {
        $sstStates = ['AR', 'GA', 'IN', 'IA', 'KS', 'KY', 'MI', 'MN', 'NE', 'NV', 'NJ', 'NC', 'ND', 'OH', 'OK', 'RI', 'SD', 'UT', 'VT', 'WA', 'WV', 'WI', 'WY', 'TN'];

        return [
            [
                'code' => 'SST_TAXABILITY_MATRIX',
                'name' => 'SST Taxability Matrix',
                'type' => 'HTML_EXCEL_CSV',
                'url' => 'https://sst.streamlinedsalestax.org/TM/query',
                'states' => $sstStates,
                'parser' => SstTaxabilityMatrixParser::class,
            ],
            [
                'code' => 'SST_RATES_BOUNDARIES',
                'name' => 'SST Rate and Boundary Files',
                'type' => 'CSV_ZIP',
                'url' => 'https://www.streamlinedsalestax.org/ratesandboundry/Rates/',
                'states' => $sstStates,
                'parser' => SstTaxSourceParser::class,
            ],
            [
                'code' => 'AL_REVENUE_TAX_RATES',
                'name' => 'Alabama Sales and Use Tax Rates',
                'type' => 'HTML_PDF',
                'url' => 'https://www.revenue.alabama.gov/sales-use/tax-rates/',
                'state' => 'AL',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/state\\s+sales\\s+tax[^\\d]*(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'AL_REPAIR_INSTALLATION_LABOR',
                'name' => 'Alabama Sales Tax Guidance - Separately Stated Repair/Installation Labor',
                'type' => 'HTML',
                'url' => 'https://www.revenue.alabama.gov/sales-use/sales-tax/',
                'state' => 'AL',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'AL_COMPUTER_SOFTWARE_TAXABILITY',
                'name' => 'Alabama Computer Hardware and Software Rule',
                'type' => 'PDF',
                'url' => 'https://www.revenue.alabama.gov/wp-content/uploads/2021/12/810-6-1-.37-.pdf',
                'state' => 'AL',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'AK_LOCAL_SALES_TAX_INFORMATION',
                'name' => 'Alaska Sales Tax Information',
                'type' => 'HTML',
                'url' => 'https://www.commerce.alaska.gov/web/dcra/OfficeoftheStateAssessor/AlaskaSalesTaxInformation.aspx',
                'state' => 'AK',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'AZ_TPT_RATE_TABLE',
                'name' => 'Arizona TPT Rate Table',
                'type' => 'PDF',
                'url' => 'https://azdor.gov/sites/default/files/document/TPT_RATETABLE_06012025.pdf',
                'state' => 'AZ',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/state(?:\\W+transaction\\W+privilege\\W+tax)?\\W+rate\\W+of\\W+(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'AZ_SOFTWARE_DATA_SERVICES',
                'name' => 'Arizona Model City Tax Code - Computer Hardware, Software and Data Services',
                'type' => 'HTML',
                'url' => 'https://azdor.gov/model-city-tax-code/articles-and-sections/computer-hardware-software-and-data-services',
                'state' => 'AZ',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'CA_CDTFA_ARCGIS_JSON',
                'name' => 'CDTFA ArcGIS JSON',
                'type' => 'JSON',
                'url' => 'https://services6.arcgis.com/snwvZ3EmaoXJiugR/arcgis/rest/services/California_Sales_and_Use_Tax_Rates/FeatureServer/1/query?where=1%3D1&outFields=*&f=json',
                'state' => 'CA',
                'parser' => CaliforniaTaxSourceParser::class,
            ],
            [
                'code' => 'CA_CDTFA_TAX_RATE_API',
                'name' => 'CDTFA Tax Rate API',
                'type' => 'API_DOC',
                'url' => 'https://services.maps.cdtfa.ca.gov/docs.html',
                'state' => 'CA',
                'parser' => CaliforniaTaxSourceParser::class,
            ],
            [
                'code' => 'CA_CDTFA_DIGITAL_GOODS',
                'name' => 'CDTFA Digital Goods Publication',
                'type' => 'HTML',
                'url' => 'https://cdtfa.ca.gov/formspubs/pub109/nontaxable-sales.htm',
                'state' => 'CA',
                'parser' => CaliforniaTaxSourceParser::class,
            ],
            [
                'code' => 'CA_CDTFA_SERVICES_NONTAXABLE',
                'name' => 'CDTFA Services and Digital Products Collection Guidance',
                'type' => 'HTML',
                'url' => 'https://www.cdtfa.ca.gov/industry/wayfair/general-information.htm',
                'state' => 'CA',
                'parser' => CaliforniaTaxSourceParser::class,
            ],
            [
                'code' => 'CO_GIS_API_AND_RATE_LOOKUP',
                'name' => 'Colorado GIS API and Rate Lookup',
                'type' => 'HTML_API',
                'url' => 'https://tax.colorado.gov/GIS-API',
                'state' => 'CO',
                'parser' => ColoradoTaxSourceParser::class,
            ],
            [
                'code' => 'CO_SALES_TAX_GUIDE',
                'name' => 'Colorado Sales Tax Guide',
                'type' => 'HTML',
                'url' => 'https://tax.colorado.gov/sales-tax-guide',
                'state' => 'CO',
                'parser' => ColoradoTaxSourceParser::class,
            ],
            [
                'code' => 'CT_DRS_SALES_TAX',
                'name' => 'Connecticut DRS Sales Tax',
                'type' => 'HTML',
                'url' => 'https://portal.ct.gov/DRS/Sales-Tax',
                'state' => 'CT',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/(6\\.35)\\s*%/',
            ],
            [
                'code' => 'CT_DIGITAL_GOODS_DATA_PROCESSING',
                'name' => 'Connecticut Sales Tax Digital Goods and Computer/Data Processing',
                'type' => 'HTML',
                'url' => 'https://portal.ct.gov/DRS/Sales-Tax/Tax-Information',
                'state' => 'CT',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'DC_OTR_TAX_RATES',
                'name' => 'District of Columbia Sales and Use Tax',
                'type' => 'HTML',
                'url' => 'https://otr.cfo.dc.gov/page/sales-use-tax',
                'state' => 'DC',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/General\\s+Sale[^\\d]*(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'DC_DIGITAL_GOODS',
                'name' => 'District of Columbia Digital Goods Sales Taxability Chart',
                'type' => 'HTML',
                'url' => 'https://otr.cfo.dc.gov/page/digital-goods-sales-taxability-chart',
                'state' => 'DC',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'DC_TAXABLE_SERVICES',
                'name' => 'District of Columbia Taxable and Non-Taxable Services',
                'type' => 'HTML',
                'url' => 'https://otr.cfo.dc.gov/page/sales-use-tax',
                'state' => 'DC',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'DE_GROSS_RECEIPTS_NO_SALES_TAX',
                'name' => 'Delaware Gross Receipts and No Sales Tax',
                'type' => 'HTML',
                'url' => 'https://revenue.delaware.gov/business-tax-forms/doing-business-in-delaware/step-4-gross-receipts-taxes/',
                'state' => 'DE',
                'parser' => DelawareTaxSourceParser::class,
            ],
            [
                'code' => 'HI_GET_INFORMATION',
                'name' => 'Hawaii General Excise Tax Information',
                'type' => 'HTML',
                'url' => 'https://tax.hawaii.gov/geninfo/get/',
                'state' => 'HI',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/(4)\\s*%\\s+for\\s+all\\s+others/i',
            ],
            [
                'code' => 'ID_SALES_TAX_INFORMATION',
                'name' => 'Idaho Sales and Use Taxes Basics Guide',
                'type' => 'HTML',
                'url' => 'https://tax.idaho.gov/taxes/sales-use/online-guide/',
                'state' => 'ID',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/sales\\s+tax\\s+rate\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'DE_EXEMPTION_CERTIFICATES',
                'name' => 'Delaware Exemption Certificates',
                'type' => 'HTML',
                'url' => 'https://revenue.delaware.gov/business-tax-forms/exemption-certificates/',
                'state' => 'DE',
                'parser' => DelawareTaxSourceParser::class,
            ],
            [
                'code' => 'WA_DOR_DOWNLOADABLE_ZIP4_DATABASE',
                'name' => 'Washington DOR Downloadable ZIP+4 Database',
                'type' => 'ZIP4_CSV',
                'url' => 'https://dor.wa.gov/taxes-rates/sales-use-tax-rates/downloadable-database',
                'zip4_url' => 'https://dor.wa.gov/sites/default/files/2026-02/Zip4Q226C.zip',
                'rate_url' => 'https://dor.wa.gov/sites/default/files/2026-02/Rates_26Q2.zip',
                'api_url' => 'https://webgis.dor.wa.gov/webapi/AddressRates.aspx',
                'state' => 'WA',
                'parser' => WashingtonDorZip4Parser::class,
                'tax_group_codes' => self::washingtonDestinationTaxGroupCodes(),
            ],
            [
                'code' => 'WA_DOR_RATE_LIBRARY_SOURCE_CODE',
                'name' => 'Washington DOR Sales Tax Rate Library Source Code',
                'type' => 'SOURCE_CODE_GUIDANCE',
                'url' => 'https://dor.wa.gov/washington-sales-tax-rate-library-source-code',
                'state' => 'WA',
                'parser' => WashingtonTaxSourceParser::class,
            ],
            [
                'code' => 'WA_DOR_XML_OR_TXT',
                'name' => 'Washington DOR XML/Text API',
                'type' => 'XML_TEXT',
                'url' => 'https://dor.wa.gov/wa-sales-tax-rate-lookup-url-interface',
                'api_url' => 'https://webgis.dor.wa.gov/webapi/AddressRates.aspx',
                'state' => 'WA',
                'parser' => WashingtonTaxSourceParser::class,
            ],
            [
                'code' => 'WA_DOR_LOCAL_RATE_TABLE',
                'name' => 'Washington DOR Local Sales and Use Tax Rate Table',
                'type' => 'HTML_TABLE',
                'url' => 'https://dor.wa.gov/taxes-rates/sales-use-tax-rates/local-sales-use-tax/local-sales-use-tax-rate-table?field_county_target_id=All&field_location_code_value=&field_location_name_value=&field_rate_updated_this_quarter_value=All&items_per_page=100&order=field_combined_sales_tax&page=0&sort=desc',
                'boundary_url' => 'https://www.streamlinedsalestax.org/ratesandboundry/Boundary/WAB2026Q3MAY27.zip',
                'rate_url' => 'https://www.streamlinedsalestax.org/ratesandboundry/Rates/WAR2026Q3MAY27.zip',
                'state' => 'WA',
                'parser' => WashingtonLocalRateTableParser::class,
                'max_pages' => 8,
                'boundary_max_rows' => 400000,
                'max_zip_hints_per_code' => 20,
                'tax_group_codes' => self::washingtonDestinationTaxGroupCodes(),
                'local_rate_examples' => [
                    ['county' => 'King', 'name' => 'Seattle', 'code' => '1726', 'local_rate' => 4.05, 'state_rate' => 6.50, 'combined_rate' => 10.55, 'zip_code_hints' => ['98101', '98102', '98103', '98104', '98105', '98106', '98107', '98108', '98109', '98112', '98115', '98116', '98117', '98118', '98119', '98121', '98122', '98125', '98126', '98133']],
                    ['county' => 'King', 'name' => 'Bellevue', 'code' => '1711', 'local_rate' => 3.80, 'state_rate' => 6.50, 'combined_rate' => 10.30, 'zip_code_hints' => ['98004', '98005', '98006', '98007', '98008']],
                    ['county' => 'Pierce', 'name' => 'Tacoma', 'code' => '2708', 'local_rate' => 3.80, 'state_rate' => 6.50, 'combined_rate' => 10.30, 'zip_code_hints' => ['98402', '98403', '98404', '98405', '98406', '98407', '98408', '98409', '98418', '98421', '98422', '98424']],
                    ['county' => 'Spokane', 'name' => 'Spokane', 'code' => '3210', 'local_rate' => 2.50, 'state_rate' => 6.50, 'combined_rate' => 9.00, 'zip_code_hints' => ['99201', '99202', '99203', '99204', '99205', '99207', '99208', '99212', '99217', '99223', '99224']],
                    ['county' => 'Clark', 'name' => 'Vancouver', 'code' => '0607', 'local_rate' => 2.20, 'state_rate' => 6.50, 'combined_rate' => 8.70, 'zip_code_hints' => ['98660', '98661', '98662', '98663', '98664', '98665', '98682', '98683', '98684', '98685', '98686']],
                    ['county' => 'Thurston', 'name' => 'Olympia', 'code' => '3403', 'local_rate' => 3.30, 'state_rate' => 6.50, 'combined_rate' => 9.80, 'zip_code_hints' => ['98501', '98502', '98504', '98506', '98507', '98508', '98512', '98516', '98599']],
                ],
            ],
            [
                'code' => 'WA_DOR_RETAIL_SERVICES',
                'name' => 'Washington DOR Services Subject to Sales Tax',
                'type' => 'HTML',
                'url' => 'https://dor.wa.gov/taxes-rates/retail-sales-tax/services-subject-sales-tax',
                'state' => 'WA',
                'parser' => WashingtonTaxSourceParser::class,
            ],
            [
                'code' => 'WA_DOR_ESSB_5814_SERVICES',
                'name' => 'Washington DOR Services Newly Subject to Retail Sales Tax',
                'type' => 'HTML',
                'url' => 'https://dor.wa.gov/taxes-rates/retail-sales-tax/services-newly-subject-retail-sales-tax',
                'state' => 'WA',
                'parser' => WashingtonTaxSourceParser::class,
            ],
            [
                'code' => 'WA_DOR_DIGITAL_GOODS',
                'name' => 'Washington DOR Digital Products',
                'type' => 'HTML',
                'url' => 'https://dor.wa.gov/forms-publications/publications-subject/tax-topics/digital-products-including-digital-goods',
                'state' => 'WA',
                'parser' => WashingtonTaxSourceParser::class,
            ],
            [
                'code' => 'WA_DOR_GIFT_CARDS',
                'name' => 'Washington DOR Gift Cards',
                'type' => 'HTML',
                'url' => 'https://dor.wa.gov/forms-publications/publications-subject/tax-topics/gift-cards-gift-certificates-and-layaway-purchases',
                'state' => 'WA',
                'parser' => WashingtonTaxSourceParser::class,
            ],
            [
                'code' => 'IL_MACHINE_READABLE_FILES',
                'name' => 'Illinois Sales Tax Machine Readable Files',
                'type' => 'DOWNLOADABLE_FILES',
                'url' => 'https://tax.illinois.gov/research/taxrates/sales-tax-rate-machine-readable-files.html',
                'file_url' => 'https://tax.illinois.gov/content/dam/soi/en/web/tax/research/taxrates/documents/salestaxrates/ordmache-current.txt',
                'state' => 'IL',
                'parser' => IllinoisTaxSourceParser::class,
            ],
            [
                'code' => 'IL_ADDRESS_SPECIFIC_FILES',
                'name' => 'Illinois Address Specific Machine Readable Files',
                'type' => 'ZIP_CSV',
                'url' => 'https://tax.illinois.gov/research/taxrates/machine-readable-file-address-specific.html',
                'address_file_url' => 'https://tax.illinois.gov/content/dam/soi/en/web/tax/research/taxrates/documents/salestaxrates/ordmacha-current.txt',
                'state' => 'IL',
                'parser' => IllinoisTaxSourceParser::class,
            ],
            [
                'code' => 'IL_GROCERY_MACHINE_READABLE_FILES',
                'name' => 'Illinois Grocery Tax Machine Readable Files',
                'type' => 'DOWNLOADABLE_FILES',
                'url' => 'https://tax.illinois.gov/research/taxrates/sales-tax-rate-machine-readable-files.html',
                'state' => 'IL',
                'parser' => IllinoisTaxSourceParser::class,
            ],
            [
                'code' => 'IL_SOFTWARE_DIGITAL_SERVICE_TAXABILITY',
                'name' => 'Illinois Software, Digital Goods and SaaS General Information Letter',
                'type' => 'PDF',
                'url' => 'https://tax.illinois.gov/content/dam/soi/en/web/tax/research/legalinformation/letterrulings/st/documents/2024/ST24-0022-GIL.pdf',
                'state' => 'IL',
                'parser' => IllinoisTaxSourceParser::class,
            ],
            [
                'code' => 'MN_SALES_TAX_API',
                'name' => 'Minnesota Sales Tax API',
                'type' => 'API_DOC',
                'url' => 'https://www.revenue.state.mn.us/sales-tax-api-application-program-interface',
                'state' => 'MN',
                'parser' => MinnesotaTaxSourceParser::class,
            ],
            [
                'code' => 'MN_SALES_TAX_API_DEVELOPER_DOC',
                'name' => 'Minnesota Sales Tax API Developer Document',
                'type' => 'PDF',
                'url' => 'https://www.revenue.state.mn.us/sites/default/files/2026-02/sales-tax-api-developer-document.pdf',
                'state' => 'MN',
                'parser' => MinnesotaTaxSourceParser::class,
            ],
            [
                'code' => 'LA_GENERAL_SALES_USE_TAX',
                'name' => 'Louisiana General Sales and Use Tax',
                'type' => 'HTML',
                'url' => 'https://www.revenue.la.gov/businesses/sales-taxes/general-sales-use-tax/',
                'state' => 'LA',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/state\\s+sales\\s+tax\\s+rate\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'LA_DIGITAL_PRODUCTS_SERVICES',
                'name' => 'Louisiana Sales and Use Tax - Digital Products and Related Services',
                'type' => 'HTML',
                'url' => 'https://revenue.louisiana.gov/businesses/sales-taxes/general-sales-use-tax/',
                'state' => 'LA',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'ME_SALES_USE_RATES',
                'name' => 'Maine Sales and Use Tax Rates',
                'type' => 'HTML',
                'url' => 'https://www1.maine.gov/revenue/taxes/sales-use-service-provider-tax/rates-due-dates',
                'state' => 'ME',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/General\\s+Sales[^\\d]*(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'ME_DIGITAL_PRODUCTS_AND_TAXABLE_SERVICES',
                'name' => 'Maine Sales and Use Tax FAQ - Products Transferred Electronically',
                'type' => 'HTML',
                'url' => 'https://www11.maine.gov/revenue/faq/sales-use-service-provider-tax',
                'state' => 'ME',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'MD_SALES_USE_TAX_GUIDANCE',
                'name' => 'Maryland Sales and Use Tax Guidance',
                'type' => 'HTML',
                'url' => 'https://services.marylandcomptroller.gov/taxes?id=kb_article_view&sysparm_article=KB0010107',
                'state' => 'MD',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/sales\\s+and\\s+use\\s+tax\\s+rate\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*percent/i',
            ],
            [
                'code' => 'MD_DIGITAL_PRODUCTS',
                'name' => 'Maryland Sales of Digital Products and Digital Codes',
                'type' => 'PDF',
                'url' => 'https://www.marylandtaxes.gov/forms/Business_Tax_Tips/bustip29.pdf',
                'state' => 'MD',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'MD_TAXABLE_SERVICES',
                'name' => 'Maryland List of Tangible Personal Property and Services',
                'type' => 'PDF',
                'url' => 'https://www.marylandtaxes.gov/forms/Tax_Publications/Sales_and_Use_Tax-List_of_TPP_and_Services.pdf',
                'state' => 'MD',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'MA_SALES_USE_TAX',
                'name' => 'Massachusetts Sales and Use Tax',
                'type' => 'HTML',
                'url' => 'https://www.mass.gov/sales-and-use-tax',
                'state' => 'MA',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/sales\\s+tax\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'MA_SOFTWARE_TAXABILITY',
                'name' => 'Massachusetts Computer Industry Services and Products Regulation',
                'type' => 'HTML',
                'url' => 'https://www.mass.gov/regulations/830-CMR-64h13-computer-industry-services-and-products',
                'state' => 'MA',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'MS_SALES_TAX_RATES',
                'name' => 'Mississippi Sales Tax Rates',
                'type' => 'HTML',
                'url' => 'https://www.dor.ms.gov/business/sales-tax-rates',
                'state' => 'MS',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/Sale\\s+of\\s+tangible\\s+personal\\s+property[^\\d]*(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'MS_DIGITAL_AND_SOFTWARE_SERVICES',
                'name' => 'Mississippi Business Tax FAQ - Digital Goods and Software Services',
                'type' => 'HTML',
                'url' => 'https://www.dor.ms.gov/business/business-tax-frequently-asked-questions',
                'state' => 'MS',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'MO_SALES_USE_TAX_RATES',
                'name' => 'Missouri Sales and Use Tax',
                'type' => 'HTML',
                'url' => 'https://dor.mo.gov/taxation/business/tax-types/sales-use/',
                'state' => 'MO',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/state\\s+sales\\s+tax\\s+rate\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'MO_SOFTWARE_TAXABILITY',
                'name' => 'Missouri Sales Tax FAQ - Computer Software and Related Services',
                'type' => 'HTML',
                'url' => 'https://dor.mo.gov/faq/taxation/business/sales-use-tax-exemptions.html',
                'state' => 'MO',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'MT_NO_GENERAL_SALES_TAX',
                'name' => 'Montana Revenue Tax Information',
                'type' => 'HTML',
                'url' => 'https://revenue.mt.gov/taxes/index',
                'state' => 'MT',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'NM_GROSS_RECEIPTS_TAX',
                'name' => 'New Mexico Gross Receipts Tax Overview',
                'type' => 'HTML',
                'url' => 'https://www.tax.newmexico.gov/governments/gross-receipts-tax/',
                'state' => 'NM',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/state\\s+portion\\s+of\\s+the\\s+gross\\s+receipts\\s+tax\\s+rate[^\\d]*(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'NH_NO_GENERAL_SALES_TAX',
                'name' => 'New Hampshire Department of Revenue Taxpayer Assistance',
                'type' => 'HTML',
                'url' => 'https://www.revenue.nh.gov/about-dra/frequently-asked-questions/taxpayer-assistance',
                'state' => 'NH',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'PA_SALES_USE_HOTEL_OCCUPANCY',
                'name' => 'Pennsylvania Sales, Use and Hotel Occupancy Tax',
                'type' => 'HTML',
                'url' => 'https://www.pa.gov/agencies/revenue/resources/tax-types-and-information/sales-use-and-hotel-occupancy-tax.html',
                'state' => 'PA',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/sales\\s+tax\\s+rate\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*percent/i',
            ],
            [
                'code' => 'PA_DIGITAL_PRODUCTS',
                'name' => 'Pennsylvania Digital Products',
                'type' => 'HTML',
                'url' => 'https://www.pa.gov/agencies/revenue/resources/tax-types-and-information/sales-use-and-hotel-occupancy-tax/digital-products',
                'state' => 'PA',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'PA_CANNED_SOFTWARE_DIGITAL_RELATED_SERVICES',
                'name' => 'Pennsylvania Canned Software, Digital Goods and Related Services',
                'type' => 'HTML',
                'url' => 'https://www.pa.gov/agencies/revenue/resources/tax-types-and-information/sales-use-and-hotel-occupancy-tax/canned-computer-software-digital-goods',
                'state' => 'PA',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'SC_SALES_TAX',
                'name' => 'South Carolina Sales Tax',
                'type' => 'HTML',
                'url' => 'https://www.dor.sc.gov/sales-use-tax-index/sales-tax',
                'state' => 'SC',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/statewide\\s+Sales\\s*&\\s*Use\\s+Tax\\s+rate\\s+is\\s+(\\d+(?:\\.\\d+)?)\\s*%/i',
            ],
            [
                'code' => 'SC_TAXABLE_COMMUNICATION_SERVICES',
                'name' => 'South Carolina Sales Tax - Taxable Communication Services',
                'type' => 'HTML',
                'url' => 'https://dor.sc.gov/sales-use-tax-index/sales-tax',
                'state' => 'SC',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'VA_RETAIL_SALES_USE_TAX',
                'name' => 'Virginia Retail Sales and Use Tax',
                'type' => 'HTML',
                'url' => 'https://www.tax.virginia.gov/sales-and-use-tax',
                'state' => 'VA',
                'parser' => OfficialPageTaxSourceParser::class,
                'rate_regex' => '/(5\\.3)\\s*%\\s+\\|\\s+Everywhere\\s+else/i',
            ],
            [
                'code' => 'VA_ELECTRONIC_SERVICES_EXEMPT',
                'name' => 'Virginia Electronically Delivered Software, Data, Content and Information Services',
                'type' => 'HTML',
                'url' => 'https://www.tax.virginia.gov/laws-rules-decisions/rulings-tax-commissioner/14-178',
                'state' => 'VA',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'VA_SERVICES_EXEMPT',
                'name' => 'Virginia Services Generally Exempt from Retail Sales and Use Tax',
                'type' => 'HTML',
                'url' => 'https://www.tax.virginia.gov/laws-rules-decisions/rulings-tax-commissioner/25-21',
                'state' => 'VA',
                'parser' => OfficialPageTaxSourceParser::class,
            ],
            [
                'code' => 'TX_COMPTROLLER_TXT_XLSX_OR_LOCATOR',
                'name' => 'Texas Comptroller Rate Sources',
                'type' => 'TXT_XLSX_HTML',
                'url' => 'https://comptroller.texas.gov/data/edi/sales-tax/taxrates.txt',
                'api_url' => 'https://gis.cpa.texas.gov/search/',
                'state' => 'TX',
                'parser' => TexasTaxSourceParser::class,
            ],
            [
                'code' => 'TX_DATA_PROCESSING',
                'name' => 'Texas Data Processing Services',
                'type' => 'HTML',
                'url' => 'https://comptroller.texas.gov/taxes/publications/94-127.php',
                'state' => 'TX',
                'parser' => TexasTaxSourceParser::class,
            ],
            [
                'code' => 'TX_SOFTWARE_TAXABILITY',
                'name' => 'Texas STAR Software Taxability',
                'type' => 'STAR_RULING',
                'url' => 'https://star.comptroller.texas.gov/view/8812L0922A13',
                'state' => 'TX',
                'parser' => TexasTaxSourceParser::class,
            ],
            [
                'code' => 'TX_INFORMATION_SERVICE',
                'name' => 'Texas Taxable Services',
                'type' => 'HTML',
                'url' => 'https://comptroller.texas.gov/taxes/publications/96-259.php',
                'state' => 'TX',
                'parser' => TexasTaxSourceParser::class,
            ],
            [
                'code' => 'NY_SALES_USE_TAX_RATES',
                'name' => 'New York Sales and Use Tax Rates',
                'type' => 'HTML',
                'url' => 'https://www.tax.ny.gov/bus/st/rates.htm',
                'state' => 'NY',
                'parser' => NewYorkTaxSourceParser::class,
            ],
            [
                'code' => 'NY_SOFTWARE_TAXABILITY',
                'name' => 'New York Computer Software',
                'type' => 'HTML',
                'url' => 'https://www.tax.ny.gov/pubs_and_bulls/tg_bulletins/st/computer_software.htm',
                'state' => 'NY',
                'parser' => NewYorkTaxSourceParser::class,
            ],
            [
                'code' => 'FL_POINTMATCH',
                'name' => 'Florida PointMatch',
                'type' => 'HTML_DOWNLOADS',
                'url' => 'https://pointmatch.floridarevenue.com/',
                'address_file_url' => 'https://pointmatch.floridarevenue.com/General/AddressFiles.aspx',
                'state' => 'FL',
                'parser' => FloridaTaxSourceParser::class,
            ],
            [
                'code' => 'FL_ELECTRONIC_SOFTWARE',
                'name' => 'Florida TAA 10A-028 - Electronically Delivered Software',
                'type' => 'PDF',
                'url' => 'https://floridarevenue.com/TaxLaw/Documents/10A-028.pdf',
                'state' => 'FL',
                'parser' => FloridaTaxSourceParser::class,
            ],
            [
                'code' => 'FL_CLOUD_COMPUTING_SERVICES',
                'name' => 'Florida TAA 16A-014 - Computer Software and Cloud Computing Services',
                'type' => 'PDF',
                'url' => 'https://www.floridarevenue.com/TaxLaw/Documents/16A-014.pdf',
                'state' => 'FL',
                'parser' => FloridaTaxSourceParser::class,
            ],
            [
                'code' => 'FL_ELECTRONIC_INFORMATION_REPORTS',
                'name' => 'Florida TAA 02A-045 - Electronic Information Reports',
                'type' => 'PDF',
                'url' => 'https://floridarevenue.com/TaxLaw/Documents/TAA%2002A-045.pdf',
                'state' => 'FL',
                'parser' => FloridaTaxSourceParser::class,
            ],
            [
                'code' => 'OR_NO_GENERAL_SALES_TAX',
                'name' => 'Oregon Sales Tax Information',
                'type' => 'MANUAL',
                'url' => 'https://www.oregon.gov/dor/programs/businesses/Pages/sales-tax.aspx',
                'state' => 'OR',
                'parser' => OregonTaxSourceParser::class,
            ],
        ];
    }
}
