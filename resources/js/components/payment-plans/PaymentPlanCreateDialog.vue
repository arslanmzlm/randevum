<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import InstallmentBuilder from '@/components/payment-plans/InstallmentBuilder.vue';
import { useMoney } from '@/composables/useMoney';
import { usePaymentMethodOptions } from '@/composables/usePaymentMethodOptions';
import { store } from '@/routes/payment-plans';
import type { PaymentMethod } from '@/types/enums';
import type { InstallmentRowForm } from '@/types/payment-plan';
import type { PatientTreatmentHistoryItem } from '@/types/treatment';
import { toDateString } from '@/utils/datetime';
import { defaultInstallmentStart } from '@/utils/installmentOccurrences';

// Standalone plan create from patient detail — the patient owes a general balance (or an optional
// treatment link). Owns its own useForm, renders the total + optional treatment Select, then the
// shared InstallmentBuilder, and posts to payment-plans.store.
const props = defineProps<{
    patientId: number;
    /** Optional attach targets (the patient's treatments). */
    treatments: PatientTreatmentHistoryItem[];
    visible: boolean;
}>();

const emit = defineEmits<{ 'update:visible': [value: boolean] }>();

const { t } = useI18n();
const { currency } = useMoney();

const methodOptions = usePaymentMethodOptions();

const treatmentOptions = computed(() =>
    props.treatments.map((item) => ({
        value: item.id,
        label: `${item.title || t('treatment.untitled')} · ${t(
            `treatment.status.${item.status}`,
        )}`,
    })),
);

const form = useForm<{
    patient_id: number;
    treatment_id: number | null;
    total_amount: number | null;
    down_payment: number | null;
    down_payment_method: PaymentMethod | null;
    /** Client-only generator params. */
    installment_count: number;
    start_date: Date | null;
    installments: InstallmentRowForm[];
}>({
    patient_id: props.patientId,
    treatment_id: null,
    total_amount: null,
    down_payment: null,
    down_payment_method: null,
    installment_count: 3,
    start_date: defaultInstallmentStart(),
    installments: [],
});

// Re-seed each time the dialog opens.
watch(
    () => props.visible,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.patient_id = props.patientId;
        form.treatment_id = null;
        form.total_amount = null;
        form.down_payment = null;
        form.down_payment_method = null;
        form.installment_count = 3;
        form.start_date = defaultInstallmentStart();
        form.installments = [];
    },
);

const installmentsSum = computed(() =>
    form.installments.reduce((sum, row) => sum + (row.amount ?? 0), 0),
);
const grandSum = computed(
    () => installmentsSum.value + (form.down_payment ?? 0),
);
const isBalanced = computed(
    () =>
        (form.total_amount ?? 0) > 0 &&
        Math.abs((form.total_amount ?? 0) - grandSum.value) < 0.005,
);
const canSubmit = computed(
    () => form.installments.length > 0 && isBalanced.value,
);

function close(): void {
    emit('update:visible', false);
}

function submit(): void {
    form.transform((data) => ({
        patient_id: data.patient_id,
        treatment_id: data.treatment_id,
        total_amount: data.total_amount,
        down_payment: data.down_payment,
        down_payment_method:
            (data.down_payment ?? 0) > 0 ? data.down_payment_method : null,
        installment_count: data.installments.length,
        installments: data.installments.map((row) => ({
            sequence: row.sequence,
            due_date: row.due_date ? toDateString(row.due_date) : null,
            amount: row.amount,
        })),
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
        :header="t('payment_plan.create_title')"
        :style="{ width: '40rem' }"
        :dismissable-mask="!form.processing"
        @update:visible="emit('update:visible', $event)"
    >
        <form class="flex flex-col gap-5 pt-2" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField
                    :label="t('payment_plan.total_amount')"
                    :error="form.errors.total_amount"
                    required
                >
                    <InputNumber
                        v-model="form.total_amount"
                        mode="currency"
                        :currency="currency"
                        :min="0"
                        :max-fraction-digits="2"
                        fluid
                    />
                </FormField>

                <FormField
                    v-if="treatmentOptions.length"
                    :label="t('payment_plan.treatment_optional')"
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
            </div>

            <InstallmentBuilder
                v-model:count="form.installment_count"
                v-model:start-date="form.start_date"
                v-model:down-payment="form.down_payment"
                v-model:down-payment-method="form.down_payment_method"
                v-model:installments="form.installments"
                :total="form.total_amount ?? 0"
                :method-options="methodOptions"
            />

            <small v-if="form.errors.installments" class="text-xs text-red-500">
                {{ form.errors.installments }}
            </small>
            <small
                v-if="form.errors.down_payment_method"
                class="text-xs text-red-500"
            >
                {{ form.errors.down_payment_method }}
            </small>

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
                    :label="t('payment_plan.create_submit')"
                    :loading="form.processing"
                    :disabled="!canSubmit"
                />
            </div>
        </form>
    </Dialog>
</template>
