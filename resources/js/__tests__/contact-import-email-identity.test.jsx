import { describe, it, expect } from 'vitest';
import { BULK_IMPORT_FIELDS, buildSampleCsv, buildSampleWorkbook } from '@/Pages/Contacts/bulkImportExcel';

/**
 * Frontend half of the Email column addition — the shared field list
 * (BULK_IMPORT_FIELDS) both samples and the XLSX grid are built from, and the
 * two downloadable sample files. The backend rules (identity resolution,
 * conflict detection, workspace isolation) are proven in PHPUnit
 * (ContactImportEmailIdentityTest), where they can be checked against
 * persisted rows.
 */

const INDIA = { code: 'IN', name: 'India', calling_code: '+91', label: 'India (+91)', example: '9123456789' };

describe('Email in the BULK_IMPORT_FIELDS single source of truth', () => {
    it('is defined, immediately after phone', () => {
        const keys = BULK_IMPORT_FIELDS.map((f) => f.key);
        const phoneIndex = keys.indexOf('phone');
        const emailIndex = keys.indexOf('email');

        expect(emailIndex).toBeGreaterThan(-1);
        expect(emailIndex).toBe(phoneIndex + 1);
    });

    it('has a static sample header stating it is optional', () => {
        const emailField = BULK_IMPORT_FIELDS.find((f) => f.key === 'email');

        expect(emailField.sampleHeader).toBe('Email (optional)');
    });
});

describe('the XLSX sample workbook — Email column', () => {
    function rowsOf(worksheet) {
        const rows = [];
        worksheet.eachRow((row) => rows.push(row.values.slice(1)));

        return rows;
    }

    it('places an Email (optional) column immediately after Phone', () => {
        const wb = buildSampleWorkbook(INDIA);
        const headers = rowsOf(wb.worksheets[0])[0];
        const phoneCol = headers.findIndex((h) => String(h).startsWith('Phone'));

        expect(headers[phoneCol + 1]).toBe('Email (optional)');
    });

    it('gives the two example rows distinct, valid emails', () => {
        const wb = buildSampleWorkbook(INDIA);
        const rows = rowsOf(wb.worksheets[0]);
        const emailCol = rows[0].indexOf('Email (optional)');

        const emails = rows.slice(1).map((r) => r[emailCol]);
        expect(new Set(emails).size).toBe(emails.length);
        for (const email of emails) {
            expect(email).toMatch(/^[^\s@]+@[^\s@]+\.[^\s@]+$/);
        }
    });
});

describe('the CSV sample — Email column', () => {
    function parseCsv(content) {
        const lines = content.trim().split('\r\n');

        return lines.map((line) => line.split(',').map((cell) => cell.replace(/^"|"$/g, '')));
    }

    it('places Email (optional) immediately after Phone', () => {
        const content = buildSampleCsv(INDIA);
        const [headers] = parseCsv(content);
        const phoneCol = headers.indexOf('Phone');

        expect(headers[phoneCol + 1]).toBe('Email (optional)');
    });

    it('gives the two example rows distinct, valid emails', () => {
        const content = buildSampleCsv(INDIA);
        const rows = parseCsv(content);
        const emailCol = rows[0].indexOf('Email (optional)');

        const emails = rows.slice(1).map((r) => r[emailCol]);
        expect(new Set(emails).size).toBe(emails.length);
        for (const email of emails) {
            expect(email).toMatch(/^[^\s@]+@[^\s@]+\.[^\s@]+$/);
        }
    });

    it('still produces a valid sample with no country selected', () => {
        const content = buildSampleCsv(null);
        const rows = parseCsv(content);
        const emailCol = rows[0].indexOf('Email (optional)');

        expect(emailCol).toBeGreaterThan(-1);
        expect(rows[1][emailCol]).toMatch(/^[^\s@]+@[^\s@]+\.[^\s@]+$/);
    });
});
