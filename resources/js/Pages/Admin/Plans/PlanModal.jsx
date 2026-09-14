import { useForm } from '@inertiajs/react';
import { Modal } from '@/Components/ui';
import PlanForm from './PlanForm';
import { useTranslation } from 'react-i18next';

const emptyPlan = (currency = 'USD') => ({
    name: '',
    slug: '',
    description: '',
    currency_code: currency,
    monthly_price_cents: null,
    quarterly_price_cents: null,
    half_yearly_price_cents: null,
    yearly_price_cents: null,
    trial_days: 0,
    stripe_monthly_id: '',
    stripe_quarterly_id: '',
    stripe_half_yearly_id: '',
    stripe_yearly_id: '',
    features: [],
    limits: {},
    enabled: true,
    featured: false,
    popular: false,
    sort_order: 0,
    whatsapp_flows_enabled: true,
});

export default function PlanModal({ show, onClose, plan = null, currencies = [], defaultCurrency = 'USD' }) {
    const { t } = useTranslation();
    const isEdit = !!plan?.id;

    const { data, setData, post, put, processing, errors, reset } = useForm(
        plan ? { ...plan, limits: plan.limits ?? {}, features: plan.features ?? [] } : emptyPlan(defaultCurrency)
    );

    const handleSubmit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(route('admin.plans.update', plan.id), {
                preserveScroll: true,
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        } else {
            post(route('admin.plans.store'), {
                preserveScroll: true,
                onSuccess: () => {
                    reset();
                    onClose();
                },
            });
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="3xl">
            <Modal.Header
                title={isEdit ? t('admin.edit_plan') : t('admin.add_plan')}
                onClose={onClose}
            />
            {/* ═══ ⚠️ A SAFETY NET, NOT THE LAYOUT — SAME CORRECTION AS
                CreateBatchModal's, WITH THIS MODAL'S OWN MEASURED CHROME ══
                Measured uncapped in headless Chrome against the compiled
                stylesheet: header 66px, Modal's own py-6 padding 48px — 114px
                total. UNLIKE CreateBatchModal, PlanForm renders its own
                Cancel/Save buttons INSIDE this Body (no separate
                <Modal.Footer> exists here), so there is no footer height to
                add — the buttons are already part of what the cap measures.

                max-h-[70vh] was the same arithmetically-wrong shape as
                CreateBatchModal's original: a fraction of the viewport
                compared against a FIXED chrome only satisfies "panel fits"
                above one specific viewport height, and fails on exactly the
                small screens the cap exists for. calc(100vh-8rem) (128px,
                ~14px of margin over the measured 114px) holds at every size
                instead. See Batches/Index.jsx's CreateBatchModal for the
                full arithmetic this pattern is based on. */}
            <Modal.Body className="max-h-[calc(100vh-8rem)] overflow-y-auto">
                <PlanForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={handleSubmit}
                    onCancel={onClose}
                    isEdit={isEdit}
                    currencies={currencies}
                />
            </Modal.Body>
        </Modal>
    );
}
