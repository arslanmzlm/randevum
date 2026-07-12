<script setup lang="ts">
import { IconReceipt } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useMoney } from '@/composables/useMoney';
import type {
    TreatmentProductOption,
    TreatmentServiceOption,
} from '@/types/treatment';
import {
    lineDiscountTotal,
    lineSubtotal,
    treatmentSubtotal,
    treatmentTotal,
} from '@/utils/treatmentTotals';
import { useTreatmentForm } from './formContext';

// Catalog options are needed only to resolve line names for the itemized summary.
const props = defineProps<{
    services: TreatmentServiceOption[];
    products: TreatmentProductOption[];
}>();

// Client-side preview only — the server recomputes every total app-side on submit.
const { t } = useI18n();
const { currency, formatMoney } = useMoney();

const form = useTreatmentForm();

// E-commerce style per-line summary — only lines with a picked catalog item.
const itemized = computed(() =>
    [
        ...form.services.map((line) => ({
            name: props.services.find((s) => s.id === line.service_id)?.name,
            quantity: line.quantity,
            amount: lineSubtotal(line),
        })),
        ...form.products.map((line) => ({
            name: props.products.find((p) => p.id === line.product_id)?.name,
            quantity: line.quantity,
            amount: lineSubtotal(line),
        })),
    ].filter((item): item is typeof item & { name: string } =>
        Boolean(item.name),
    ),
);

const subtotal = computed(() =>
    treatmentSubtotal(form.services, form.products),
);

const lineDiscounts = computed(() =>
    lineDiscountTotal(form.services, form.products),
);

const total = computed(() =>
    treatmentTotal(form.services, form.products, form.discount_amount),
);
</script>

<template>
    <SectionCard :icon="IconReceipt" :title="t('treatment.sections.totals')">
        <div class="flex flex-col gap-4">
            <dl class="flex flex-col gap-2 text-sm">
                <div
                    v-for="(item, index) in itemized"
                    :key="index"
                    class="flex items-center justify-between gap-3"
                >
                    <dt class="truncate text-surface-600">
                        {{ item.quantity }} × {{ item.name }}
                    </dt>
                    <dd class="shrink-0 font-medium text-surface-800">
                        {{ formatMoney(item.amount) }}
                    </dd>
                </div>

                <div
                    v-if="lineDiscounts > 0"
                    class="flex items-center justify-between"
                >
                    <dt class="text-surface-500">
                        {{ t('treatment.totals.line_discounts') }}
                    </dt>
                    <dd class="font-medium text-red-500">
                        −{{ formatMoney(lineDiscounts) }}
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between"
                    :class="
                        itemized.length
                            ? 'mt-2 border-t border-surface-200 pt-4'
                            : undefined
                    "
                >
                    <dt class="text-surface-500">
                        {{ t('treatment.totals.subtotal') }}
                    </dt>
                    <dd class="font-medium text-surface-800">
                        {{ formatMoney(subtotal) }}
                    </dd>
                </div>
            </dl>

            <FormField
                :label="t('treatment.totals.discount')"
                :error="form.errors.discount_amount"
            >
                <InputNumber
                    v-model="form.discount_amount"
                    mode="currency"
                    :currency="currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>

            <div
                class="mt-1 flex items-center justify-between border-t border-surface-200 pt-4"
            >
                <span class="text-base font-semibold text-surface-900">
                    {{ t('treatment.totals.total') }}
                </span>
                <span class="text-base font-semibold text-surface-900">
                    {{ formatMoney(total) }}
                </span>
            </div>
        </div>
    </SectionCard>
</template>
