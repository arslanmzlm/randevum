<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { show as treatmentShow } from '@/routes/treatments';
import type { TransactionItem } from '@/types/balance';

withDefaults(
    defineProps<{
        transactions: TransactionItem[];
        /** Show a column indicating the linked treatment (or "standalone") — patient Show only. */
        showTreatment?: boolean;
    }>(),
    { showTreatment: false },
);

const { t } = useI18n();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();
</script>

<template>
    <DataTable :value="transactions" size="small">
        <template #empty>
            <p class="py-6 text-center text-sm text-surface-400">
                {{ t('balance.no_transactions') }}
            </p>
        </template>

        <Column :header="t('balance.columns.date')" class="w-32">
            <template #body="{ data }: { data: TransactionItem }">
                {{ formatDate(data.paid_at) }}
            </template>
        </Column>

        <Column :header="t('balance.columns.method')">
            <template #body="{ data }: { data: TransactionItem }">
                {{ t(`payment.method.${data.payment_method}`) }}
            </template>
        </Column>

        <Column v-if="showTreatment" :header="t('balance.columns.treatment')">
            <template #body="{ data }: { data: TransactionItem }">
                <Link
                    v-if="data.treatment_id !== null"
                    :href="treatmentShow(data.treatment_id).url"
                    class="text-primary-600 hover:underline"
                >
                    {{ t('balance.view_treatment') }}
                </Link>
                <span v-else class="text-surface-400">
                    {{ t('balance.standalone') }}
                </span>
            </template>
        </Column>

        <Column
            :header="t('balance.columns.amount')"
            headerClass="text-right"
            bodyClass="w-32 text-right"
        >
            <template #body="{ data }: { data: TransactionItem }">
                <span
                    :class="
                        Number(data.amount) < 0
                            ? 'text-red-600'
                            : 'text-surface-800'
                    "
                >
                    {{ formatMoney(data.amount) }}
                </span>
            </template>
        </Column>

        <Column :header="t('balance.columns.note')">
            <template #body="{ data }: { data: TransactionItem }">
                <span class="text-surface-600">
                    {{ data.note || '—' }}
                </span>
            </template>
        </Column>
    </DataTable>
</template>
