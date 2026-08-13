<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { update as updateStock } from '@/routes/products/stock';
import type { StockMovementReason } from '@/types/enums';
import type { Product, ProductStockFormData } from '@/types/product';
import {
    MANUAL_STOCK_MOVEMENT_REASONS,
    MOVEMENT_MODE_STOCK_REASONS,
    STOCK_MOVEMENT_REASON_SIGN,
} from '@/utils/stockMovementReason';

const props = defineProps<{ product: Product | null }>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

// Two ways to say the same thing to the ledger: a movement (reason + positive quantity,
// the reason supplying the sign) or a count (the new total, the delta closing the gap).
// One endpoint reads whichever amount field `mode` names.
const stockForm = useForm<ProductStockFormData>({
    mode: 'movement',
    quantity: 1,
    current_stock: 0,
    reason: 'stock_in',
    note: '',
});

const modeOptions = computed(() => [
    { label: t('product.stock_mode.movement'), value: 'movement' as const },
    { label: t('product.stock_mode.count'), value: 'count' as const },
]);

// Count correction has no fixed direction, so it only makes sense against a new total.
const reasonOptions = computed(() => {
    const reasons =
        stockForm.mode === 'movement'
            ? MOVEMENT_MODE_STOCK_REASONS
            : MANUAL_STOCK_MOVEMENT_REASONS;

    return reasons.map((reason) => ({
        label: t(`stock_movement.reason.${reason}`),
        value: reason,
    }));
});

const reasonSign = computed(() => STOCK_MOVEMENT_REASON_SIGN[stockForm.reason]);

// Spells out what the chosen reason will do to the stock, so the mode's effect is visible
// before submitting rather than only in the ledger afterwards.
const quantityHint = computed(() =>
    reasonSign.value === -1
        ? t('product.hints.stock_quantity_out')
        : t('product.hints.stock_quantity_in'),
);

const countHint = computed(() => {
    if (reasonSign.value === 1) {
        return t('product.hints.stock_count_increase');
    }

    if (reasonSign.value === -1) {
        return t('product.hints.stock_count_decrease');
    }

    return t('product.hints.stock_count');
});

function defaultReason(
    mode: ProductStockFormData['mode'],
): StockMovementReason {
    return mode === 'movement' ? 'stock_in' : 'count_correction';
}

// Initialize from the target product each time the dialog opens.
watch(visible, (open) => {
    if (open && props.product) {
        stockForm.clearErrors();
        stockForm.mode = 'movement';
        stockForm.quantity = 1;
        stockForm.current_stock = props.product.current_stock;
        stockForm.reason = defaultReason('movement');
        stockForm.note = '';
    }
});

// Each mode has its own default reason, and count correction is not offered in movement
// mode — switching modes must not leave a reason the new mode can't express.
watch(
    () => stockForm.mode,
    (mode) => {
        stockForm.clearErrors();

        const allowed =
            mode === 'movement'
                ? MOVEMENT_MODE_STOCK_REASONS
                : MANUAL_STOCK_MOVEMENT_REASONS;

        if (!allowed.includes(stockForm.reason)) {
            stockForm.reason = defaultReason(mode);
        }
    },
);

function submitStock(): void {
    if (!props.product) {
        return;
    }

    // Send only the amount field the chosen mode uses; the other one is noise the server
    // would have to ignore.
    stockForm.transform((data) => ({
        mode: data.mode,
        reason: data.reason,
        note: data.note,
        ...(data.mode === 'movement'
            ? { quantity: data.quantity }
            : { current_stock: data.current_stock }),
    }));

    stockForm.patch(updateStock(props.product.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :draggable="false"
        :header="t('product.update_stock')"
        class="w-full max-w-sm"
    >
        <p v-if="product" class="mb-4 text-sm text-surface-500">
            {{ product.name }} ·
            {{ t('product.stock_current', { stock: product.current_stock }) }}
        </p>

        <form
            novalidate
            class="flex flex-col gap-1"
            @submit.prevent="submitStock"
        >
            <SelectButton
                v-model="stockForm.mode"
                :options="modeOptions"
                option-label="label"
                option-value="value"
                :allow-empty="false"
                :aria-label="t('product.fields.stock_mode')"
                class="mb-2 self-start"
            />

            <p
                class="mb-4 rounded-lg bg-surface-100 px-4 py-3 text-sm text-surface-600"
            >
                {{
                    stockForm.mode === 'movement'
                        ? t('product.hints.stock_mode_movement')
                        : t('product.hints.stock_mode_count')
                }}
            </p>

            <FormField
                :label="t('product.fields.stock_reason')"
                :error="stockForm.errors.reason"
                required
            >
                <Select
                    v-model="stockForm.reason"
                    :options="reasonOptions"
                    option-label="label"
                    option-value="value"
                    fluid
                />
            </FormField>

            <FormField
                v-if="stockForm.mode === 'movement'"
                :label="t('product.fields.stock_quantity')"
                :error="stockForm.errors.quantity"
                :hint="quantityHint"
                required
            >
                <InputNumber
                    v-model="stockForm.quantity"
                    :min="1"
                    :use-grouping="false"
                    show-buttons
                    fluid
                />
            </FormField>

            <FormField
                v-else
                :label="t('product.fields.stock_new_total')"
                :error="stockForm.errors.current_stock"
                :hint="countHint"
                required
            >
                <InputNumber
                    v-model="stockForm.current_stock"
                    :use-grouping="false"
                    show-buttons
                    fluid
                />
            </FormField>

            <FormField
                :label="t('product.fields.stock_note')"
                :error="stockForm.errors.note"
            >
                <Textarea v-model="stockForm.note" rows="2" fluid />
            </FormField>

            <div class="mt-6 flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('common.cancel')"
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    :label="t('product.stock_save')"
                    :loading="stockForm.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
