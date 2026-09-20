import { Head, useForm } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Badge, Button, Card } from '@/Components/ui';

export default function MessagingSettings({ outlets }) {
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
                {outlets.map((outlet) => <OutletMessagingCard key={outlet.uuid} outlet={outlet} />)}
                {outlets.length === 0 && (
                    <Card>
                        <p className="py-4 text-center text-sm text-neutral-500 dark:text-neutral-400">No outlets are available in this workspace.</p>
                    </Card>
                )}
            </div>
        </ClientLayout>
    );
}

function OutletMessagingCard({ outlet }) {
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
        </Card>
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
