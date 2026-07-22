<script setup lang="ts">
import { IconCash, IconPlus, IconTrash } from '@tabler/icons-vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ModeSelectRow from '@/components/ModeSelectRow.vue';
import InstallmentBuilder from '@/components/payment-plans/InstallmentBuilder.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useMoney } from '@/composables/useMoney';
import type { PaymentMethod } from '@/types/enums';
import type { PaymentEntryMode } from '@/types/treatment';
import { treatmentTotal } from '@/utils/treatmentTotals';
import { useTreatmentForm } from './formContext';

const { t } = useI18n();
const { currency, formatMoney } = useMoney();

const form = useTreatmentForm();

// Nested dotted error keys (payments.*) aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const modes: PaymentEntryMode[] = ['received', 'none', 'installment'];

const modeOptions = computed(() =>
    modes.map((mode) => ({
        value: mode,
        label: t(`treatment.payment.mode_${mode}`),
    })),
);

const methods: PaymentMethod[] = ['cash', 'card', 'transfer', 'cheque'];

const methodOptions = computed(() =>
    methods.map((method) => ({
        value: method,
        label: t(`payment.method.${method}`),
    })),
);

const total = computed(() =>
    treatmentTotal(form.services, form.products, form.discount_amount),
);

const paidSum = computed(() =>
    form.payment.rows.reduce((sum, row) => sum + (row.amount ?? 0), 0),
);

const remaining = computed(() => Math.max(0, total.value - paidSum.value));
const exceedsTotal = computed(() => paidSum.value > total.value);

// A single untouched row tracks the live total (default = full payment); stops the moment
// the user edits the amount or splits into a second row.
let lastAutoAmount: number | null = null;

watch(
    total,
    (value) => {
        const rows = form.payment.rows;

        if (
            rows.length === 1 &&
            (rows[0].amount === lastAutoAmount || rows[0].amount === null)
        ) {
            rows[0].amount = value > 0 ? value : null;
            lastAutoAmount = rows[0].amount;
        }
    },
    { immediate: true },
);

function addRow(): void {
    form.payment.rows.push({ method: null, amount: null });
}

function removeRow(index: number): void {
    form.payment.rows.splice(index, 1);
}
</script>

<template>
    <SectionCard :icon="IconCash" :title="t('treatment.sections.payment')">
        <div class="flex flex-col gap-5">
            <ModeSelectRow
                v-model="form.payment.mode"
                :options="modeOptions"
                id-prefix="payment-mode"
                gap="gap-x-6 gap-y-3"
            />

            <p
                v-if="form.payment.mode === 'received'"
                class="text-sm text-surface-500"
            >
                {{ t('treatment.payment.hint') }}
            </p>

            <template v-if="form.payment.mode === 'installment'">
                <InstallmentBuilder
                    v-model:count="form.payment.installment.count"
                    v-model:start-date="form.payment.installment.start_date"
                    v-model:down-payment="form.payment.installment.down_payment"
                    v-model:down-payment-method="
                        form.payment.installment.down_payment_method
                    "
                    v-model:installments="form.payment.installment.installments"
                    :total="total"
                    :method-options="methodOptions"
                />

                <small
                    v-if="
                        fieldError('installment_plan.installments') ||
                        fieldError('installment_plan')
                    "
                    class="text-xs text-red-500"
                >
                    {{
                        fieldError('installment_plan.installments') ??
                        fieldError('installment_plan')
                    }}
                </small>
            </template>

            <template v-else-if="form.payment.mode === 'received'">
                <div
                    v-for="(row, index) in form.payment.rows"
                    :key="index"
                    class="flex items-end gap-2"
                >
                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-xs text-surface-500">
                            {{ t('treatment.payment.method') }}
                        </label>
                        <Select
                            v-model="row.method"
                            :options="methodOptions"
                            option-label="label"
                            option-value="value"
                            :invalid="
                                Boolean(fieldError(`payments.${index}.method`))
                            "
                            fluid
                        />
                    </div>

                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-xs text-surface-500">
                            {{ t('treatment.payment.amount') }}
                        </label>
                        <InputNumber
                            v-model="row.amount"
                            mode="currency"
                            :currency="currency"
                            :min="0"
                            :max-fraction-digits="2"
                            :invalid="
                                Boolean(fieldError(`payments.${index}.amount`))
                            "
                            fluid
                        />
                    </div>

                    <Button
                        v-if="form.payment.rows.length > 1"
                        type="button"
                        severity="danger"
                        text
                        rounded
                        :aria-label="t('treatment.payment.remove_method')"
                        @click="removeRow(index)"
                    >
                        <IconTrash class="size-4" />
                    </Button>
                </div>

                <small v-if="fieldError('payments')" class="text-red-500">
                    {{ fieldError('payments') }}
                </small>

                <div class="flex items-center justify-between">
                    <Button
                        v-if="form.payment.rows.length < 4"
                        type="button"
                        severity="secondary"
                        outlined
                        size="small"
                        :label="t('treatment.payment.add_method')"
                        @click="addRow"
                    >
                        <template #icon>
                            <IconPlus class="size-4" />
                        </template>
                    </Button>
                    <span v-else />

                    <span
                        class="text-sm font-medium"
                        :class="
                            exceedsTotal ? 'text-red-500' : 'text-surface-700'
                        "
                    >
                        {{
                            exceedsTotal
                                ? t('treatment.payment.exceeds_total')
                                : `${t('treatment.payment.remaining')}: ${formatMoney(remaining)}`
                        }}
                    </span>
                </div>
            </template>
        </div>
    </SectionCard>
</template>
