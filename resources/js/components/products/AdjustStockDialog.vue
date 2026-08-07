<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { update as updateStock } from '@/routes/products/stock';
import type { Product, ProductStockFormData } from '@/types/product';
import { MANUAL_STOCK_MOVEMENT_REASONS } from '@/utils/stockMovementReason';

const props = defineProps<{ product: Product | null }>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

// Self-contained stock-adjust form — the same PATCH .../stock operation as the edit page.
// `reason` + `note` are what the ledger records for this manual movement.
const stockForm = useForm<ProductStockFormData>({
    current_stock: 0,
    reason: 'manual_adjustment',
    note: '',
});

const reasonOptions = computed(() =>
    MANUAL_STOCK_MOVEMENT_REASONS.map((reason) => ({
        label: t(`stock_movement.reason.${reason}`),
        value: reason,
    })),
);

// Initialize from the target product each time the dialog opens.
watch(visible, (open) => {
    if (open && props.product) {
        stockForm.clearErrors();
        stockForm.current_stock = props.product.current_stock;
        stockForm.reason = 'manual_adjustment';
        stockForm.note = '';
    }
});

function submitStock(): void {
    if (!props.product) {
        return;
    }

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
            {{ product.name }}
        </p>

        <form novalidate @submit.prevent="submitStock">
            <FormField
                :label="t('product.fields.current_stock')"
                :error="stockForm.errors.current_stock"
                :hint="t('product.hints.stock')"
            >
                <InputNumber
                    v-model="stockForm.current_stock"
                    :use-grouping="false"
                    show-buttons
                    fluid
                />
            </FormField>

            <FormField
                :label="t('product.fields.stock_reason')"
                :error="stockForm.errors.reason"
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
