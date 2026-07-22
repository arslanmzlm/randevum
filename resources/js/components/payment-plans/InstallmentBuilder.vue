<script setup lang="ts">
import { IconCalendarDollar } from '@tabler/icons-vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useMoney } from '@/composables/useMoney';
import type { PaymentMethod } from '@/types/enums';
import type { InstallmentRowForm } from '@/types/payment-plan';
import { buildInstallments } from '@/utils/installmentOccurrences';

// The dynamic taksit builder: count + start date + down payment → equal-split monthly rows, each
// row's date + amount editable, with a live sum-check against `total`. Reused by the treatment
// PaymentSection (installment mode, total = computed treatment total) and the standalone create
// dialog (total = user-entered). Fields are v-model-bound so either parent form owns the state.
const props = defineProps<{
    /** Plan total the installments (+ down payment) must sum to. */
    total: number;
    methodOptions: Array<{ value: PaymentMethod; label: string }>;
}>();

const count = defineModel<number>('count', { required: true });
const startDate = defineModel<Date | null>('startDate', { required: true });
const downPayment = defineModel<number | null>('downPayment', {
    required: true,
});
const downPaymentMethod = defineModel<PaymentMethod | null>(
    'downPaymentMethod',
    { required: true },
);
const installments = defineModel<InstallmentRowForm[]>('installments', {
    required: true,
});

const { t } = useI18n();
const { currency, formatMoney } = useMoney();

const minDate = new Date();

// Any generator-param change rebuilds the whole schedule from the pattern — manual row edits are
// intentionally discarded (mirrors the follow-up package generator: keep simple).
watch(
    [count, startDate, downPayment, () => props.total],
    () => {
        installments.value = buildInstallments({
            total: props.total,
            downPayment: downPayment.value ?? 0,
            count: count.value,
            startDate: startDate.value,
        });
    },
    { immediate: true },
);

// Clear the method once the down payment drops to zero, so a stale method isn't submitted.
watch(downPayment, (value) => {
    if (!value || value <= 0) {
        downPaymentMethod.value = null;
    }
});

const hasDownPayment = computed(() => (downPayment.value ?? 0) > 0);

const installmentsSum = computed(() =>
    installments.value.reduce((sum, row) => sum + (row.amount ?? 0), 0),
);

const grandSum = computed(
    () => installmentsSum.value + (downPayment.value ?? 0),
);

// Positive → under total (still to allocate); negative → exceeds; ~0 → balanced.
const difference = computed(
    () => Math.round((props.total - grandSum.value) * 100) / 100,
);
const isBalanced = computed(() => Math.abs(difference.value) < 0.005);
const exceedsTotal = computed(() => difference.value < -0.005);
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <FormField :label="t('payment_plan.builder.count')">
                <InputNumber
                    v-model="count"
                    :min="1"
                    :max="60"
                    show-buttons
                    :use-grouping="false"
                    fluid
                />
            </FormField>

            <FormField :label="t('payment_plan.builder.start_date')">
                <DatePicker
                    v-model="startDate"
                    :min-date="minDate"
                    date-format="dd.mm.yy"
                    show-icon
                    fluid
                />
            </FormField>

            <FormField :label="t('payment_plan.builder.down_payment')">
                <InputNumber
                    v-model="downPayment"
                    mode="currency"
                    :currency="currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>
        </div>

        <FormField
            v-if="hasDownPayment"
            :label="t('payment_plan.builder.down_payment_method')"
        >
            <Select
                v-model="downPaymentMethod"
                :options="methodOptions"
                option-label="label"
                option-value="value"
                fluid
            />
        </FormField>

        <p class="text-sm text-surface-500">
            {{ t('payment_plan.builder.hint') }}
        </p>

        <div v-if="installments.length" class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('payment_plan.builder.schedule_title') }}
                </h3>
                <span class="text-xs text-surface-400">
                    {{ t('payment_plan.builder.total_label') }}:
                    {{ formatMoney(total) }}
                </span>
            </div>

            <div
                v-for="(row, index) in installments"
                :key="index"
                class="flex items-end gap-2"
            >
                <span
                    class="flex h-10 w-8 shrink-0 items-center justify-center text-sm font-medium text-surface-500"
                >
                    {{ row.sequence }}.
                </span>

                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-xs text-surface-500">
                        {{ t('payment_plan.builder.due_date') }}
                    </label>
                    <DatePicker
                        v-model="row.due_date"
                        date-format="dd.mm.yy"
                        show-icon
                        fluid
                    />
                </div>

                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-xs text-surface-500">
                        {{ t('payment_plan.builder.amount') }}
                    </label>
                    <InputNumber
                        v-model="row.amount"
                        mode="currency"
                        :currency="currency"
                        :min="0"
                        :max-fraction-digits="2"
                        fluid
                    />
                </div>
            </div>

            <div
                class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium"
                :class="
                    isBalanced
                        ? 'bg-emerald-50 text-emerald-700'
                        : 'bg-amber-50 text-amber-700'
                "
            >
                <span>
                    <IconCalendarDollar class="mr-1 inline size-4" />
                    {{
                        t('payment_plan.builder.sum_label', {
                            amount: formatMoney(grandSum),
                        })
                    }}
                </span>
                <span>
                    <template v-if="isBalanced">
                        {{ t('payment_plan.builder.balanced') }}
                    </template>
                    <template v-else-if="exceedsTotal">
                        {{ t('payment_plan.builder.exceeds') }}
                    </template>
                    <template v-else>
                        {{
                            t('payment_plan.builder.remaining', {
                                amount: formatMoney(difference),
                            })
                        }}
                    </template>
                </span>
            </div>
        </div>
    </div>
</template>
