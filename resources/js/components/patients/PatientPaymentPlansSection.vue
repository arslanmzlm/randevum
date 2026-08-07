<script setup lang="ts">
import { IconCalendarDollar, IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PaymentPlanCard from '@/components/payment-plans/PaymentPlanCard.vue';
import PaymentPlanCreateDialog from '@/components/payment-plans/PaymentPlanCreateDialog.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import type { PatientPaymentPlan } from '@/types/payment-plan';
import type { PatientTreatmentHistoryItem } from '@/types/treatment';

// Patient-detail "Taksit planları" section: the patient's plans + schedules, plus a gated
// "taksit planı oluştur" entry that opens the standalone create dialog.
defineProps<{
    patientId: number;
    plans: PatientPaymentPlan[];
    treatments: PatientTreatmentHistoryItem[];
}>();

const { t } = useI18n();
const { can } = useCan();

const showCreate = ref(false);

const canCreate = computed(() => can('paymentPlans.create'));
</script>

<template>
    <SectionCard
        :icon="IconCalendarDollar"
        :title="t('payment_plan.section_title')"
    >
        <template v-if="canCreate" #actions>
            <Button
                type="button"
                severity="secondary"
                outlined
                size="small"
                :label="t('payment_plan.create_button')"
                @click="showCreate = true"
            >
                <template #icon>
                    <IconPlus class="size-4" />
                </template>
            </Button>
        </template>

        <div v-if="plans.length" class="flex flex-col gap-4">
            <PaymentPlanCard
                v-for="plan in plans"
                :key="plan.id"
                :plan="plan"
            />
        </div>
        <p v-else class="text-sm text-surface-500">
            {{ t('payment_plan.empty') }}
        </p>

        <PaymentPlanCreateDialog
            v-if="canCreate"
            v-model:visible="showCreate"
            :patient-id="patientId"
            :treatments="treatments"
        />
    </SectionCard>
</template>
