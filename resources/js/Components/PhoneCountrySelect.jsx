import { useTranslation } from 'react-i18next';
import { Globe } from 'lucide-react';

/**
 * The "Default phone country" control, shared by BOTH contact import
 * surfaces — the CSV import on Contacts/Index and the XLSX Bulk Import grid.
 *
 * ⚠️ ONE COMPONENT ON PURPOSE. The two screens must state the same rule in the
 * same words: a number beginning with "+" keeps its own country code, anything
 * else is treated as local digits and needs this selection. Writing that
 * explanation twice is how the two screens drift into describing subtly
 * different behaviour — the same "one concept, two definitions" shape that
 * Segments.jsx's hardcoded field list already has with
 * SegmentResolver::ALLOWED_FIELDS.
 *
 * There is deliberately NO preselected country: see
 * ContactController::suggestedPhoneCountry() for the measured reason (no
 * workspace or client country field exists to preselect from).
 *
 * @param {{
 *   countries: Array<{code:string,name:string,calling_code:string,label:string,example:string}>,
 *   value: string,
 *   onChange: (code: string) => void,
 *   id?: string,
 * }} props
 */
export default function PhoneCountrySelect({ countries = [], value, onChange, id = 'default-phone-country' }) {
    const { t } = useTranslation();
    const selected = countries.find((c) => c.code === value) ?? null;

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {t('contacts_page.phone_country_label')}
            </label>

            <div className="mt-1 flex flex-wrap items-center gap-2">
                <span className="pointer-events-none text-neutral-400 dark:text-neutral-500">
                    <Globe className="h-4 w-4" />
                </span>
                <select
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-900 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100"
                >
                    <option value="">{t('contacts_page.phone_country_none')}</option>
                    {countries.map((c) => (
                        <option key={c.code} value={c.code}>
                            {c.label}
                        </option>
                    ))}
                </select>

                {/* The current selection restated in words, so the active rule
                    is readable at a glance without opening the dropdown. */}
                <span
                    data-testid="phone-country-summary"
                    className={
                        selected
                            ? 'rounded-full bg-brand-50 dark:bg-brand-900/30 px-2.5 py-1 text-xs font-medium text-brand-700 dark:text-brand-200'
                            : 'rounded-full bg-amber-50 dark:bg-amber-900/30 px-2.5 py-1 text-xs font-medium text-amber-800 dark:text-amber-200'
                    }
                >
                    {selected
                        ? t('contacts_page.phone_country_selected', { country: selected.name, code: selected.calling_code })
                        : t('contacts_page.phone_country_unselected')}
                </span>
            </div>

            <ul className="mt-2 list-disc space-y-0.5 pl-5 text-xs text-neutral-600 dark:text-neutral-400">
                <li>
                    {selected
                        ? t('contacts_page.phone_country_help_local', {
                              example: selected.example,
                              code: selected.calling_code,
                              result: `${selected.calling_code}${selected.example}`,
                          })
                        : t('contacts_page.phone_country_help_local_none')}
                </li>
                <li>{t('contacts_page.phone_country_help_international')}</li>
                <li>{t('contacts_page.phone_country_help_storage')}</li>
            </ul>
        </div>
    );
}
