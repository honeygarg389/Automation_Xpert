import { Head, useForm } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Badge, Button, Card } from '@/Components/ui';

export default function MessagingSettings({ outlets, digitalBillDeliveryOptions = { senders: [] } }) {
    return (
        <ClientLayout title="Restaurant Messaging">
            <Head title="Restaurant Messaging" />

            <div className="mb-6">
                <div className="flex items-center gap-2">
                    <MessageSquare className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                    <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Restaurant Messaging</h1>
                </div>
                <p className="mt-1 max-w-3xl text-sm text-neutral-500 dark:text-neutral-400">
                    These controls affect only future Digital Bill and Feedback Request behavior for each outlet.
                    Changing settings does not send any message.
                </p>
            </div>

            <div className="space-y-4">
                {outlets.map((outlet) => <OutletMessagingCard key={outlet.uuid} outlet={outlet} digitalBillDeliveryOptions={digitalBillDeliveryOptions} />)}
                {outlets.length === 0 && (
                    <Card>
                        <p className="py-4 text-center text-sm text-neutral-500 dark:text-neutral-400">No outlets are available in this workspace.</p>
                    </Card>
                )}
            </div>
        </ClientLayout>
    );
}

function OutletMessagingCard({ outlet, digitalBillDeliveryOptions }) {
    const { data, setData, put, processing, errors } = useForm({
        digital_bill_enabled: Boolean(outlet.digital_bill_enabled),
        feedback_request_enabled: Boolean(outlet.feedback_request_enabled),
    });

    const submit = (event) => {
        event.preventDefault();
        put(route('client.restaurant.messaging.outlets.update', outlet.uuid), { preserveScroll: true });
    };

    return (
        <Card>
            <form onSubmit={submit}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-semibold text-neutral-900 dark:text-neutral-100">{outlet.name}</h2>
                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Both settings are off by default and do not send anything when changed.</p>
                    </div>
                    <Badge variant="default" size="sm">Outlet settings</Badge>
                </div>

                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    <SettingsToggle
                        id={`digital-bill-${outlet.uuid}`}
                        label="Digital Bill"
                        description="Controls future Digital Bill delivery only."
                        checked={data.digital_bill_enabled}
                        onChange={(checked) => setData('digital_bill_enabled', checked)}
                    />
                    <SettingsToggle
                        id={`feedback-request-${outlet.uuid}`}
                        label="Feedback Request"
                        description="Controls future Feedback Request delivery only."
                        checked={data.feedback_request_enabled}
                        onChange={(checked) => setData('feedback_request_enabled', checked)}
                    />
                </div>

                {(errors.digital_bill_enabled || errors.feedback_request_enabled) && (
                    <p className="mt-3 text-sm text-coral-600 dark:text-coral-400">
                        {errors.digital_bill_enabled || errors.feedback_request_enabled}
                    </p>
                )}
                <div className="mt-4 flex justify-end">
                    <Button type="submit" variant="primary" disabled={processing}>Save settings</Button>
                </div>
            </form>
            <DigitalBillDeliveryConfigCard outlet={outlet} options={digitalBillDeliveryOptions} />
        </Card>
    );
}

function DigitalBillDeliveryConfigCard({ outlet, options }) {
    const initialSenderId = outlet.digital_bill_delivery_config?.whatsapp_phone_number_id ?? '';
    const { data, setData, put, processing, errors } = useForm({
        whatsapp_phone_number_id: initialSenderId,
        whatsapp_template_id: outlet.digital_bill_delivery_config?.whatsapp_template_id ?? '',
    });
    const sender = options.senders?.find((item) => Number(item.id) === Number(data.whatsapp_phone_number_id));
    const submit = (event) => {
        event.preventDefault();
        put(route('client.restaurant.messaging.outlets.digital-bill-delivery-config.update', outlet.uuid), { preserveScroll: true });
    };
    return (
        <form onSubmit={submit} className="mt-5 border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Digital Bill delivery configuration</h3>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Configuration is revalidated before any future delivery.</p>
            <div className="mt-3 grid gap-3 md:grid-cols-2">
                <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">WhatsApp sender<select value={data.whatsapp_phone_number_id} onChange={(event) => { setData('whatsapp_phone_number_id', event.target.value); setData('whatsapp_template_id', ''); }} className="mt-1 block w-full rounded-soft border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Select a sender</option>{(options.senders ?? []).map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
                <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">Approved Utility template<select value={data.whatsapp_template_id} disabled={!sender} onChange={(event) => setData('whatsapp_template_id', event.target.value)} className="mt-1 block w-full rounded-soft border-neutral-300 text-sm disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900"><option value="">{sender ? 'Select a Utility template' : 'Select a sender first'}</option>{(sender?.templates ?? []).map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
            </div>
            {(errors.whatsapp_phone_number_id || errors.whatsapp_template_id) && <p className="mt-2 text-sm text-coral-600 dark:text-coral-400">{errors.whatsapp_phone_number_id || errors.whatsapp_template_id}</p>}
            <div className="mt-3 flex justify-end"><Button type="submit" variant="secondary" disabled={processing}>Save delivery configuration</Button></div>
        </form>
    );
}

function SettingsToggle({ id, label, description, checked, onChange }) {
    return (
        <label htmlFor={id} className="flex cursor-pointer items-start gap-3 rounded-soft border border-neutral-200 p-3 dark:border-neutral-700">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-1 h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
            />
            <span>
                <span className="block text-sm font-medium text-neutral-900 dark:text-neutral-100">{label}</span>
                <span className="mt-0.5 block text-xs text-neutral-500 dark:text-neutral-400">{description}</span>
            </span>
        </label>
    );
}
