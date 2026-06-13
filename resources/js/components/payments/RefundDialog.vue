<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useMoney } from '@/composables/useMoney';
import { refund } from '@/routes/transactions';
import type { TransactionItem } from '@/types/balance';

const props = defineProps<{
    /** The original payment being reversed; drives the amount cap. Null ⇒ nothing selected yet. */
    transaction: TransactionItem | null;
    visible: boolean;
}>();

const emit = defineEmits<{ 'update:visible': [value: boolean] }>();

const { t } = useI18n();
const { currency, formatMoney } = useMoney();

// The refundable remaining caps the amount input; the explicit amount + reason form IS the
// deliberate confirmation step (no extra ConfirmDialog needed).
const maxAmount = computed(() =>
    props.transaction ? Number(props.transaction.refundable_amount) : 0,
);

const form = useForm<{ amount: number | null; reason: string }>({
    amount: null,
    reason: '',
});

// Re-seed from the selected transaction each time the dialog opens (default = full remaining).
watch(
    () => props.visible,
    (open) => {
        if (!open || !props.transaction) {
            return;
        }

        form.clearErrors();
        form.amount = Number(props.transaction.refundable_amount);
        form.reason = '';
    },
);

function close(): void {
    emit('update:visible', false);
}

function submit(): void {
    if (!props.transaction) {
        return;
    }

    form.post(refund(props.transaction.id).url, {
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
        :header="t('refund.title')"
        :style="{ width: '28rem' }"
        :dismissable-mask="!form.processing"
        @update:visible="emit('update:visible', $event)"
    >
        <form class="flex flex-col gap-5 pt-2" @submit.prevent="submit">
            <FormField
                :label="t('refund.amount_label')"
                :error="form.errors.amount"
                :hint="t('refund.max_hint', { amount: formatMoney(maxAmount) })"
                required
            >
                <InputNumber
                    v-model="form.amount"
                    mode="currency"
                    :currency="currency"
                    :min="0"
                    :max="maxAmount"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('refund.reason_label')"
                :error="form.errors.reason"
                required
            >
                <Textarea v-model="form.reason" rows="3" auto-resize fluid />
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
                    :label="t('refund.submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
