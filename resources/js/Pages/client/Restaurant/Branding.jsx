import { Head, useForm } from '@inertiajs/react';
import { Image, Store } from 'lucide-react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card, Input, Textarea } from '@/Components/ui';

export default function Branding({ profile, outlets }) {
    const form = useForm({
        brand_name: profile.brand_name ?? '', primary_color: profile.primary_color ?? '', thank_you_note: profile.thank_you_note ?? '',
        social_links: profile.social_links ?? {}, logo: null, cover: null,
    });
    const submit = (event) => { event.preventDefault(); form.put(route('client.restaurant.branding.update'), { forceFormData: true, preserveScroll: true }); };

    return <ClientLayout title="Restaurant Branding"><Head title="Restaurant Branding" />
        <div className="mb-6"><div className="flex items-center gap-2"><Store className="h-5 w-5 text-brand-600" /><h1 className="text-xl font-semibold">Restaurant Branding</h1></div><p className="mt-1 text-sm text-neutral-500">Your public digital bill uses these details. Saving does not send any message.</p></div>
        <form className="space-y-5" onSubmit={submit}>
            <Card><div className="grid gap-4 md:grid-cols-2"><Input label="Brand name" value={form.data.brand_name} onChange={(e) => form.setData('brand_name', e.target.value)} error={form.errors.brand_name} /><Input label="Primary color" placeholder="#1f2937" value={form.data.primary_color} onChange={(e) => form.setData('primary_color', e.target.value)} error={form.errors.primary_color} />
                <Input label="Logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} error={form.errors.logo} /><Input label="Cover image" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => form.setData('cover', e.target.files?.[0] ?? null)} error={form.errors.cover} />
                <Textarea className="md:col-span-2" label="Thank-you note" value={form.data.thank_you_note} onChange={(e) => form.setData('thank_you_note', e.target.value)} error={form.errors.thank_you_note} />
                {['instagram', 'facebook', 'x', 'youtube'].map((key) => <Input key={key} label={`${key[0].toUpperCase()}${key.slice(1)} URL`} type="url" value={form.data.social_links[key] ?? ''} onChange={(e) => form.setData('social_links', { ...form.data.social_links, [key]: e.target.value })} error={form.errors[`social_links.${key}`]} />)}
            </div><div className="mt-5 flex justify-end"><Button type="submit" disabled={form.processing}>Save branding</Button></div></Card>
        </form>
        <div className="mt-7"><div className="mb-3 flex items-center gap-2"><Image className="h-4 w-4" /><h2 className="font-semibold">Outlet public contact details</h2></div><div className="space-y-4">{outlets.map((outlet) => <OutletContact key={outlet.uuid} outlet={outlet} />)}</div></div>
    </ClientLayout>;
}

function OutletContact({ outlet }) {
    const form = useForm({ public_phone: outlet.public_phone ?? '', public_website: outlet.public_website ?? '' });
    const submit = (event) => { event.preventDefault(); form.put(route('client.restaurant.branding.outlets.update', outlet.uuid), { preserveScroll: true }); };
    return <Card><form onSubmit={submit}><h3 className="font-medium">{outlet.name}</h3><div className="mt-4 grid gap-4 md:grid-cols-2"><Input label="Public phone" value={form.data.public_phone} onChange={(e) => form.setData('public_phone', e.target.value)} error={form.errors.public_phone} /><Input label="Public website" type="url" value={form.data.public_website} onChange={(e) => form.setData('public_website', e.target.value)} error={form.errors.public_website} /></div><div className="mt-4 flex justify-end"><Button type="submit" variant="secondary" disabled={form.processing}>Save outlet details</Button></div></form></Card>;
}
