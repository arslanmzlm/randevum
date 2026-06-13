<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useMoney } from '@/composables/useMoney';
import { store } from '@/routes/payments';
import type { PaymentMethod } from '@/types/enums';
import type {
    PaymentTreatmentOption,
    RecordPaymentFormData,
} from '@/types/treatment';
import { toDateString } from '@/utils/datetime';

const props = defineProps<{
    /** Patient the payment is recorded against. */
    patientId: number;
    /** Preset treatment (treatment Show); omitted ⇒ standalone (patient Show). */
    treatmentId?: number | null;
    /** Remaining balance for the preset treatment — presets the amount + shows a hint. */
    remaining?: number | null;
    /** Optional attach targets in standalone mode (Draft + Completed treatments). */
    treatments?: PaymentTreatmentOption[];
    visible: boolean;
}>();

const emit = defineEmits<{ 'update:visible': [value: boolean] }>();

const { t } = useI18n();
const { currency, formatMoney } = useMoney();

// Standalone (patient Show) offers an optional treatment Select; treatment-bound (treatment Show)
// presets a fixed treatment and shows the remaining-balance hint instead.
const standalone = computed(() => Array.isArray(props.treatments));

const methods: PaymentMethod[] = ['cash', 'card', 'transfer', 'cheque'];
const methodOptions = computed(() =>
    methods.map((method) => ({
        value: method,
        label: t(`payment.method.${method}`),
    })),
);

const treatmentOptions = computed(() =>
    (props.treatments ?? []).map((item) => ({
        value: item.id,
        label: `${item.title || t('treatment.untitled')} · ${t(
            `treatment.status.${item.status}`,
        )} · ${formatMoney(item.total_amount)}`,
    })),
);

const today = new Date();

const form = useForm<RecordPaymentFormData>({
    patient_id: props.patientId,
    treatment_id: props.treatmentId ?? null,
    amount: props.remaining ?? null,
    payment_method: null,
    note: '',
    paid_at: null,
});

// Re-seed from props each time the dialog opens (remaining/treatment may differ per launch).
watch(
    () => props.visible,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.patient_id = props.patientId;
        form.treatment_id = props.treatmentId ?? null;
        form.amount = props.remaining ?? null;
        form.payment_method = null;
        form.note = '';
        form.paid_at = null;
    },
);

function close(): void {
    emit('update:visible', false);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        paid_at: data.paid_at ? toDateString(data.paid_at) : null,
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            close();
        },
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="t('payment.record_title')"
        :style="{ width: '30rem' }"
        :dismissable-mask="!form.processing"
        @update:visible="emit('update:visible', $event)"
    >
        <form class="flex flex-col gap-5 pt-2" @submit.prevent="submit">
            <p
                v-if="!standalone && remaining != null"
                class="text-sm text-surface-500"
            >
                {{
                    t('payment.remaining_hint', {
                        amount: formatMoney(remaining),
                    })
                }}
            </p>

            <FormField
                v-if="standalone"
                :label="t('payment.treatment_optional')"
                :error="form.errors.treatment_id"
            >
                <Select
                    v-model="form.treatment_id"
                    :options="treatmentOptions"
                    option-label="label"
                    option-value="value"
                    show-clear
                    fluid
                />
            </FormField>

            <FormField
                :label="t('payment.method_label')"
                :error="form.errors.payment_method"
                required
            >
                <Select
                    v-model="form.payment_method"
                    :options="methodOptions"
                    option-label="label"
                    option-value="value"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('payment.amount')"
                :error="form.errors.amount"
                required
            >
                <InputNumber
                    v-model="form.amount"
                    mode="currency"
                    :currency="currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('payment.paid_at')"
                :error="form.errors.paid_at"
            >
                <DatePicker
                    v-model="form.paid_at"
                    date-format="dd.mm.yy"
                    :max-date="today"
                    fluid
                />
            </FormField>

            <FormField :label="t('payment.note')" :error="form.errors.note">
                <Textarea v-model="form.note" rows="3" auto-resize fluid />
            </FormField>

            <div class="flex justify-end gap-2 pt-1">
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('common.cancel')"
                    :disabled="form.processing"
                    @click="close"
                />
                <Button
                    type="submit"
                    :label="t('payment.submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
