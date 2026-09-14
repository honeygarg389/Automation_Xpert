import ExcelJS from 'exceljs';
import i18n from '@/i18n';

/** Canonical Gender values accepted by the backend (Contact::GENDERS), in friendly-label form. */
export const GENDER_OPTIONS = ['Male', 'Female', 'Non-binary / Other', 'Prefer not to say'];

/**
 * The only values ContactService::normalizeOptInInput() accepts for an opt-in
 * column, in the grid-dropdown-friendly form. The backend also accepts
 * True/False and 1/0 (case-insensitive) — the dropdown offers only Yes/No
 * because that is the unambiguous, self-explanatory pair; a typed value
 * outside this list (including True/False/1/0, which ARE still valid input)
 * is still accepted on submit, `allowInvalid: true` on the dropdown exists
 * precisely so the grid does not silently reject a value the backend would
 * accept.
 */
export const OPT_IN_OPTIONS = ['Yes', 'No'];

/**
 * Single source of truth for every Bulk Import XLSX surface: the
 * Handsontable grid's columns, the downloadable sample workbook's header
 * row, and the uploaded-workbook header parser's recognized column names.
 * Adding or renaming a column here is the only change needed to keep the
 * grid, the sample file, and the parser consistent — before this existed,
 * the three were three separate hardcoded lists that had already drifted
 * from each other (the grid's "Contact list" header vs. the sample
 * workbook's "Contact list (tag name, optional)" is the same column, two
 * different hardcoded strings).
 *
 * `key`          payload field name sent to the backend (client.contacts.bulk-store).
 * `sampleHeader` exact text written into the downloadable sample workbook's header row.
 * `gridHeader()` the (possibly i18n-translated) Handsontable column label — a
 *                function, not a plain string, because i18n.t() must run
 *                once the active locale is known, not at module-load time.
 * `patterns`     normalized (lowercase, punctuation-stripped, single-spaced)
 *                strings headerIndex() matches an uploaded workbook's header
 *                cell against.
 * `kind`         drives both the Handsontable column type and how a raw
 *                cell value is read: 'text' | 'gender' | 'tag' | 'segment'.
 *
 * Personal Details columns sit after the core Name/Phone columns and
 * before the assignment columns (Contact list, Segment) — matching the
 * existing grid's own convention of core identity first, assignment last.
 */
export const BULK_IMPORT_FIELDS = [
    { key: 'name', sampleHeader: 'Name', gridHeader: () => i18n.t('common.name'), patterns: ['name', 'fullname', 'full name'], kind: 'text' },
    // ⚠️ sampleHeader is a FUNCTION of the selected default country for this
    // column only. It used to read "Phone (E.164, country code required)",
    // which was the instruction that made people hand-write +91 in front of
    // every number — or, far more often, not bother, and have the whole row
    // silently skipped. The header now states the rule that actually applies
    // to the file they are about to fill in.
    {
        key: 'phone',
        sampleHeader: (country) =>
            country
                ? `Phone (local ${country.name} numbers OK, or +country code)`
                : 'Phone (+country code required — no default country selected)',
        gridHeader: () => i18n.t('contacts_page.phone_e164'),
        patterns: ['phone', 'mobile', 'tel', 'e164', 'number'],
        kind: 'text',
    },
    // Immediately after Phone — optional. ContactService::normalizeEmailInput()
    // trims, lowercases, and strictly validates it; resolveContactIdentity()
    // is what a supplied email actually does for matching/updating an
    // existing contact (see ContactService.php for the full identity rules).
    // A blank/omitted cell is simply "no email supplied" — it is never
    // required, and never implies Email Opt-in (that stays a separate,
    // explicit column).
    {
        key: 'email',
        sampleHeader: 'Email (optional)',
        gridHeader: () => i18n.t('contacts_page.bulk_col_email'),
        patterns: ['email', 'email address', 'e mail'],
        kind: 'text',
    },
    { key: 'gender', sampleHeader: 'Gender', gridHeader: () => i18n.t('contacts_page.bulk_col_gender'), patterns: ['gender'], kind: 'gender' },
    { key: 'birthday', sampleHeader: 'Birthday (YYYY-MM-DD)', gridHeader: () => i18n.t('contacts_page.bulk_col_birthday'), patterns: ['birthday', 'date of birth', 'dob'], kind: 'text' },
    { key: 'anniversary_date', sampleHeader: 'Anniversary Date (YYYY-MM-DD)', gridHeader: () => i18n.t('contacts_page.bulk_col_anniversary'), patterns: ['anniversary date', 'anniversary', 'wedding anniversary'], kind: 'text' },
    { key: 'city', sampleHeader: 'City', gridHeader: () => i18n.t('contacts_page.bulk_col_city'), patterns: ['city'], kind: 'text' },
    { key: 'state', sampleHeader: 'State', gridHeader: () => i18n.t('contacts_page.bulk_col_state'), patterns: ['state'], kind: 'text' },
    { key: 'postal_code', sampleHeader: 'Postal Code / PIN Code', gridHeader: () => i18n.t('contacts_page.bulk_col_postal_code'), patterns: ['postal code pin code', 'postal code', 'pin code', 'zip code', 'zipcode', 'zip'], kind: 'text' },
    // ── Marketing consent — Yes/No/True/False/1/0, EXPLICIT only ─────────
    //
    // ⚠️ Omitted or blank means "no consent stated": a NEW contact gets
    // `false` on all three, never a silent true — a phone number is not
    // marketing consent, and this column is where that consent is actually
    // recorded, not inferred from the presence of a Phone column. See
    // ContactService::resolveOptInFields() for the full create-vs-update
    // rule (existing contacts keep their current value when this column is
    // blank/omitted; only an explicit value here changes it).
    {
        key: 'opt_in_whatsapp',
        sampleHeader: 'WhatsApp Opt-in',
        gridHeader: () => i18n.t('contacts_page.bulk_col_whatsapp_opt_in'),
        patterns: ['whatsapp opt in', 'opt in whatsapp', 'whatsapp optin'],
        kind: 'optin',
    },
    {
        key: 'opt_in_sms',
        sampleHeader: 'SMS Opt-in',
        gridHeader: () => i18n.t('contacts_page.bulk_col_sms_opt_in'),
        patterns: ['sms opt in', 'opt in sms', 'sms optin'],
        kind: 'optin',
    },
    {
        key: 'opt_in_email',
        sampleHeader: 'Email Opt-in',
        gridHeader: () => i18n.t('contacts_page.bulk_col_email_opt_in'),
        patterns: ['email opt in', 'opt in email', 'email optin'],
        kind: 'optin',
    },
    { key: 'tag', sampleHeader: 'Contact list (tag name, optional)', gridHeader: () => i18n.t('contacts_page.bulk_col_contact_list'), patterns: ['contact list', 'contact lists', 'list', 'tag', 'tags'], kind: 'tag' },
    { key: 'segment', sampleHeader: 'Segment (static segment name, optional)', gridHeader: () => i18n.t('contacts_page.bulk_col_segment'), patterns: ['segment', 'segments'], kind: 'segment' },
];

/** @param {number} rows @param {number} cols */
export function emptyMatrix(rows, cols = BULK_IMPORT_FIELDS.length) {
    return Array.from({ length: rows }, () => Array.from({ length: cols }, () => ''));
}

function cellDisplayValue(cell) {
    if (cell == null || cell.value === null || cell.value === undefined) {
        return '';
    }
    const v = cell.value;
    if (typeof v === 'object') {
        if (v.richText) {
            return v.richText.map((p) => p.text).join('');
        }
        if (v.text) {
            return String(v.text);
        }
        if (v.result !== undefined) {
            return String(v.result);
        }
        if (v.hyperlink && v.text !== undefined) {
            return String(v.text);
        }
    }
    return String(v);
}

function headerIndex(headers, patterns) {
    const norm = headers.map((h) =>
        String(h ?? '')
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim(),
    );
    for (let i = 0; i < norm.length; i++) {
        const h = norm[i];
        for (const p of patterns) {
            if (h === p || h.includes(p) || p.includes(h)) {
                return i;
            }
        }
    }
    return -1;
}

function tagNameFromRaw(raw, tags) {
    const s = String(raw ?? '').trim();
    if (!s) {
        return '';
    }
    const asNum = Number.parseInt(s, 10);
    if (!Number.isNaN(asNum) && String(asNum) === s) {
        const t = tags.find((x) => x.id === asNum);
        return t ? t.name : '';
    }
    const hit = tags.find((t) => t.name.toLowerCase() === s.toLowerCase());
    return hit ? hit.name : s;
}

function segmentNameFromRaw(raw, segments) {
    const s = String(raw ?? '').trim();
    if (!s) {
        return '';
    }
    const asNum = Number.parseInt(s, 10);
    if (!Number.isNaN(asNum) && String(asNum) === s) {
        const seg = segments.find((x) => x.id === asNum);
        return seg ? seg.name : '';
    }
    const hit = segments.find((seg) => seg.name.toLowerCase() === s.toLowerCase());
    return hit ? hit.name : s;
}

/**
 * Read the first worksheet into Handsontable rows, one array slot per
 * BULK_IMPORT_FIELDS entry (in that order) — currently:
 * [name, phone, gender, birthday, anniversary_date, city, state, postal_code, tagName, segmentName].
 * @param {ArrayBuffer} arrayBuffer
 * @param {Array<{id:number,name:string}>} tags
 * @param {Array<{id:number,name:string}>} segments
 * @returns {string[][] | null}
 */
export async function parseWorkbookToMatrix(arrayBuffer, tags, segments) {
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(arrayBuffer);
    const ws = wb.worksheets[0];
    if (!ws) {
        return null;
    }

    let maxCol = 0;
    ws.eachRow((row) => {
        maxCol = Math.max(maxCol, row.cellCount);
    });
    if (maxCol < 1) {
        return null;
    }

    const matrix = [];
    ws.eachRow((row) => {
        const r = [];
        for (let c = 1; c <= maxCol; c++) {
            r.push(cellDisplayValue(row.getCell(c)));
        }
        matrix.push(r);
    });

    if (!matrix.length) {
        return null;
    }

    const headers = matrix[0].map((c) => String(c ?? ''));
    const columnIndexByKey = {};
    BULK_IMPORT_FIELDS.forEach((field) => {
        columnIndexByKey[field.key] = headerIndex(headers, field.patterns);
    });

    if (columnIndexByKey.phone < 0) {
        return null;
    }

    const out = [];
    for (let i = 1; i < matrix.length; i++) {
        const row = matrix[i] || [];
        const values = BULK_IMPORT_FIELDS.map((field) => {
            const idx = columnIndexByKey[field.key];
            const raw = idx >= 0 ? row[idx] : '';
            if (field.kind === 'tag') {
                return tagNameFromRaw(raw, tags);
            }
            if (field.kind === 'segment') {
                return segmentNameFromRaw(raw, segments);
            }
            return String(raw ?? '').trim();
        });
        if (values.every((v) => !v)) {
            continue;
        }
        out.push(values);
    }

    return out.length ? out : emptyMatrix(10);
}

/** The header text for one column, given the selected default country (may be null). */
export function sampleHeaderText(field, country) {
    return typeof field.sampleHeader === 'function' ? field.sampleHeader(country) : field.sampleHeader;
}

/** Sample row values, in BULK_IMPORT_FIELDS order, for the downloadable workbook. */
const SAMPLE_ROW_VALUES = {
    name: 'Jane Doe',
    phone: '+15551234567',
    // Used by the FIRST example row only — the second ("Sam Taylor") row
    // gets its own distinct address inline where that row is built, the same
    // pattern already used for phone and name, so the two sample rows never
    // share an email the way two real imported rows never should either.
    email: 'jane.doe@example.com',
    gender: 'Female',
    birthday: '1990-05-20',
    anniversary_date: '2015-10-14',
    city: 'Bengaluru',
    state: 'Karnataka',
    postal_code: '560001',
    // Defaulted to 'No', deliberately: a sample row must not model "assume
    // consent" as the normal case. Anyone editing this file to build their
    // own import sees No as the starting point and has to make a conscious
    // choice to change it to Yes — the same rule the real import enforces.
    opt_in_whatsapp: 'No',
    opt_in_sms: 'No',
    opt_in_email: 'No',
    tag: '',
    segment: '',
};

/**
 * Build (but do not download) the sample workbook.
 *
 * Split out from downloadSampleWorkbook() so a test can assert the CONTENTS
 * of the file a user receives rather than the strings that went into it —
 * the blob/anchor download step is untestable in jsdom, and asserting the
 * inputs would prove nothing about the sheet.
 *
 * @param {{code:string,name:string,calling_code:string,label:string,example:string}|null} country
 *        The selected default phone country, or null when none is selected.
 */
export function buildSampleWorkbook(country = null) {
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Contacts');
    ws.addRow(BULK_IMPORT_FIELDS.map((f) => sampleHeaderText(f, country)));

    // Force the Phone column to Excel's Text format BEFORE any row is
    // written into it. Excel auto-detects a leading "+" (like a leading "="
    // or "-") as the start of an arithmetic expression on both file-open and
    // on later manual edits; without this, "+919123456789" can silently
    // become a formula error or get mangled the moment a real user re-enters
    // or edits a phone cell in the downloaded sample — a genuinely
    // unimportable file that LOOKS fine until reopened. `numFmt: '@'`
    // (Excel's own Text format code) is a native ExcelJS API, not an
    // invented workaround, and is applied to the WHOLE column (not just the
    // example rows) so it also protects every row the user adds afterward.
    const phoneColumnNumber = BULK_IMPORT_FIELDS.findIndex((f) => f.key === 'phone') + 1;
    if (phoneColumnNumber > 0) {
        ws.getColumn(phoneColumnNumber).numFmt = '@';
    }

    // TWO sample rows when a country is selected, and the pair is the point:
    // row 2 is a LOCAL number in the selected country, row 3 is an explicit
    // +country-code number from somewhere else. Seeing both in the file is
    // what teaches the rule — a single fully-qualified example is what the old
    // sample had, and it read as "you must always write +country code".
    const localExample = country?.example ?? '';

    if (localExample) {
        ws.addRow(BULK_IMPORT_FIELDS.map((f) => (f.key === 'phone' ? localExample : SAMPLE_ROW_VALUES[f.key] ?? '')));
    }

    ws.addRow(
        BULK_IMPORT_FIELDS.map((f) => {
            if (f.key === 'phone') return SAMPLE_ROW_VALUES.phone;
            if (f.key === 'name') return 'Sam Taylor';
            if (f.key === 'email') return 'sam.taylor@example.com';
            return SAMPLE_ROW_VALUES[f.key] ?? '';
        }),
    );

    ws.getRow(1).font = { bold: true };

    // Gender dropdown on the sample rows, via ExcelJS's existing
    // cell.dataValidation API — not a separate/invented spreadsheet
    // mechanism. Degrades silently to plain header guidance ("Gender" plus
    // the example "Female" value already in row 2) if unavailable in this
    // ExcelJS build, rather than failing the whole sample download.
    const genderColumnNumber = BULK_IMPORT_FIELDS.findIndex((f) => f.kind === 'gender') + 1;
    if (genderColumnNumber > 0) {
        try {
            for (let r = 2; r <= 50; r++) {
                ws.getCell(r, genderColumnNumber).dataValidation = {
                    type: 'list',
                    allowBlank: true,
                    formulae: [`"${GENDER_OPTIONS.join(',')}"`],
                };
            }
        } catch {
            // See comment above — clear header/example guidance is enough.
        }
    }

    // Yes/No dropdown on EACH of the three opt-in columns, same mechanism and
    // same silent-degrade-to-header-guidance behaviour as Gender above.
    // Requirement: "clear Yes/No dropdown validation for each opt-in column."
    const optInColumnNumbers = BULK_IMPORT_FIELDS.reduce((acc, f, i) => (f.kind === 'optin' ? [...acc, i + 1] : acc), []);
    for (const columnNumber of optInColumnNumbers) {
        try {
            for (let r = 2; r <= 50; r++) {
                ws.getCell(r, columnNumber).dataValidation = {
                    type: 'list',
                    allowBlank: true,
                    formulae: [`"${OPT_IN_OPTIONS.join(',')}"`],
                };
            }
        } catch {
            // See the Gender block above — clear header/example guidance is enough.
        }
    }

    return wb;
}

/**
 * @param {{code:string,name:string,calling_code:string,label:string,example:string}|null} country
 */
export async function downloadSampleWorkbook(country = null) {
    const wb = buildSampleWorkbook(country);
    const buf = await wb.xlsx.writeBuffer();
    const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'contacts-import-sample.xlsx';
    a.click();
    URL.revokeObjectURL(url);
}

/**
 * The CSV-recognized subset of BULK_IMPORT_FIELDS — everything except
 * `tag`/`segment`, which are XLSX-grid-only concepts:
 * `ContactController::IMPORT_HEADER_MAP` (the CSV import's header
 * recognizer) has no entry for either, by design — CSV import has never
 * touched tags or segment assignment. Deriving this list from
 * BULK_IMPORT_FIELDS rather than hand-writing a second field list is the
 * whole point: the CSV and XLSX samples cannot drift from each other on the
 * fields they DO share, because there is exactly one definition of each.
 */
const CSV_FIELDS = BULK_IMPORT_FIELDS.filter((f) => f.kind !== 'tag' && f.kind !== 'segment');

/** One CSV field, RFC-4180-ish: quoted (with internal quotes doubled) whenever it contains a comma, quote, or newline. */
function csvField(value) {
    const s = String(value ?? '');

    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function csvRow(values) {
    return values.map(csvField).join(',');
}

/**
 * Build (but do not download) the sample CSV's text content.
 *
 * Split out from downloadSampleCsv() for the same reason
 * buildSampleWorkbook() is split from downloadSampleWorkbook() — a test can
 * assert the actual file CONTENT, not the inputs that went into it.
 *
 * @param {{code:string,name:string,calling_code:string,label:string,example:string}|null} country
 */
export function buildSampleCsv(country = null) {
    // ⚠️ NOT sampleHeaderText() for the Phone column here. That function's
    // Phone header is a country-dependent SENTENCE (e.g. "Phone (local
    // India numbers OK, or +country code)"), which is fine for the XLSX
    // grid — its headerIndex() matches headers by SUBSTRING against a
    // patterns list, so a long descriptive header still matches "phone".
    // CSV's ContactController::IMPORT_HEADER_MAP does an EXACT lowercased
    // match, with no substring fallback — a sentence-shaped header would
    // match nothing, and the resulting file would report "Phone is
    // required" on every row despite a phone value sitting right there.
    // The equivalent guidance lives in the page's own csv_header_hint text
    // instead of the header cell.
    const headerRow = csvRow(CSV_FIELDS.map((f) => (f.key === 'phone' ? 'Phone' : sampleHeaderText(f, country))));

    // Same two-example-row shape as the XLSX sample, same reason: a LOCAL
    // number in the selected country (when one is selected) plus an
    // explicit +country-code number from elsewhere, teaching both accepted
    // forms — never the same phone twice, so nothing here needs re-reading
    // as an update/merge example.
    const rows = [headerRow];
    const localExample = country?.example ?? '';

    if (localExample) {
        rows.push(csvRow(CSV_FIELDS.map((f) => (f.key === 'phone' ? localExample : SAMPLE_ROW_VALUES[f.key] ?? ''))));
    }

    rows.push(
        csvRow(
            CSV_FIELDS.map((f) => {
                if (f.key === 'phone') return SAMPLE_ROW_VALUES.phone;
                if (f.key === 'name') return 'Sam Taylor';
                if (f.key === 'email') return 'sam.taylor@example.com';

                return SAMPLE_ROW_VALUES[f.key] ?? '';
            }),
        ),
    );

    // \r\n: the conventional CSV line ending, and what Excel itself writes —
    // matters only for maximum compatibility with spreadsheet tools that are
    // stricter about it than PHP's fgetcsv() (which accepts either).
    return rows.join('\r\n') + '\r\n';
}

/**
 * @param {{code:string,name:string,calling_code:string,label:string,example:string}|null} country
 */
export function downloadSampleCsv(country = null) {
    const content = buildSampleCsv(country);
    const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'contacts-import-sample.csv';
    a.click();
    URL.revokeObjectURL(url);
}

/**
 * Map Handsontable data to the API payload (tag/segment names → ids).
 * Blank cells become `null`, not `''` — the backend treats a null Personal
 * Details value as "not provided, leave the existing value unchanged,"
 * the same safe no-overwrite rule the CSV import path already uses.
 * @param {string[][]} data
 * @param {Array<{id:number,name:string}>} tags
 * @param {Array<{id:number,name:string}>} segments
 */
export function matrixToPayload(data, tags, segments) {
    const keyIndex = Object.fromEntries(BULK_IMPORT_FIELDS.map((f, i) => [f.key, i]));
    const cell = (row, key) => String(row[keyIndex[key]] ?? '').trim();

    return data.map((row) => {
        const name = cell(row, 'name');
        const phone = cell(row, 'phone');
        const tagName = cell(row, 'tag');
        const segName = cell(row, 'segment');
        const tag = tags.find((t) => t.name.toLowerCase() === tagName.toLowerCase());
        const seg = segments.find((s) => s.name.toLowerCase() === segName.toLowerCase());

        return {
            name: name || null,
            phone_e164: phone || null,
            // Trimmed here only — lowercasing and strict validation happen
            // server-side in ContactService::normalizeEmailInput(), the same
            // split already used for phone (PhoneNumber::normalizeForImport())
            // and opt-ins (normalizeOptInInput()), so the browser never has to
            // duplicate validation rules the backend already owns.
            email: cell(row, 'email') || null,
            tag_id: tag ? tag.id : null,
            segment_id: seg ? seg.id : null,
            gender: cell(row, 'gender') || null,
            birthday: cell(row, 'birthday') || null,
            anniversary_date: cell(row, 'anniversary_date') || null,
            city: cell(row, 'city') || null,
            state: cell(row, 'state') || null,
            postal_code: cell(row, 'postal_code') || null,
            // null (not '') on a blank cell — same "not provided" convention
            // as the Personal Details fields above. What "not provided" MEANS
            // for these three is create-vs-update-dependent and is resolved
            // server-side by ContactService::resolveOptInFields(), not here.
            opt_in_whatsapp: cell(row, 'opt_in_whatsapp') || null,
            opt_in_sms: cell(row, 'opt_in_sms') || null,
            opt_in_email: cell(row, 'opt_in_email') || null,
        };
    });
}
