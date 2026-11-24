<?php

namespace App\Domain\Import;

/**
 * The import workbook format (docs/11 mode B/C, docs/18 D-2 and D-6). Sheets are processed in this order.
 * Column names are matched case-insensitively; spaces and dashes count as underscores.
 * `*` in the description marks a required column.
 */
final class Template
{
    public const SHEETS = [
        'Districts' => [
            'lgd_code' => '* LGD district code',
            'name_en' => '* Name in English',
            'name_as' => 'Name in Assamese',
            'name_bn' => 'Name in Bengali',
            'erupantor_code' => 'District code in e-Rupantor',
            'is_pilot' => 'yes / no',
        ],
        'CoDistricts' => [
            'lgd_code' => '* LGD code of the co-district',
            'district_lgd_code' => '* LGD code of its district',
            'name_en' => '* Name in English',
            'name_as' => 'Name in Assamese',
            'name_bn' => 'Name in Bengali',
            'erupantor_code' => 'Code in e-Rupantor',
            'is_pilot' => 'yes / no',
        ],
        'Constituencies' => [
            'ac_no' => '* Assembly constituency number',
            'district_lgd_code' => '* LGD code of the main district',
            'name_en' => '* Name in English',
            'name_as' => 'Name in Assamese',
            'name_bn' => 'Name in Bengali',
            'parliamentary_constituency' => 'Lok Sabha constituency name',
        ],
        'Users' => [
            'mobile' => '* 10-digit mobile number (used for OTP login, matched on re-import)',
            'name' => '* Full name',
            'role' => '* state_admin, state_viewer, district_collector, dpc, co_district_officer, agency_user or field_user',
            'designation' => 'e.g. JE, AE, DPO',
            'district_lgd_code' => 'LGD district code (required for district_collector, dpc, agency_user)',
            'co_district_lgd_code' => 'LGD co-district code (required for co_district_officer)',
            'agency_code' => 'Implementing agency code (required for agency_user)',
            'email' => 'Email address',
            'preferred_locale' => 'en, as or bn (default en)',
            'is_active' => 'yes / no (default yes)',
        ],
        'Works' => [
            'code' => '* Unique work code (kept if e-Rupantor ID is missing)',
            'erupantor_id' => 'Scheme / work ID in e-Rupantor (matched first)',
            'name_en' => '* Work name in English',
            'name_as' => 'Work name in Assamese',
            'district_lgd_code' => '* LGD district code',
            'co_district_lgd_code' => 'LGD co-district code',
            'constituency_ac_no' => 'Assembly constituency number',
            'scheme_type_code' => '* Scheme type code',
            'scheme_type_name' => 'Name, to create a new scheme type',
            'fund_source_code' => '* Fund source code (e.g. MLA_ADF)',
            'fund_source_name' => 'Name, to create a new fund source',
            'financial_year' => '* e.g. 2026-27',
            'agency_code' => '* Implementing agency code',
            'agency_name' => 'Name, to create a new agency',
            'stage_template_code' => '* Stage template (configured in admin)',
            'sanctioned_amount_rs' => '* Sanctioned amount in rupees',
            'sanction_order_no' => 'Sanction order number',
            'sanction_date' => 'YYYY-MM-DD or DD-MM-YYYY',
            'target_completion_date' => 'YYYY-MM-DD or DD-MM-YYYY',
            'site_lat' => 'Latitude (decimal degrees)',
            'site_lng' => 'Longitude (decimal degrees)',
            'site_radius_m' => 'Photo geofence radius in metres (default 200)',
            'field_user_mobile' => 'Mobile of the assigned field officer',
        ],
        'Releases' => [
            'work_code' => '* Work code or e-Rupantor ID',
            'amount_rs' => '* Amount released in rupees',
            'released_on' => '* YYYY-MM-DD or DD-MM-YYYY',
            'order_no' => 'Release order number',
        ],
    ];

    public static function required(string $sheet): array
    {
        return array_keys(array_filter(self::SHEETS[$sheet], fn ($d) => str_starts_with($d, '*')));
    }

    public static function normaliseHeader(string $h): string
    {
        return trim(preg_replace('/[\s\-]+/', '_', strtolower(trim($h))), '_');
    }

    /** Sheet name in the file → canonical sheet name, or null if not part of the template. */
    public static function canonicalSheet(string $name): ?string
    {
        $n = str_replace(['_', ' ', '-'], '', strtolower($name));
        foreach (array_keys(self::SHEETS) as $sheet) {
            if (strtolower($sheet) === $n || rtrim(strtolower($sheet), 's') === $n) {
                return $sheet;
            }
        }

        return null;
    }
}
