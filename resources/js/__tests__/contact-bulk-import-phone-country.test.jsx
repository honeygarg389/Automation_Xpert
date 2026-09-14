import { describe, it, expect } from 'vitest';
import {
    BULK_IMPORT_FIELDS,
    sampleHeaderText,
    buildSampleWorkbook,
} from '@/Pages/Contacts/bulkImportExcel';

/**
 * Bug report: a real XLSX import of local Indian numbers (e.g. 8630026021,
 * no +91) reported "0 created, 0 updated, 7 skipped" with no visible reason,
 * because the sample file and on-page copy told the user "country code
 * required" with no way to say which country's local numbers were being
 * imported.
 *
 * Requirement 7 + 10: the downloadable sample workbook and its header text
 * must reflect the SELECTED default country, not a fixed "must have +"
 * instruction, and the artefact a user actually downloads (not just the
 * strings fed into it) has to say so — a header string could be right while
 * the sheet that gets written is wrong, so these tests read the built
 * workbook back.
 */

const PHONE_FIELD = BULK_IMPORT_FIELDS.find((f) => f.key === 'phone');

const INDIA = { code: 'IN', name: 'India', calling_code: '+91', label: 'India (+91)', example: '9123456789' };
const US = { code: 'US', name: 'United States', calling_code: '+1', label: 'United States (+1)', example: '9123456789' };

/** Read a worksheet's rows back as plain arrays of cell display values. */
function rowsOf(worksheet) {
    const rows = [];
    worksheet.eachRow((row) => {
        // ExcelJS's row.values is 1-indexed with a leading undefined slot.
        rows.push(row.values.slice(1));
    });
    return rows;
}

describe('bulk import phone column reflects the selected default country', () => {
    it('phone header names the selected country when one is chosen', () => {
        const header = sampleHeaderText(PHONE_FIELD, INDIA);
        expect(header).toContain('India');
        expect(header.toLowerCase()).toContain('local');
    });

    it('phone header falls back to "+country code required" with no selection', () => {
        const header = sampleHeaderText(PHONE_FIELD, null);
        expect(header).toContain('+country code required');
        expect(header).not.toContain('India');
    });

    it('two different countries produce two different phone headers', () => {
        expect(sampleHeaderText(PHONE_FIELD, INDIA)).not.toBe(sampleHeaderText(PHONE_FIELD, US));
    });

    it('the downloaded workbook header row uses the selected country phone header', () => {
        const wb = buildSampleWorkbook(INDIA);
        const rows = rowsOf(wb.worksheets[0]);
        const phoneCol = BULK_IMPORT_FIELDS.findIndex((f) => f.key === 'phone');

        expect(rows[0][phoneCol]).toBe(sampleHeaderText(PHONE_FIELD, INDIA));
        expect(rows[0][phoneCol]).toContain('India');
    });

    it('the workbook example row contains a LOCAL number when a country is selected', () => {
        const wb = buildSampleWorkbook(INDIA);
        const rows = rowsOf(wb.worksheets[0]);
        const phoneCol = BULK_IMPORT_FIELDS.findIndex((f) => f.key === 'phone');

        // Row index 1 = first data row (row index 0 is the header).
        const localCell = String(rows[1][phoneCol]);
        expect(localCell).toBe(INDIA.example);
        expect(localCell.startsWith('+')).toBe(false);
    });

    it('the workbook also includes an explicit +country-code example row, to teach BOTH accepted forms', () => {
        const wb = buildSampleWorkbook(INDIA);
        const rows = rowsOf(wb.worksheets[0]);
        const phoneCol = BULK_IMPORT_FIELDS.findIndex((f) => f.key === 'phone');

        const phoneCells = rows.slice(1).map((r) => String(r[phoneCol]));
        expect(phoneCells.some((c) => c.startsWith('+'))).toBe(true);
        expect(phoneCells.some((c) => !c.startsWith('+'))).toBe(true);
    });

    it('with no country selected, the workbook has no local-number example row', () => {
        const wbWithCountry = buildSampleWorkbook(INDIA);
        const wbNoCountry = buildSampleWorkbook(null);

        const rowsWith = rowsOf(wbWithCountry.worksheets[0]);
        const rowsWithout = rowsOf(wbNoCountry.worksheets[0]);

        // One fewer data row when there's no local example to show.
        expect(rowsWithout.length).toBe(rowsWith.length - 1);

        const phoneCol = BULK_IMPORT_FIELDS.findIndex((f) => f.key === 'phone');
        const phoneCells = rowsWithout.slice(1).map((r) => String(r[phoneCol]));
        expect(phoneCells.every((c) => c.startsWith('+'))).toBe(true);
    });

    it('non-phone sample columns are unaffected by the selected country', () => {
        const wbIndia = buildSampleWorkbook(INDIA);
        const wbUs = buildSampleWorkbook(US);
        const rowsIndia = rowsOf(wbIndia.worksheets[0]);
        const rowsUs = rowsOf(wbUs.worksheets[0]);

        const genderCol = BULK_IMPORT_FIELDS.findIndex((f) => f.key === 'gender');
        expect(rowsIndia[0][genderCol]).toBe(rowsUs[0][genderCol]);
        expect(rowsIndia[0][genderCol]).toBe('Gender');
    });
});
