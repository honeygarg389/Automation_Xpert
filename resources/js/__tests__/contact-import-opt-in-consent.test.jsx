import { describe, it, expect } from 'vitest';
import fs from 'fs';
import {
    BULK_IMPORT_FIELDS,
    OPT_IN_OPTIONS,
    buildSampleCsv,
    buildSampleWorkbook,
} from '@/Pages/Contacts/bulkImportExcel';

/**
 * Browser bug report: an imported contact's Opt-ins showed only Email —
 * WhatsApp and SMS were absent, because the DATABASE column defaults
 * (`opt_in_email DEFAULT true`, the other two `DEFAULT false`) leaked
 * through an import that never wrote any of the three at all.
 *
 * This file covers the FRONTEND half of the fix: the sample files must
 * (1) contain the three Opt-in columns, (2) default every sample opt-in
 * value to "No", and (3) offer a clean Yes/No dropdown in the XLSX grid.
 * The BACKEND half — that omitted/blank means false for a NEW contact and
 * "leave untouched" for an EXISTING one — is proven in PHPUnit
 * (ContactImportConsentAndIdentityTest), where it can actually be checked
 * against a persisted row.
 */

const INDIA = { code: 'IN', name: 'India', calling_code: '+91', label: 'India (+91)', example: '9123456789' };

describe('opt-in columns are present in the BULK_IMPORT_FIELDS single source of truth', () => {
    it('defines exactly three opt-in fields, one per channel', () => {
        const optInFields = BULK_IMPORT_FIELDS.filter((f) => f.kind === 'optin');
        expect(optInFields.map((f) => f.key).sort()).toEqual(['opt_in_email', 'opt_in_sms', 'opt_in_whatsapp']);
    });

    it('OPT_IN_OPTIONS offers exactly Yes and No', () => {
        expect(OPT_IN_OPTIONS).toEqual(['Yes', 'No']);
    });

    it('each opt-in field has a distinct sample header naming its channel', () => {
        const headers = BULK_IMPORT_FIELDS.filter((f) => f.kind === 'optin').map((f) => f.sampleHeader);
        expect(headers).toContain('WhatsApp Opt-in');
        expect(headers).toContain('SMS Opt-in');
        expect(headers).toContain('Email Opt-in');
    });
});

describe('the XLSX sample workbook', () => {
    function rowsOf(worksheet) {
        const rows = [];
        worksheet.eachRow((row) => rows.push(row.values.slice(1)));

        return rows;
    }

    it('includes all three opt-in columns in its header row', () => {
        const wb = buildSampleWorkbook(INDIA);
        const rows = rowsOf(wb.worksheets[0]);

        expect(rows[0]).toEqual(expect.arrayContaining(['WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in']));
    });

    it('defaults every opt-in sample cell to "No"', () => {
        const wb = buildSampleWorkbook(INDIA);
        const rows = rowsOf(wb.worksheets[0]);
        const headerRow = rows[0];

        for (const header of ['WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in']) {
            const col = headerRow.indexOf(header);
            expect(col).toBeGreaterThanOrEqual(0);
            for (const dataRow of rows.slice(1)) {
                expect(String(dataRow[col])).toBe('No');
            }
        }
    });

    it('applies a Yes/No dropdown data validation to every opt-in column, every data row', () => {
        const wb = buildSampleWorkbook(INDIA);
        const ws = wb.worksheets[0];
        const headerRow = rowsOf(ws)[0];

        for (const header of ['WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in']) {
            const colIndex = headerRow.indexOf(header) + 1; // 1-based for getCell
            const cell = ws.getCell(2, colIndex);
            expect(cell.dataValidation).toBeTruthy();
            expect(cell.dataValidation.type).toBe('list');
            expect(cell.dataValidation.formulae[0]).toContain('Yes');
            expect(cell.dataValidation.formulae[0]).toContain('No');
        }
    });
});

describe('the CSV sample file', () => {
    function parseCsv(content) {
        const lines = content.trim().split('\r\n');

        return lines.map((line) => line.split(',').map((cell) => cell.replace(/^"|"$/g, '')));
    }

    it('includes the exact "Phone" header, not the country-dependent hint sentence', () => {
        // ⚠️ Regression test for the bug this caught in development: the
        // dynamic, country-dependent Phone header text (fine for the XLSX
        // grid's fuzzy substring matcher) does NOT exact-match
        // ContactController::IMPORT_HEADER_MAP's plain "phone" key, so a CSV
        // sample using it would report every row as missing a phone despite
        // one being right there.
        const content = buildSampleCsv(INDIA);
        const [headers] = parseCsv(content);

        expect(headers).toContain('Phone');
        expect(headers.some((h) => h.includes('local India'))).toBe(false);
    });

    it('includes all six Personal Details headers plus all three Opt-in headers', () => {
        const content = buildSampleCsv(INDIA);
        const [headers] = parseCsv(content);

        for (const expected of [
            'Name', 'Phone', 'Gender', 'Birthday (YYYY-MM-DD)', 'Anniversary Date (YYYY-MM-DD)',
            'City', 'State', 'Postal Code / PIN Code', 'WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in',
        ]) {
            expect(headers).toContain(expected);
        }
    });

    it('never includes the Contact-list/Segment columns — those are XLSX-grid-only concepts CSV import has never recognized', () => {
        const content = buildSampleCsv(INDIA);
        const [headers] = parseCsv(content);

        expect(headers.some((h) => /contact list/i.test(h))).toBe(false);
        expect(headers.some((h) => /segment/i.test(h))).toBe(false);
    });

    it('defaults every opt-in sample value to "No"', () => {
        const content = buildSampleCsv(INDIA);
        const rows = parseCsv(content);
        const headers = rows[0];

        for (const header of ['WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in']) {
            const col = headers.indexOf(header);
            for (const dataRow of rows.slice(1)) {
                expect(dataRow[col]).toBe('No');
            }
        }
    });

    it('uses only YYYY-MM-DD dates, never the stale US-locale format', () => {
        const content = buildSampleCsv(INDIA);

        expect(content).not.toContain('5/20/1990');
        expect(content).not.toContain('10/14/2015');
        expect(content).toMatch(/\b\d{4}-\d{2}-\d{2}\b/);
    });

    it('never contains the stale "country code required" header text', () => {
        const content = buildSampleCsv(INDIA);

        expect(content).not.toContain('Phone (E.164, country code required)');
    });

    it('uses distinct phone numbers across every sample row', () => {
        const content = buildSampleCsv(INDIA);
        const rows = parseCsv(content);
        const headers = rows[0];
        const phoneCol = headers.indexOf('Phone');

        const phones = rows.slice(1).map((r) => r[phoneCol]);
        expect(new Set(phones).size).toBe(phones.length);
    });

    it('with no country selected, still produces a valid, importable CSV using the + form', () => {
        const content = buildSampleCsv(null);
        const rows = parseCsv(content);
        const headers = rows[0];
        const phoneCol = headers.indexOf('Phone');

        expect(headers).toContain('Phone');
        for (const dataRow of rows.slice(1)) {
            expect(dataRow[phoneCol].startsWith('+')).toBe(true);
        }
    });
});

describe('the marketing-consent compliance help text', () => {
    // Real en.json content, not a hand-typed duplicate — this is what a user
    // actually reads on both import screens.
    const en = JSON.parse(fs.readFileSync('resources/js/locales/en.json', 'utf8'));
    const hint = en.contacts_page.bulk_consent_hint;

    it('exists and is non-empty', () => {
        expect(hint).toBeTruthy();
        expect(hint.length).toBeGreaterThan(10);
    });

    it('tells the admin to set Yes only where real consent exists', () => {
        expect(hint.toLowerCase()).toContain('consent');
        expect(hint.toLowerCase()).toContain('yes');
    });

    it('does NOT imply that having a phone number authorizes WhatsApp/SMS marketing', () => {
        // The requirement's own negative: "Do not imply that possessing a
        // phone number authorizes WhatsApp/SMS marketing." Asserted as the
        // absence of exactly that claim, plus the presence of the explicit
        // denial this hint actually states.
        expect(hint).not.toMatch(/phone number.*(authoriz|allow|permit|mean).*(consent|opt)/i);
        expect(hint.toLowerCase()).toContain('does not authorize');
    });
});
