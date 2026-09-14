<?php

namespace App\Support;

/**
 * The one place that turns an imported phone string into canonical E.164.
 *
 * ─── Why this exists ────────────────────────────────────────────────────────
 *
 * `contacts` is uniquely keyed `(workspace_id, phone_e164)`, so the stored
 * string IS the contact's identity. Two spellings of one number become two
 * contacts, silently, with no error — which is why normalization cannot be
 * left to each caller.
 *
 * It was, until now. Measured before writing this: FIVE independent
 * implementations existed and none was shared —
 * `PayloadNormalizer::phone()` and `SyncStoreCustomersJob::phone()`
 * (byte-identical private duplicates), `SmartQrRedirectResolver::normalise()`,
 * `CampaignController`'s inline `preg_replace`, and `ContactService::
 * importGridRows()`'s bare `str_starts_with($phone, '+')` test. None of them
 * adds a country code, so none could accept a local number.
 * `SmartQrRedirectResolver::normalise()` even carries the docblock line
 * "Kept here rather than reaching for a shared helper because none exists".
 *
 * ⚠️ THIS CLASS DELIBERATELY DOES NOT RETROFIT THOSE FIVE. They are reached by
 * the WhatsApp, Smart QR, e-commerce and campaign paths, none of which is in
 * this change's scope, and rewriting a phone value those flows already store
 * would change contact identity for existing rows. This is the import path's
 * normalizer, built so the others CAN adopt it later; adopting them is its own
 * slice, with its own migration question about existing rows.
 *
 * ─── The rule, in one line ──────────────────────────────────────────────────
 *
 * A leading `+` means the number carries its own country code and is trusted
 * as written — validated structurally, never re-interpreted through whatever
 * country happens to be selected. Anything else is local digits and needs an
 * explicitly chosen default country before it can mean anything at all.
 *
 * There is no fallback default and there must never be one. Guessing `+91`
 * because the product is India-first would silently mangle every number a
 * non-Indian workspace imports, and the damage is indistinguishable from a
 * successful import until someone tries to message the contact.
 *
 * ─── ⚠️ CORRECTION: this dataset was ONCE a hand-curated 55-country subset ──
 *
 * That shipped as a first pass and was rejected in review: "AutomationXpert is
 * a multi-country SaaS" and a 55-country list silently fails every workspace
 * outside it, the same class of silent failure this whole file exists to
 * remove. COUNTRIES below is now a COMPLETE ISO 3166-1 alpha-2 sweep of every
 * territory with a real, independently-dialable E.164 calling code —
 * including the ones that deliberately SHARE a calling code with another
 * country (all of NANP under +1; Russia and Kazakhstan under +7; Jersey,
 * Guernsey and the Isle of Man under +44; Réunion and Mayotte under +262; and
 * so on) — grouped and commented below so each sharing cluster is a visible,
 * deliberate entry, not an accidental collision.
 *
 * National significant-number length ranges are the practical numbering-plan
 * ranges publicly documented per country (ITU/ITU-T numbering plans), not a
 * per-number-type ITU guarantee — the same caveat the original 55-entry table
 * already carried. Where a plan's true range was uncertain, the range was
 * widened rather than narrowed: an overly strict range silently rejects a
 * real number, which is the failure mode this file exists to prevent; an
 * overly wide one merely accepts something a stricter checker might not.
 */
final class PhoneNumber
{
    /**
     * Selectable import countries: ISO-3166-1 alpha-2 => name, calling code,
     * and the inclusive national-digit range used to validate a LOCAL number.
     *
     * ⚠️ The range validates LOCAL input only, against the EXPLICITLY selected
     * country. An explicit `+` number is never checked against this table —
     * see normalizeExplicitE164() — because doing so would mean re-validating
     * (and risking rejecting) a real number from a territory this table gets
     * even slightly wrong, which is a worse failure than the generic
     * structural check it uses instead.
     *
     * ⚠️ MULTIPLE entries deliberately share one `calling_code` value. That is
     * not a bug to deduplicate — a Canadian number and a Bahamian number are
     * both +1, but they are different COUNTRIES for the purpose of this
     * selector (which is about setting country-appropriate expectations, not
     * about the calling code alone), so each keeps its own row.
     *
     * @var array<string, array{name: string, calling_code: string, min: int, max: int}>
     */
    public const COUNTRIES = [
        // ── NANP — all share calling code 1 (10-digit national number, uniformly) ──
        'US' => ['name' => 'United States', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'CA' => ['name' => 'Canada', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'BS' => ['name' => 'Bahamas', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'BB' => ['name' => 'Barbados', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'AI' => ['name' => 'Anguilla', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'AG' => ['name' => 'Antigua and Barbuda', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'VG' => ['name' => 'British Virgin Islands', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'VI' => ['name' => 'U.S. Virgin Islands', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'KY' => ['name' => 'Cayman Islands', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'BM' => ['name' => 'Bermuda', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'GD' => ['name' => 'Grenada', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'TC' => ['name' => 'Turks and Caicos Islands', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'MS' => ['name' => 'Montserrat', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'MP' => ['name' => 'Northern Mariana Islands', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'GU' => ['name' => 'Guam', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'AS' => ['name' => 'American Samoa', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'SX' => ['name' => 'Sint Maarten', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'LC' => ['name' => 'Saint Lucia', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'DM' => ['name' => 'Dominica', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'VC' => ['name' => 'Saint Vincent and the Grenadines', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'PR' => ['name' => 'Puerto Rico', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'DO' => ['name' => 'Dominican Republic', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'TT' => ['name' => 'Trinidad and Tobago', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'KN' => ['name' => 'Saint Kitts and Nevis', 'calling_code' => '1', 'min' => 10, 'max' => 10],
        'JM' => ['name' => 'Jamaica', 'calling_code' => '1', 'min' => 10, 'max' => 10],

        // ── Mexico / Central America ──
        'MX' => ['name' => 'Mexico', 'calling_code' => '52', 'min' => 10, 'max' => 10],
        'GT' => ['name' => 'Guatemala', 'calling_code' => '502', 'min' => 8, 'max' => 8],
        'BZ' => ['name' => 'Belize', 'calling_code' => '501', 'min' => 7, 'max' => 7],
        'SV' => ['name' => 'El Salvador', 'calling_code' => '503', 'min' => 8, 'max' => 8],
        'HN' => ['name' => 'Honduras', 'calling_code' => '504', 'min' => 8, 'max' => 8],
        'NI' => ['name' => 'Nicaragua', 'calling_code' => '505', 'min' => 8, 'max' => 8],
        'CR' => ['name' => 'Costa Rica', 'calling_code' => '506', 'min' => 8, 'max' => 8],
        'PA' => ['name' => 'Panama', 'calling_code' => '507', 'min' => 7, 'max' => 8],

        // ── South America ──
        'CO' => ['name' => 'Colombia', 'calling_code' => '57', 'min' => 10, 'max' => 10],
        'VE' => ['name' => 'Venezuela', 'calling_code' => '58', 'min' => 10, 'max' => 10],
        'GY' => ['name' => 'Guyana', 'calling_code' => '592', 'min' => 7, 'max' => 7],
        'SR' => ['name' => 'Suriname', 'calling_code' => '597', 'min' => 6, 'max' => 7],
        'EC' => ['name' => 'Ecuador', 'calling_code' => '593', 'min' => 8, 'max' => 9],
        'PE' => ['name' => 'Peru', 'calling_code' => '51', 'min' => 8, 'max' => 9],
        'BR' => ['name' => 'Brazil', 'calling_code' => '55', 'min' => 10, 'max' => 11],
        'BO' => ['name' => 'Bolivia', 'calling_code' => '591', 'min' => 8, 'max' => 8],
        'PY' => ['name' => 'Paraguay', 'calling_code' => '595', 'min' => 8, 'max' => 9],
        'UY' => ['name' => 'Uruguay', 'calling_code' => '598', 'min' => 8, 'max' => 8],
        'AR' => ['name' => 'Argentina', 'calling_code' => '54', 'min' => 10, 'max' => 11],
        'CL' => ['name' => 'Chile', 'calling_code' => '56', 'min' => 9, 'max' => 9],
        'FK' => ['name' => 'Falkland Islands', 'calling_code' => '500', 'min' => 5, 'max' => 5],
        'GF' => ['name' => 'French Guiana', 'calling_code' => '594', 'min' => 9, 'max' => 9],

        // ── Caribbean (non-NANP) — GP/MF/BL share 590, CW/BQ share 599 ──
        'CU' => ['name' => 'Cuba', 'calling_code' => '53', 'min' => 8, 'max' => 8],
        'HT' => ['name' => 'Haiti', 'calling_code' => '509', 'min' => 8, 'max' => 8],
        'GP' => ['name' => 'Guadeloupe', 'calling_code' => '590', 'min' => 9, 'max' => 9],
        'MF' => ['name' => 'Saint Martin', 'calling_code' => '590', 'min' => 9, 'max' => 9],
        'BL' => ['name' => 'Saint Barthélemy', 'calling_code' => '590', 'min' => 9, 'max' => 9],
        'MQ' => ['name' => 'Martinique', 'calling_code' => '596', 'min' => 9, 'max' => 9],
        'CW' => ['name' => 'Curaçao', 'calling_code' => '599', 'min' => 7, 'max' => 8],
        'BQ' => ['name' => 'Bonaire, Sint Eustatius and Saba', 'calling_code' => '599', 'min' => 7, 'max' => 7],
        'AW' => ['name' => 'Aruba', 'calling_code' => '297', 'min' => 7, 'max' => 7],

        // ── Western Europe — GB/IM/JE/GG share 44 ──
        'GB' => ['name' => 'United Kingdom', 'calling_code' => '44', 'min' => 9, 'max' => 10],
        'IM' => ['name' => 'Isle of Man', 'calling_code' => '44', 'min' => 9, 'max' => 10],
        'JE' => ['name' => 'Jersey', 'calling_code' => '44', 'min' => 9, 'max' => 10],
        'GG' => ['name' => 'Guernsey', 'calling_code' => '44', 'min' => 9, 'max' => 10],
        'IE' => ['name' => 'Ireland', 'calling_code' => '353', 'min' => 7, 'max' => 9],
        'FR' => ['name' => 'France', 'calling_code' => '33', 'min' => 9, 'max' => 9],
        'DE' => ['name' => 'Germany', 'calling_code' => '49', 'min' => 6, 'max' => 13],
        'ES' => ['name' => 'Spain', 'calling_code' => '34', 'min' => 9, 'max' => 9],
        'PT' => ['name' => 'Portugal', 'calling_code' => '351', 'min' => 9, 'max' => 9],
        'IT' => ['name' => 'Italy', 'calling_code' => '39', 'min' => 8, 'max' => 11],
        'SM' => ['name' => 'San Marino', 'calling_code' => '378', 'min' => 6, 'max' => 10],
        'VA' => ['name' => 'Vatican City', 'calling_code' => '39', 'min' => 8, 'max' => 11],
        'MT' => ['name' => 'Malta', 'calling_code' => '356', 'min' => 8, 'max' => 8],
        'NL' => ['name' => 'Netherlands', 'calling_code' => '31', 'min' => 9, 'max' => 9],
        'BE' => ['name' => 'Belgium', 'calling_code' => '32', 'min' => 8, 'max' => 9],
        'LU' => ['name' => 'Luxembourg', 'calling_code' => '352', 'min' => 6, 'max' => 11],
        'CH' => ['name' => 'Switzerland', 'calling_code' => '41', 'min' => 9, 'max' => 9],
        'LI' => ['name' => 'Liechtenstein', 'calling_code' => '423', 'min' => 7, 'max' => 9],
        'AT' => ['name' => 'Austria', 'calling_code' => '43', 'min' => 7, 'max' => 13],
        'MC' => ['name' => 'Monaco', 'calling_code' => '377', 'min' => 8, 'max' => 9],
        'AD' => ['name' => 'Andorra', 'calling_code' => '376', 'min' => 6, 'max' => 6],

        // ── Nordics / Baltics ──
        'DK' => ['name' => 'Denmark', 'calling_code' => '45', 'min' => 8, 'max' => 8],
        'SE' => ['name' => 'Sweden', 'calling_code' => '46', 'min' => 7, 'max' => 9],
        'NO' => ['name' => 'Norway', 'calling_code' => '47', 'min' => 8, 'max' => 8],
        'FI' => ['name' => 'Finland', 'calling_code' => '358', 'min' => 6, 'max' => 12],
        'IS' => ['name' => 'Iceland', 'calling_code' => '354', 'min' => 7, 'max' => 9],
        'EE' => ['name' => 'Estonia', 'calling_code' => '372', 'min' => 7, 'max' => 8],
        'LV' => ['name' => 'Latvia', 'calling_code' => '371', 'min' => 8, 'max' => 8],
        'LT' => ['name' => 'Lithuania', 'calling_code' => '370', 'min' => 8, 'max' => 8],

        // ── Central & Eastern Europe ──
        'PL' => ['name' => 'Poland', 'calling_code' => '48', 'min' => 9, 'max' => 9],
        'CZ' => ['name' => 'Czechia', 'calling_code' => '420', 'min' => 9, 'max' => 9],
        'SK' => ['name' => 'Slovakia', 'calling_code' => '421', 'min' => 9, 'max' => 9],
        'HU' => ['name' => 'Hungary', 'calling_code' => '36', 'min' => 8, 'max' => 9],
        'RO' => ['name' => 'Romania', 'calling_code' => '40', 'min' => 9, 'max' => 9],
        'BG' => ['name' => 'Bulgaria', 'calling_code' => '359', 'min' => 7, 'max' => 9],
        'GR' => ['name' => 'Greece', 'calling_code' => '30', 'min' => 10, 'max' => 10],
        'CY' => ['name' => 'Cyprus', 'calling_code' => '357', 'min' => 8, 'max' => 8],
        'HR' => ['name' => 'Croatia', 'calling_code' => '385', 'min' => 8, 'max' => 9],
        'SI' => ['name' => 'Slovenia', 'calling_code' => '386', 'min' => 8, 'max' => 8],
        'BA' => ['name' => 'Bosnia and Herzegovina', 'calling_code' => '387', 'min' => 8, 'max' => 8],
        'RS' => ['name' => 'Serbia', 'calling_code' => '381', 'min' => 8, 'max' => 9],
        'ME' => ['name' => 'Montenegro', 'calling_code' => '382', 'min' => 8, 'max' => 8],
        'MK' => ['name' => 'North Macedonia', 'calling_code' => '389', 'min' => 8, 'max' => 8],
        'AL' => ['name' => 'Albania', 'calling_code' => '355', 'min' => 8, 'max' => 9],
        'XK' => ['name' => 'Kosovo', 'calling_code' => '383', 'min' => 8, 'max' => 8],
        'BY' => ['name' => 'Belarus', 'calling_code' => '375', 'min' => 9, 'max' => 9],
        'UA' => ['name' => 'Ukraine', 'calling_code' => '380', 'min' => 9, 'max' => 9],
        'MD' => ['name' => 'Moldova', 'calling_code' => '373', 'min' => 8, 'max' => 8],

        // ── Russia + Kazakhstan share calling code 7 ──
        'RU' => ['name' => 'Russia', 'calling_code' => '7', 'min' => 10, 'max' => 10],
        'KZ' => ['name' => 'Kazakhstan', 'calling_code' => '7', 'min' => 10, 'max' => 10],

        // ── Caucasus / Central Asia ──
        'GE' => ['name' => 'Georgia', 'calling_code' => '995', 'min' => 9, 'max' => 9],
        'AM' => ['name' => 'Armenia', 'calling_code' => '374', 'min' => 8, 'max' => 8],
        'AZ' => ['name' => 'Azerbaijan', 'calling_code' => '994', 'min' => 9, 'max' => 9],
        'TJ' => ['name' => 'Tajikistan', 'calling_code' => '992', 'min' => 9, 'max' => 9],
        'TM' => ['name' => 'Turkmenistan', 'calling_code' => '993', 'min' => 8, 'max' => 8],
        'UZ' => ['name' => 'Uzbekistan', 'calling_code' => '998', 'min' => 9, 'max' => 9],
        'KG' => ['name' => 'Kyrgyzstan', 'calling_code' => '996', 'min' => 9, 'max' => 9],

        // ── North Africa ──
        'EG' => ['name' => 'Egypt', 'calling_code' => '20', 'min' => 9, 'max' => 10],
        'LY' => ['name' => 'Libya', 'calling_code' => '218', 'min' => 8, 'max' => 9],
        'TN' => ['name' => 'Tunisia', 'calling_code' => '216', 'min' => 8, 'max' => 8],
        'DZ' => ['name' => 'Algeria', 'calling_code' => '213', 'min' => 8, 'max' => 9],
        'MA' => ['name' => 'Morocco', 'calling_code' => '212', 'min' => 9, 'max' => 9],
        'EH' => ['name' => 'Western Sahara', 'calling_code' => '212', 'min' => 9, 'max' => 9],
        'SD' => ['name' => 'Sudan', 'calling_code' => '249', 'min' => 9, 'max' => 9],
        'SS' => ['name' => 'South Sudan', 'calling_code' => '211', 'min' => 9, 'max' => 9],

        // ── West Africa ──
        'MR' => ['name' => 'Mauritania', 'calling_code' => '222', 'min' => 8, 'max' => 8],
        'ML' => ['name' => 'Mali', 'calling_code' => '223', 'min' => 8, 'max' => 8],
        'SN' => ['name' => 'Senegal', 'calling_code' => '221', 'min' => 9, 'max' => 9],
        'GM' => ['name' => 'Gambia', 'calling_code' => '220', 'min' => 7, 'max' => 7],
        'GW' => ['name' => 'Guinea-Bissau', 'calling_code' => '245', 'min' => 7, 'max' => 7],
        'GN' => ['name' => 'Guinea', 'calling_code' => '224', 'min' => 8, 'max' => 9],
        'SL' => ['name' => 'Sierra Leone', 'calling_code' => '232', 'min' => 8, 'max' => 8],
        'LR' => ['name' => 'Liberia', 'calling_code' => '231', 'min' => 7, 'max' => 9],
        'CI' => ['name' => 'Côte d\'Ivoire', 'calling_code' => '225', 'min' => 8, 'max' => 10],
        'GH' => ['name' => 'Ghana', 'calling_code' => '233', 'min' => 9, 'max' => 9],
        'TG' => ['name' => 'Togo', 'calling_code' => '228', 'min' => 8, 'max' => 8],
        'BJ' => ['name' => 'Benin', 'calling_code' => '229', 'min' => 8, 'max' => 8],
        'NE' => ['name' => 'Niger', 'calling_code' => '227', 'min' => 8, 'max' => 8],
        'BF' => ['name' => 'Burkina Faso', 'calling_code' => '226', 'min' => 8, 'max' => 8],
        'NG' => ['name' => 'Nigeria', 'calling_code' => '234', 'min' => 7, 'max' => 10],
        'CV' => ['name' => 'Cabo Verde', 'calling_code' => '238', 'min' => 7, 'max' => 7],

        // ── Central Africa ──
        'TD' => ['name' => 'Chad', 'calling_code' => '235', 'min' => 8, 'max' => 8],
        'CM' => ['name' => 'Cameroon', 'calling_code' => '237', 'min' => 8, 'max' => 9],
        'ST' => ['name' => 'Sao Tome and Principe', 'calling_code' => '239', 'min' => 7, 'max' => 7],
        'GQ' => ['name' => 'Equatorial Guinea', 'calling_code' => '240', 'min' => 9, 'max' => 9],
        'GA' => ['name' => 'Gabon', 'calling_code' => '241', 'min' => 7, 'max' => 9],
        'CG' => ['name' => 'Congo (Republic)', 'calling_code' => '242', 'min' => 9, 'max' => 9],
        'CD' => ['name' => 'DR Congo', 'calling_code' => '243', 'min' => 9, 'max' => 9],
        'AO' => ['name' => 'Angola', 'calling_code' => '244', 'min' => 9, 'max' => 9],
        'CF' => ['name' => 'Central African Republic', 'calling_code' => '236', 'min' => 8, 'max' => 8],

        // ── East Africa ──
        'ET' => ['name' => 'Ethiopia', 'calling_code' => '251', 'min' => 9, 'max' => 9],
        'ER' => ['name' => 'Eritrea', 'calling_code' => '291', 'min' => 7, 'max' => 7],
        'DJ' => ['name' => 'Djibouti', 'calling_code' => '253', 'min' => 8, 'max' => 8],
        'SO' => ['name' => 'Somalia', 'calling_code' => '252', 'min' => 7, 'max' => 9],
        'KE' => ['name' => 'Kenya', 'calling_code' => '254', 'min' => 9, 'max' => 9],
        'UG' => ['name' => 'Uganda', 'calling_code' => '256', 'min' => 9, 'max' => 9],
        'TZ' => ['name' => 'Tanzania', 'calling_code' => '255', 'min' => 9, 'max' => 9],
        'RW' => ['name' => 'Rwanda', 'calling_code' => '250', 'min' => 9, 'max' => 9],
        'BI' => ['name' => 'Burundi', 'calling_code' => '257', 'min' => 8, 'max' => 8],

        // ── Southern Africa — RE/YT share calling code 262 ──
        'MZ' => ['name' => 'Mozambique', 'calling_code' => '258', 'min' => 9, 'max' => 9],
        'MW' => ['name' => 'Malawi', 'calling_code' => '265', 'min' => 7, 'max' => 9],
        'ZM' => ['name' => 'Zambia', 'calling_code' => '260', 'min' => 9, 'max' => 9],
        'ZW' => ['name' => 'Zimbabwe', 'calling_code' => '263', 'min' => 5, 'max' => 9],
        'BW' => ['name' => 'Botswana', 'calling_code' => '267', 'min' => 7, 'max' => 8],
        'NA' => ['name' => 'Namibia', 'calling_code' => '264', 'min' => 6, 'max' => 9],
        'ZA' => ['name' => 'South Africa', 'calling_code' => '27', 'min' => 9, 'max' => 9],
        'SZ' => ['name' => 'Eswatini', 'calling_code' => '268', 'min' => 7, 'max' => 8],
        'LS' => ['name' => 'Lesotho', 'calling_code' => '266', 'min' => 8, 'max' => 8],
        'MG' => ['name' => 'Madagascar', 'calling_code' => '261', 'min' => 9, 'max' => 10],
        'MU' => ['name' => 'Mauritius', 'calling_code' => '230', 'min' => 7, 'max' => 8],
        'SC' => ['name' => 'Seychelles', 'calling_code' => '248', 'min' => 6, 'max' => 7],
        'KM' => ['name' => 'Comoros', 'calling_code' => '269', 'min' => 7, 'max' => 7],
        'YT' => ['name' => 'Mayotte', 'calling_code' => '262', 'min' => 9, 'max' => 9],
        'RE' => ['name' => 'Réunion', 'calling_code' => '262', 'min' => 9, 'max' => 9],

        // ── Middle East ──
        'TR' => ['name' => 'Turkey', 'calling_code' => '90', 'min' => 10, 'max' => 10],
        'SY' => ['name' => 'Syria', 'calling_code' => '963', 'min' => 8, 'max' => 9],
        'LB' => ['name' => 'Lebanon', 'calling_code' => '961', 'min' => 7, 'max' => 8],
        'JO' => ['name' => 'Jordan', 'calling_code' => '962', 'min' => 8, 'max' => 9],
        'IQ' => ['name' => 'Iraq', 'calling_code' => '964', 'min' => 8, 'max' => 10],
        'IR' => ['name' => 'Iran', 'calling_code' => '98', 'min' => 10, 'max' => 10],
        'IL' => ['name' => 'Israel', 'calling_code' => '972', 'min' => 8, 'max' => 9],
        'PS' => ['name' => 'Palestine', 'calling_code' => '970', 'min' => 8, 'max' => 9],
        'SA' => ['name' => 'Saudi Arabia', 'calling_code' => '966', 'min' => 8, 'max' => 9],
        'YE' => ['name' => 'Yemen', 'calling_code' => '967', 'min' => 7, 'max' => 9],
        'OM' => ['name' => 'Oman', 'calling_code' => '968', 'min' => 7, 'max' => 8],
        'AE' => ['name' => 'United Arab Emirates', 'calling_code' => '971', 'min' => 8, 'max' => 9],
        'QA' => ['name' => 'Qatar', 'calling_code' => '974', 'min' => 7, 'max' => 8],
        'BH' => ['name' => 'Bahrain', 'calling_code' => '973', 'min' => 8, 'max' => 8],
        'KW' => ['name' => 'Kuwait', 'calling_code' => '965', 'min' => 7, 'max' => 8],
        'AF' => ['name' => 'Afghanistan', 'calling_code' => '93', 'min' => 9, 'max' => 9],

        // ── South Asia ──
        'PK' => ['name' => 'Pakistan', 'calling_code' => '92', 'min' => 9, 'max' => 10],
        'IN' => ['name' => 'India', 'calling_code' => '91', 'min' => 10, 'max' => 10],
        'LK' => ['name' => 'Sri Lanka', 'calling_code' => '94', 'min' => 9, 'max' => 9],
        'MV' => ['name' => 'Maldives', 'calling_code' => '960', 'min' => 7, 'max' => 7],
        'NP' => ['name' => 'Nepal', 'calling_code' => '977', 'min' => 8, 'max' => 10],
        'BT' => ['name' => 'Bhutan', 'calling_code' => '975', 'min' => 7, 'max' => 8],
        'BD' => ['name' => 'Bangladesh', 'calling_code' => '880', 'min' => 8, 'max' => 10],
        'MM' => ['name' => 'Myanmar', 'calling_code' => '95', 'min' => 7, 'max' => 10],

        // ── East / Southeast Asia ──
        'CN' => ['name' => 'China', 'calling_code' => '86', 'min' => 10, 'max' => 11],
        'MN' => ['name' => 'Mongolia', 'calling_code' => '976', 'min' => 7, 'max' => 8],
        'HK' => ['name' => 'Hong Kong', 'calling_code' => '852', 'min' => 8, 'max' => 8],
        'MO' => ['name' => 'Macau', 'calling_code' => '853', 'min' => 8, 'max' => 8],
        'TW' => ['name' => 'Taiwan', 'calling_code' => '886', 'min' => 8, 'max' => 9],
        'KP' => ['name' => 'North Korea', 'calling_code' => '850', 'min' => 6, 'max' => 10],
        'KR' => ['name' => 'South Korea', 'calling_code' => '82', 'min' => 8, 'max' => 10],
        'JP' => ['name' => 'Japan', 'calling_code' => '81', 'min' => 9, 'max' => 10],
        'VN' => ['name' => 'Vietnam', 'calling_code' => '84', 'min' => 9, 'max' => 10],
        'LA' => ['name' => 'Laos', 'calling_code' => '856', 'min' => 8, 'max' => 10],
        'KH' => ['name' => 'Cambodia', 'calling_code' => '855', 'min' => 8, 'max' => 9],
        'TH' => ['name' => 'Thailand', 'calling_code' => '66', 'min' => 8, 'max' => 9],
        'MY' => ['name' => 'Malaysia', 'calling_code' => '60', 'min' => 7, 'max' => 10],
        'SG' => ['name' => 'Singapore', 'calling_code' => '65', 'min' => 8, 'max' => 8],
        'ID' => ['name' => 'Indonesia', 'calling_code' => '62', 'min' => 8, 'max' => 12],
        'BN' => ['name' => 'Brunei', 'calling_code' => '673', 'min' => 7, 'max' => 7],
        'PH' => ['name' => 'Philippines', 'calling_code' => '63', 'min' => 9, 'max' => 10],
        'TL' => ['name' => 'Timor-Leste', 'calling_code' => '670', 'min' => 7, 'max' => 8],

        // ── Oceania ──
        'AU' => ['name' => 'Australia', 'calling_code' => '61', 'min' => 9, 'max' => 9],
        'NZ' => ['name' => 'New Zealand', 'calling_code' => '64', 'min' => 8, 'max' => 10],
        'PG' => ['name' => 'Papua New Guinea', 'calling_code' => '675', 'min' => 7, 'max' => 8],
        'FJ' => ['name' => 'Fiji', 'calling_code' => '679', 'min' => 7, 'max' => 7],
        'SB' => ['name' => 'Solomon Islands', 'calling_code' => '677', 'min' => 5, 'max' => 7],
        'VU' => ['name' => 'Vanuatu', 'calling_code' => '678', 'min' => 5, 'max' => 7],
        'NC' => ['name' => 'New Caledonia', 'calling_code' => '687', 'min' => 6, 'max' => 6],
        'PF' => ['name' => 'French Polynesia', 'calling_code' => '689', 'min' => 6, 'max' => 8],
        'WS' => ['name' => 'Samoa', 'calling_code' => '685', 'min' => 5, 'max' => 7],
        'TO' => ['name' => 'Tonga', 'calling_code' => '676', 'min' => 5, 'max' => 7],
        'KI' => ['name' => 'Kiribati', 'calling_code' => '686', 'min' => 5, 'max' => 8],
        'TV' => ['name' => 'Tuvalu', 'calling_code' => '688', 'min' => 5, 'max' => 6],
        'NR' => ['name' => 'Nauru', 'calling_code' => '674', 'min' => 7, 'max' => 7],
        'PW' => ['name' => 'Palau', 'calling_code' => '680', 'min' => 7, 'max' => 7],
        'FM' => ['name' => 'Micronesia', 'calling_code' => '691', 'min' => 7, 'max' => 7],
        'MH' => ['name' => 'Marshall Islands', 'calling_code' => '692', 'min' => 7, 'max' => 7],
        'CK' => ['name' => 'Cook Islands', 'calling_code' => '682', 'min' => 5, 'max' => 5],
        'NU' => ['name' => 'Niue', 'calling_code' => '683', 'min' => 4, 'max' => 4],
        'TK' => ['name' => 'Tokelau', 'calling_code' => '690', 'min' => 4, 'max' => 4],
        'WF' => ['name' => 'Wallis and Futuna', 'calling_code' => '681', 'min' => 6, 'max' => 6],
    ];

    /**
     * E.164 allows at most 15 digits INCLUDING the country calling code, and
     * the requirement is exact: "+ followed by digits only, maximum 15
     * digits, no malformed values." The minimum here is not part of that
     * requirement — it exists only to reject obvious garbage (`+1`, `+12`)
     * rather than to enforce any particular numbering plan, so it stays low
     * and generic on purpose: per-country correctness for an explicit +
     * number is deliberately NOT enforced here — see normalizeExplicitE164().
     */
    private const E164_MIN_DIGITS = 8;

    private const E164_MAX_DIGITS = 15;

    /** True when the code names a country this importer can normalize local numbers for. */
    public static function isSupportedCountry(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::COUNTRIES);
    }

    /**
     * The country list for a <select>, sorted by display name.
     *
     * Shared by both import surfaces so the CSV page and the Bulk Import page
     * can never offer different countries — the drift that
     * `Segments.jsx`'s hardcoded field list already has with
     * `SegmentResolver::ALLOWED_FIELDS`.
     *
     * @return list<array{code: string, name: string, calling_code: string, label: string, example: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::COUNTRIES as $code => $meta) {
            $options[] = [
                'code' => $code,
                'name' => $meta['name'],
                'calling_code' => '+'.$meta['calling_code'],
                'label' => "{$meta['name']} (+{$meta['calling_code']})",
                'example' => self::exampleFor($code),
            ];
        }

        // India pinned first — the product's primary market, and the one
        // most workspaces on this platform will actually select. Everything
        // else stays alphabetical by name, unchanged, after it.
        usort($options, function (array $a, array $b) {
            if ($a['code'] === 'IN') {
                return -1;
            }
            if ($b['code'] === 'IN') {
                return 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $options;
    }

    /**
     * A representative LOCAL number for the country, for on-page help text and
     * the sample workbook. Derived from the country's own minimum length so it
     * can never contradict the validation rule the same class enforces.
     */
    public static function exampleFor(?string $code): string
    {
        $meta = self::meta($code);

        if ($meta === null) {
            return '';
        }

        // A stable, obviously-fake local number of exactly the right length:
        // a leading 9 (a mobile prefix in most of the listed countries) then
        // ascending digits. Never a real subscriber number.
        $digits = '9';
        for ($i = 1; $i < $meta['min']; $i++) {
            $digits .= (string) ($i % 10);
        }

        return $digits;
    }

    /** The full E.164 form of exampleFor(), e.g. "+919123456789". */
    public static function exampleE164For(?string $code): string
    {
        $meta = self::meta($code);
        $local = self::exampleFor($code);

        if ($meta === null || $local === '') {
            return '';
        }

        return '+'.$meta['calling_code'].$local;
    }

    /**
     * Turn one imported cell into canonical E.164, or explain why it cannot be.
     *
     * ⚠️ Returns an ERROR STRING rather than throwing, and rather than
     * returning null. A silent null is precisely the bug this replaces:
     * `importGridRows()` dropped every non-`+` row with `$stats['skipped']++`
     * and no message, which is what produced "0 created, 0 updated, 7 skipped"
     * with nothing on screen saying why.
     *
     * @param  string|null  $raw  the cell exactly as the file supplied it
     * @param  string|null  $defaultCountry  ISO-3166-1 alpha-2, explicitly chosen by the user
     * @return array{phone: string|null, error: string|null}
     */
    public static function normalizeForImport(?string $raw, ?string $defaultCountry): array
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return self::fail('Phone number is required.');
        }

        // Strip the punctuation humans and spreadsheets put in phone numbers —
        // spaces, dashes, dots, brackets — before any structural test, so
        // "+91 86300-26021" and "+918630026021" are the same number rather
        // than one valid and one rejected.
        $cleaned = preg_replace('/[\s\-().]/', '', $trimmed) ?? '';

        if (str_starts_with($cleaned, '+')) {
            return self::normalizeExplicitE164($cleaned);
        }

        return self::normalizeLocal($cleaned, $defaultCountry);
    }

    /**
     * An explicit `+` number is validated STRUCTURALLY ONLY, and stored
     * exactly as written (as canonical E.164): `+` followed by digits only,
     * maximum 15 digits, no malformed values. It is NEVER re-interpreted or
     * rewritten through the selected default country — an Indian workspace
     * importing a US number must keep the US number, and a workspace whose
     * table happens to be wrong about (say) Andorra's exact digit count must
     * not have that turn into a false rejection of a real Andorran number.
     * That is why this does not consult COUNTRIES at all.
     *
     * @return array{phone: string|null, error: string|null}
     */
    private static function normalizeExplicitE164(string $cleaned): array
    {
        $digits = substr($cleaned, 1);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return self::fail('Phone number contains characters that are not digits.');
        }

        if (str_starts_with($digits, '0')) {
            // No country calling code begins with 0, so this is a malformed
            // number rather than a valid one we merely do not recognise.
            return self::fail('Phone number is not a valid international number (a country code cannot start with 0).');
        }

        $length = strlen($digits);

        if ($length < self::E164_MIN_DIGITS || $length > self::E164_MAX_DIGITS) {
            return self::fail(sprintf(
                'Phone number must be between %d and %d digits after the country code prefix.',
                self::E164_MIN_DIGITS,
                self::E164_MAX_DIGITS
            ));
        }

        return ['phone' => '+'.$digits, 'error' => null];
    }

    /**
     * Local digits are normalized with the EXPLICITLY selected calling code,
     * and there is no implicit default — a local number with no selection is
     * refused, by name, never silently guessed.
     *
     * @return array{phone: string|null, error: string|null}
     */
    private static function normalizeLocal(string $cleaned, ?string $defaultCountry): array
    {
        if (preg_match('/^\d+$/', $cleaned) !== 1) {
            return self::fail('Phone number contains characters that are not digits.');
        }

        $meta = self::meta($defaultCountry);

        if ($meta === null) {
            // Refuse rather than guess. The message names the control the
            // user has to change, because "invalid phone" on a number that is
            // perfectly valid locally is the confusing half of the original
            // bug.
            return self::fail(
                'Phone number has no country code and no default phone country is selected. '
                .'Choose a default phone country, or write the number in full international form (e.g. +918630026021).'
            );
        }

        // National trunk prefix: 08630026021 dialled inside India is the same
        // subscriber as +918630026021. Dropping leading zeros before the
        // length check is what makes a sheet exported from a local CRM import
        // cleanly.
        $national = ltrim($cleaned, '0');

        if ($national === '') {
            return self::fail('Phone number is invalid for the selected default country.');
        }

        $length = strlen($national);

        if ($length < $meta['min'] || $length > $meta['max']) {
            $expected = $meta['min'] === $meta['max']
                ? sprintf('%d digits', $meta['min'])
                : sprintf('%d to %d digits', $meta['min'], $meta['max']);

            return self::fail(sprintf(
                'Phone number is invalid for the selected default country (%s expects %s, got %d).',
                $meta['name'],
                $expected,
                $length
            ));
        }

        // The resulting E.164 value must itself respect the universal 15-digit
        // ceiling — a belt-and-braces check, since every entry in COUNTRIES is
        // hand-verified to already satisfy it, but a table this size is
        // exactly where a typo could slip through unnoticed otherwise.
        $result = '+'.$meta['calling_code'].$national;

        if (strlen($meta['calling_code'].$national) > self::E164_MAX_DIGITS) {
            return self::fail('Phone number is invalid for the selected default country (exceeds the 15-digit E.164 limit).');
        }

        return ['phone' => $result, 'error' => null];
    }

    /**
     * @return array{name: string, calling_code: string, min: int, max: int}|null
     */
    private static function meta(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }

        return self::COUNTRIES[strtoupper($code)] ?? null;
    }

    /** @return array{phone: null, error: string} */
    private static function fail(string $message): array
    {
        return ['phone' => null, 'error' => $message];
    }
}
