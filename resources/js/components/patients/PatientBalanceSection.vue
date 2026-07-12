<script setup lang="ts">
import { IconCash } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import TransactionList from '@/components/payments/TransactionList.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useMoney } from '@/composables/useMoney';
import type { PatientBalance, TransactionItem } from '@/types/balance';

const props = defineProps<{
    balance?: PatientBalance;
    transactions?: TransactionItem[];
}>();

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useMoney();

// A negative remaining means the patient overpaid — the clinic owes them (a credit).
const remainingIsCredit = computed(
    () => props.balance != null && Number(props.balance.remaining) < 0,
);
</script>

<template>
    <SectionCard
        v-if="can('transactions.viewAny') && balance"
        :icon="IconCash"
        :title="t('balance.section_title')"
    >
        <div class="flex flex-col gap-5">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div
                    class="flex flex-col gap-1 rounded-lg border border-surface-200 p-4"
                >
                    <dt class="text-xs text-surface-500">
                        {{ t('balance.total') }}
                    </dt>
                    <dd class="text-lg font-semibold text-surface-900">
                        {{ formatMoney(balance.total) }}
                    </dd>
                </div>
                <div
                    class="flex flex-col gap-1 rounded-lg border border-surface-200 p-4"
                >
                    <dt class="text-xs text-surface-500">
                        {{ t('balance.paid') }}
                    </dt>
                    <dd class="text-lg font-semibold text-green-600">
                        {{ formatMoney(balance.paid) }}
                    </dd>
                </div>
                <div
                    class="flex flex-col gap-1 rounded-lg border border-surface-200 p-4"
                >
                    <dt class="text-xs text-surface-500">
                        {{
                            remainingIsCredit
                                ? t('balance.credit')
                                : t('balance.remaining')
                        }}
                    </dt>
                    <dd
                        class="text-lg font-semibold"
                        :class="
                            remainingIsCredit
                                ? 'text-green-600'
                                : 'text-surface-900'
                        "
                    >
                        {{
                            formatMoney(
                                remainingIsCredit
                                    ? Math.abs(Number(balance.remaining))
                                    : balance.remaining,
                            )
                        }}
                    </dd>
                </div>
            </dl>

            <div class="flex flex-col gap-3">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('balance.transactions_title') }}
                </h3>
                <TransactionList
                    :transactions="transactions ?? []"
                    show-treatment
                />
            </div>
        </div>
    </SectionCard>
</template>
