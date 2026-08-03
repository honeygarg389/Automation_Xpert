import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { useState, useRef, useCallback } from 'react';
import { UserPlus, Upload, Search, Tag, Trash2, Eye, Users, Table2, Download, CheckSquare, Square, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

function ContactAvatar({ contact, size = 8 }) {
    const { t } = useTranslation();
    const name = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();
    const initials = name
        ? name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase()
        : '?';

    if (contact.avatar_url) {
        return (
            <img
                src={contact.avatar_url}
                alt={name || t('contacts_page.contact_alt')}
                className={`h-${size} w-${size} rounded-full object-cover flex-shrink-0`}
                onError={e => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'flex'; }}
            />
        );
    }
    return (
        <div className={`h-${size} w-${size} rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 flex items-center justify-center text-xs font-semibold flex-shrink-0`}>
            {initials}
        </div>
    );
}

function ContactRow({ contact, selected, onToggle, onDelete }) {
    const { t } = useTranslation();
    return (
        <tr className={`hover:bg-neutral-50 dark:hover:bg-neutral-800/50 ${selected ? 'bg-brand-50 dark:bg-brand-900/10' : ''}`}>
            <td className="px-4 py-3">
                <button type="button" onClick={() => onToggle(contact.uuid)} className="text-neutral-400 hover:text-brand-600 transition">
                    {selected
                        ? <CheckSquare className="h-4 w-4 text-brand-600" />
                        : <Square className="h-4 w-4" />
                    }
                </button>
            </td>
            <td className="px-4 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                <div className="flex items-center gap-2.5">
                    <ContactAvatar contact={contact} size={8} />
                    <span>
                        {contact.first_name || contact.last_name
                            ? `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim()
                            : <span className="text-neutral-400">—</span>
                        }
                    </span>
                </div>
            </td>
            <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">{contact.phone_e164 || '—'}</td>
            <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">{contact.email || '—'}</td>
            <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                    {contact.tags?.map(tag => (
                        <span key={tag.id} className="rounded-full px-2 py-0.5 text-xs font-medium" style={{ backgroundColor: tag.color + '33', color: tag.color }}>
                            {tag.name}
                        </span>
                    ))}
                </div>
            </td>
            <td className="px-4 py-3">
                <div className="flex items-center gap-1">
                    {contact.opt_in_whatsapp && <span className="text-xs bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 rounded px-1">{t('contacts_page.channel_wa')}</span>}
                    {contact.opt_in_sms      && <span className="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 rounded px-1">{t('contacts_page.channel_sms')}</span>}
                    {contact.opt_in_email    && <span className="text-xs bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 rounded px-1">{t('contacts_page.channel_email')}</span>}
                </div>
            </td>
            <td className="px-4 py-3">
                <div className="flex items-center gap-2">
                    <Link href={route('client.contacts.show', contact.uuid)} className="text-neutral-400 hover:text-brand-600 transition">
                        <Eye className="h-4 w-4" />
                    </Link>
                    <button type="button" onClick={() => onDelete(contact.uuid)} className="text-neutral-400 hover:text-red-500 transition">
                        <Trash2 className="h-4 w-4" />
                    </button>
                </div>
            </td>
        </tr>
    );
}

export default function ContactsIndex({ contacts, filters, segments = [] }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [search, setSearch] = useState(filters.search ?? '');
    const [showAddModal, setShowAddModal] = useState(false);
    const [selected, setSelected] = useState(new Set());
    const fileInput = useRef();

    const { data, setData, post, processing, reset } = useForm({
        first_name: '', last_name: '', phone_e164: '', email: '',
        opt_in_whatsapp: true, opt_in_sms: true, opt_in_email: true,
        segment_ids: [],
    });

    const allUuids = contacts.data.map(c => c.uuid);
    const allSelected = allUuids.length > 0 && allUuids.every(id => selected.has(id));
    const someSelected = selected.size > 0;

    const toggleAll = useCallback(() => {
        if (allSelected) {
            setSelected(prev => { const next = new Set(prev); allUuids.forEach(id => next.delete(id)); return next; });
        } else {
            setSelected(prev => new Set([...prev, ...allUuids]));
        }
    }, [allSelected, allUuids]);

    const toggleOne = useCallback((uuid) => {
        setSelected(prev => { const next = new Set(prev); next.has(uuid) ? next.delete(uuid) : next.add(uuid); return next; });
    }, []);

    const clearSelection = () => setSelected(new Set());

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('client.contacts.index'), { search }, { preserveState: true, replace: true });
    };

    const handleDelete = (uuid) => {
        if (confirm(t('contacts_page.confirm_delete_one'))) {
            router.delete(route('client.contacts.destroy', uuid), { preserveScroll: true });
        }
    };

    const handleBulkDelete = () => {
        if (!confirm(t('contacts_page.confirm_delete_selected', { count: selected.size }))) return;
        router.delete(route('client.contacts.bulk-destroy'), {
            data: { uuids: [...selected] },
            preserveScroll: true,
            onSuccess: () => clearSelection(),
        });
    };

    const handleExport = (selectedOnly = false) => {
        const params = new URLSearchParams();
        if (selectedOnly && someSelected) {
            params.set('uuids', [...selected].join(','));
        } else if (filters.search) {
            params.set('search', filters.search);
        }
        window.location.href = route('client.contacts.export') + (params.toString() ? '?' + params.toString() : '');
    };

    const handleImport = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('file', file);
        router.post(route('client.contacts.import'), formData, { preserveScroll: true });
    };

    const handlePhoneChange = (value) => {
        setData(prev => ({
            ...prev,
            phone_e164: value,
            opt_in_whatsapp: value.trim() ? prev.opt_in_whatsapp : false,
            opt_in_sms: value.trim() ? prev.opt_in_sms : false,
        }));
    };

    const handleEmailChange = (value) => {
        setData(prev => ({
            ...prev,
            email: value,
            opt_in_email: value.trim() ? prev.opt_in_email : false,
        }));
    };

    const submitAdd = (e) => {
        e.preventDefault();
        if (!data.phone_e164.trim() && !data.email.trim()) {
            alert(t('contacts_page.alert_phone_or_email'));
            return;
        }
        post(route('client.contacts.store'), { onSuccess: () => { reset(); setShowAddModal(false); } });
    };

    return (
        <ClientLayout title={t('contacts_page.title')}>
            <Head title={t('contacts_page.title')} />
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.title')}</h2>
                    <div className="flex gap-2">
                        {(
                            <Link
                                href={route('client.contacts.bulk-import')}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                            >
                                <Table2 className="h-4 w-4" /> {t('contacts_page.bulk_import')}
                            </Link>
                        )}
                        {(
                            <button type="button" onClick={() => fileInput.current?.click()} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                <Upload className="h-4 w-4" /> {t('contacts_page.import_csv')}
                            </button>
                        )}
                        <input ref={fileInput} type="file" accept=".csv,.txt" className="hidden" onChange={handleImport} />
                        <button type="button" onClick={() => handleExport(false)} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                            <Download className="h-4 w-4" /> {t('contacts_page.export_csv')}
                        </button>
                        <Link href={route('client.segments.index')} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                            <Tag className="h-4 w-4" /> {t('contacts_page.segments')}
                        </Link>
                        {(
                            <button type="button" onClick={() => setShowAddModal(true)} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                                <UserPlus className="h-4 w-4" /> {t('contacts_page.add_contact')}
                            </button>
                        )}
                    </div>
                </div>

                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}

                {/* Search */}
                <form onSubmit={handleSearch} className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder={t('contacts_page.search_placeholder')}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 pl-9 pr-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        />
                    </div>
                    <button type="submit" className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">{t('common.search')}</button>
                </form>

                {/* Bulk action bar */}
                {someSelected && (
                    <div className="flex items-center gap-3 rounded-lg bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-700 px-4 py-2.5">
                        <span className="text-sm font-medium text-brand-700 dark:text-brand-300">{t('contacts_page.n_selected', { count: selected.size })}</span>
                        <div className="flex gap-2 ml-auto">
                            <button type="button" onClick={() => handleExport(true)} className="flex items-center gap-1.5 rounded-lg border border-brand-300 dark:border-brand-600 px-3 py-1.5 text-xs font-medium text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-900/40 transition">
                                <Download className="h-3.5 w-3.5" /> {t('contacts_page.export_selected')}
                            </button>
                            <button type="button" onClick={handleBulkDelete} className="flex items-center gap-1.5 rounded-lg border border-red-300 dark:border-red-700 px-3 py-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                <Trash2 className="h-3.5 w-3.5" /> {t('contacts_page.delete_selected')}
                            </button>
                            <button type="button" onClick={clearSelection} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                    <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-800">
                            <tr>
                                <th className="px-4 py-3 w-10">
                                    <button type="button" onClick={toggleAll} className="text-neutral-400 hover:text-brand-600 transition">
                                        {allSelected
                                            ? <CheckSquare className="h-4 w-4 text-brand-600" />
                                            : <Square className="h-4 w-4" />
                                        }
                                    </button>
                                </th>
                                {[t('common.name'), t('contacts_page.col_phone'), t('common.email'), t('contacts_page.col_tags'), t('contacts_page.col_optins'), ''].map(h => (
                                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {contacts.data.map(c => (
                                <ContactRow
                                    key={c.id}
                                    contact={c}
                                    selected={selected.has(c.uuid)}
                                    onToggle={toggleOne}
                                    onDelete={handleDelete}
                                />
                            ))}
                            {contacts.data.length === 0 && (
                                <tr>
                                    <td colSpan={7}>
                                        <EmptyState
                                            icon={<Users className="h-8 w-8" />}
                                            title={t('contacts_page.empty_title')}
                                            description={t('contacts_page.empty_description')}
                                            action={{ label: t('contacts_page.add_contact'), onClick: () => setShowAddModal(true) }}
                                            secondaryAction={{ label: t('contacts_page.bulk_import'), href: route('client.contacts.bulk-import') }}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {contacts.last_page > 1 && (
                    <div className="flex gap-1">
                        {contacts.links.map((link, i) => (
                            <a key={i} href={link.url ?? '#'} className={`px-3 py-1.5 rounded text-sm border ${link.active ? 'bg-brand-600 text-white border-brand-600' : 'border-neutral-300 dark:border-neutral-600 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800'} ${!link.url ? 'opacity-40 pointer-events-none' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </div>

            {/* Add Contact Modal */}
            {showAddModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.add_contact')}</h3>
                        <form onSubmit={submitAdd} className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.first_name')}</label>
                                    <input type="text" value={data.first_name} onChange={e => setData('first_name', e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.last_name')}</label>
                                    <input type="text" value={data.last_name} onChange={e => setData('last_name', e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                                </div>
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.phone_e164')}</label>
                                <input type="text" value={data.phone_e164} onChange={e => handlePhoneChange(e.target.value)} placeholder="+8801XXXXXXXXX" className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('common.email')}</label>
                                <input type="email" value={data.email} onChange={e => handleEmailChange(e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div className="flex gap-4">
                                {[['opt_in_whatsapp', 'WhatsApp', !data.phone_e164.trim()], ['opt_in_sms', t('contacts_page.channel_sms'), !data.phone_e164.trim()], ['opt_in_email', t('common.email'), !data.email.trim()]].map(([key, label, disabled]) => (
                                    <label key={key} className={`flex items-center gap-1.5 text-sm ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}`}>
                                        <input type="checkbox" checked={data[key]} onChange={e => setData(key, e.target.checked)} disabled={disabled} className="rounded" />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            {segments.length > 0 && (
                                <div>
                                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.add_to_segments')}</label>
                                    <div className="mt-1.5 flex flex-wrap gap-2">
                                        {segments.map(seg => {
                                            const checked = data.segment_ids.includes(seg.id);
                                            return (
                                                <label key={seg.id} className={`flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs cursor-pointer transition ${checked ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300' : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}>
                                                    <input type="checkbox" className="sr-only" checked={checked} onChange={() => {
                                                        const ids = checked ? data.segment_ids.filter(id => id !== seg.id) : [...data.segment_ids, seg.id];
                                                        setData('segment_ids', ids);
                                                    }} />
                                                    {seg.name}
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                            <div className="flex gap-2 pt-2">
                                <button type="submit" disabled={processing} className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                    {processing ? t('common.saving') : t('common.save')}
                                </button>
                                <button type="button" onClick={() => setShowAddModal(false)} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
