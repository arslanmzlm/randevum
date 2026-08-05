<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconTrash, IconX } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InstallmentStatusTag from '@/components/payment-plans/InstallmentStatusTag.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { cancel, destroy } from '@/routes/payment-plans';
import type { PatientPaymentPlan } from '@/types/payment-plan';
import { paymentPlanStatusSeverity } from '@/utils/installmentStatus';

// Read-only display of one payment plan + its installment schedule (patient detail). Collection of
// individual installments happens on the collections screen; here the only mutating action is
// cancelling the whole plan (gated), which gives a mis-created plan an exit.
const props = defineProps<{ plan: PatientPaymentPlan }>();

const { t } = useI18n();
const { can } = useCan();
const confirm = useConfirm();
const { formatMoney } = useMoney();
const { formatDate, formatDateOnly } = useDateTime();

const statusSeverity = computed(() =>
    paymentPlanStatusSeverity(props.plan.status),
);

const canCancel = computed(
    () => props.plan.status === 'active' && can('paymentPlans.cancel'),
);

// A plan with money against it is cancelled, never deleted: the payment records would lose the
// schedule they belong to. The server enforces the same rule.
const hasCollected = computed(() =>
    props.plan.installments.some(
        (installment) => installment.status === 'paid',
    ),
);

const canDelete = computed(
    () => can('paymentPlans.delete') && !hasCollected.value,
);

function deletePlan(): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('payment_plan.delete_confirm_message'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(props.plan.id).url, { preserveScroll: true }),
    });
}

function cancelPlan(): void {
    confirm.require({
        header: t('payment_plan.cancel_confirm_title'),
        message: t('payment_plan.cancel_confirm_message'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: t('payment_plan.cancel_action'),
            severity: 'danger',
        },
        accept: () =>
            router.post(
                cancel(props.plan.id).url,
                {},
                { preserveScroll: true },
            ),
    });
}
</script>

<template>
    <div class="flex flex-col gap-4 rounded-xl border border-surface-200 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex items-center gap-2">
                    <Tag
                        :value="t(`payment_plan.plan_status.${plan.status}`)"
                        :severity="statusSeverity"
                        class="p-tag-sm"
                    />
                    <span class="text-sm text-surface-500">
                        {{
                            plan.treatment_id
                                ? t('payment_plan.linked_treatment')
                                : t('payment_plan.general_balance')
                        }}
                    </span>
                </div>
                <span class="text-xs text-surface-400">
                    {{ formatDate(plan.created_at) }}
                </span>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex flex-col items-end">
                    <span class="text-lg font-semibold text-surface-900">
                        {{ formatMoney(plan.total_amount) }}
                    </span>
                    <span class="text-xs text-surface-500">
                        {{
                            t('payment_plan.installment_count_label', {
                                count: plan.installment_count,
                            })
                        }}
                        <template v-if="plan.down_payment">
                            ·
                            {{
                                t('payment_plan.down_payment_label', {
                                    amount: formatMoney(plan.down_payment),
                                })
                            }}
                        </template>
                    </span>
                </div>
                <Button
                    v-if="canCancel"
                    v-tooltip.top="t('payment_plan.cancel_action')"
                    type="button"
                    severity="danger"
                    text
                    rounded
                    :aria-label="t('payment_plan.cancel_action')"
                    @click="cancelPlan"
                >
                    <IconX class="size-4" />
                </Button>
                <Button
                    v-if="canDelete"
                    v-tooltip.top="t('payment_plan.delete_action')"
                    type="button"
                    severity="danger"
                    text
                    rounded
                    :aria-label="t('payment_plan.delete_action')"
                    @click="deletePlan"
                >
                    <IconTrash class="size-4" />
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-surface-500">
                        <th class="py-1 pr-3 font-medium">#</th>
                        <th class="py-1 pr-3 font-medium">
                            {{ t('payment_plan.builder.due_date') }}
                        </th>
                        <th class="py-1 pr-3 font-medium">
                            {{ t('payment_plan.builder.amount') }}
                        </th>
                        <th class="py-1 pr-3 font-medium">
                            {{ t('payment_plan.columns.status') }}
                        </th>
                        <th class="py-1 font-medium">
                            {{ t('payment_plan.columns.paid_at') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="installment in plan.installments"
                        :key="installment.id"
                        class="border-t border-surface-100"
                    >
                        <td class="py-2 pr-3 text-surface-500">
                            {{ installment.sequence }}
                        </td>
                        <td class="py-2 pr-3 text-surface-700">
                            {{ formatDateOnly(installment.due_date) }}
                        </td>
                        <td class="py-2 pr-3 font-medium text-surface-800">
                            {{ formatMoney(installment.amount) }}
                        </td>
                        <td class="py-2 pr-3">
                            <InstallmentStatusTag
                                :status="installment.status"
                                :overdue="installment.is_overdue"
                                small
                            />
                        </td>
                        <td class="py-2 text-surface-500">
                            {{
                                installment.paid_at
                                    ? formatDate(installment.paid_at)
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
