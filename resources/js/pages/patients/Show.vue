<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCash,
    IconHeartbeat,
    IconMessage,
    IconPencil,
    IconStethoscope,
    IconTrash,
    IconUser,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AnamnesisSection from '@/components/anamnesis/AnamnesisSection.vue';
import AnamnesisSummaryCard from '@/components/anamnesis/AnamnesisSummaryCard.vue';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import PatientAppointmentsSection from '@/components/patients/PatientAppointmentsSection.vue';
import PatientBalanceSection from '@/components/patients/PatientBalanceSection.vue';
import PatientCasesSection from '@/components/patients/PatientCasesSection.vue';
import PatientPaymentPlansSection from '@/components/patients/PatientPaymentPlansSection.vue';
import PatientProfileCard from '@/components/patients/PatientProfileCard.vue';
import PatientTagsSection from '@/components/patients/PatientTagsSection.vue';
import PatientTreatmentsList from '@/components/patients/PatientTreatmentsList.vue';
import UngroupedTreatmentsSection from '@/components/patients/UngroupedTreatmentsSection.vue';
import RecordPaymentDialog from '@/components/payments/RecordPaymentDialog.vue';
import PillTabs from '@/components/PillTabs.vue';
import SmsLogList from '@/components/sms/SmsLogList.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, edit, index } from '@/routes/patients';
import type { PatientShowProps } from '@/types/patient';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientShowProps>();

const { t } = useI18n();

const confirm = useConfirm();
const { can } = useCan();

const canUpdate = computed(() => can('patients.update'));
const canDelete = computed(() => can('patients.delete'));
const canRecordPayment = computed(() => can('transactions.create'));

const tabs = computed(() => [
    {
        id: 'patient-tab-summary',
        value: 'summary',
        label: t('patient.tabs.summary'),
        icon: IconUser,
    },
    {
        id: 'patient-tab-clinical',
        value: 'clinical',
        label: t('patient.tabs.clinical'),
        icon: IconStethoscope,
    },
    {
        id: 'patient-tab-anamnesis',
        value: 'anamnesis',
        label: t('patient.tabs.anamnesis'),
        icon: IconHeartbeat,
    },
    {
        id: 'patient-tab-finance',
        value: 'finance',
        label: t('patient.tabs.finance'),
        icon: IconCash,
    },
    {
        id: 'patient-tab-messages',
        value: 'messages',
        label: t('patient.tabs.messages'),
        icon: IconMessage,
    },
]);

const validTabs = tabs.value.map((tab) => tab.value);
const requestedTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(
    requestedTab && validTabs.includes(requestedTab) ? requestedTab : 'summary',
);

// Keep the address bar on the tab being read; replaceState because switching tabs is not
// navigation history. The existing state is carried over: Inertia keeps its page snapshot there,
// and wiping it turns back/forward into a full reload.
watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
});

const showPaymentDialog = ref(false);

function removePatient(): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('patient.remove_confirm', { name: props.patient.full_name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () => router.delete(destroy(props.patient.id).url),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="patient.full_name" />

        <PageHeader
            :title="patient.full_name"
            :description="t('patient.detail_subtitle')"
            :breadcrumbs="[
                { label: t('nav.patients'), href: index().url },
                { label: patient.full_name },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('patient.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
                <Button
                    v-if="canRecordPayment"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('payment.record_button')"
                    @click="showPaymentDialog = true"
                >
                    <template #icon>
                        <IconCash />
                    </template>
                </Button>
                <ButtonLink
                    v-if="canUpdate"
                    :href="edit(patient.id).url"
                    :label="t('patient.edit')"
                >
                    <template #icon>
                        <IconPencil />
                    </template>
                </ButtonLink>
                <Button
                    v-if="canDelete"
                    type="button"
                    severity="danger"
                    outlined
                    :label="t('patient.remove')"
                    @click="removePatient"
                >
                    <template #icon>
                        <IconTrash />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <!-- Tabs instead of one long stack: the page carried nine sections and the reader had to
             scroll past the clinical history to reach the balance. -->
        <PillTabs v-model="activeTab" :tabs="tabs">
            <TabPanel value="summary">
                <div class="flex flex-col gap-6">
                    <div
                        class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3"
                    >
                        <PatientProfileCard
                            class="lg:col-span-2"
                            :patient="patient"
                        />
                        <PatientAppointmentsSection
                            :appointments="appointments"
                            only="upcoming"
                        />
                    </div>

                    <PatientTagsSection
                        :patient="patient"
                        :all-tags="allTags"
                    />

                    <!-- Read-only here; filling and editing live on the Anamnez tab. -->
                    <AnamnesisSummaryCard
                        :anamnesis="anamnesis"
                        :fields="anamnesisFieldsAll"
                        @edit="activeTab = 'anamnesis'"
                    />
                </div>
            </TabPanel>

            <TabPanel value="clinical">
                <div class="flex flex-col gap-6">
                    <PatientTreatmentsList :treatments="treatments" />

                    <PatientCasesSection :cases="cases" />

                    <UngroupedTreatmentsSection
                        :treatments="treatments"
                        :cases="cases"
                        :patient-id="patient.id"
                        :own-doctor-id="ownDoctorId"
                    />

                    <PatientAppointmentsSection
                        :appointments="appointments"
                        only="past"
                    />
                </div>
            </TabPanel>

            <TabPanel value="anamnesis">
                <AnamnesisSection
                    :patient="patient"
                    :anamnesis="anamnesis"
                    :fields="anamnesisFields"
                />
            </TabPanel>

            <TabPanel value="finance">
                <div class="flex flex-col gap-6">
                    <PatientBalanceSection
                        :balance="balance"
                        :transactions="transactions"
                    />

                    <PatientPaymentPlansSection
                        v-if="paymentPlans"
                        :patient-id="patient.id"
                        :plans="paymentPlans"
                        :treatments="treatments"
                    />
                </div>
            </TabPanel>

            <TabPanel value="messages">
                <SmsLogList
                    :logs="smsLogs"
                    :title="t('sms.log.patient.title')"
                    :empty-message="t('sms.log.patient.empty')"
                />
            </TabPanel>
        </PillTabs>

        <RecordPaymentDialog
            v-if="canRecordPayment"
            v-model:visible="showPaymentDialog"
            :patient-id="patient.id"
            :treatments="treatments"
        />
    </div>
</template>
