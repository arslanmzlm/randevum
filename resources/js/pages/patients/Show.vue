<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCash,
    IconPencil,
    IconTrash,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AnamnesisSection from '@/components/anamnesis/AnamnesisSection.vue';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import PatientAppointmentsSection from '@/components/patients/PatientAppointmentsSection.vue';
import PatientBalanceSection from '@/components/patients/PatientBalanceSection.vue';
import PatientCasesSection from '@/components/patients/PatientCasesSection.vue';
import PatientPaymentPlansSection from '@/components/patients/PatientPaymentPlansSection.vue';
import PatientProfileCard from '@/components/patients/PatientProfileCard.vue';
import PatientSmsLogList from '@/components/patients/PatientSmsLogList.vue';
import PatientTagsSection from '@/components/patients/PatientTagsSection.vue';
import PatientTreatmentsList from '@/components/patients/PatientTreatmentsList.vue';
import UngroupedTreatmentsSection from '@/components/patients/UngroupedTreatmentsSection.vue';
import RecordPaymentDialog from '@/components/payments/RecordPaymentDialog.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, edit, index } from '@/routes/patients';
import type { PatientShowProps } from '@/types/patient';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientShowProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();

const page = usePage();

const canManage = computed(() => can('patients.update'));
const canRecordPayment = computed(() => can('transactions.create'));

// Anamnesis fields are podiatry-specific; other verticals would 422 on submit.
const isPodiatry = computed(
    () => page.props.activeClinic?.vertical.slug === 'podiatry',
);

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
                    v-if="canManage"
                    :href="edit(patient.id).url"
                    :label="t('patient.edit')"
                >
                    <template #icon>
                        <IconPencil />
                    </template>
                </ButtonLink>
                <Button
                    v-if="canManage"
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

        <!-- Four tabs instead of one long stack: the page carried nine sections and the reader
             had to scroll past the clinical history to reach the balance. -->
        <Tabs value="summary">
            <TabList>
                <!-- Stable ids: the tab labels collide with sidebar group names, so tests (and
                     anything else scripting the page) need an unambiguous handle. -->
                <Tab id="patient-tab-summary" value="summary">
                    {{ t('patient.tabs.summary') }}
                </Tab>
                <Tab id="patient-tab-clinical" value="clinical">
                    {{ t('patient.tabs.clinical') }}
                </Tab>
                <Tab id="patient-tab-finance" value="finance">
                    {{ t('patient.tabs.finance') }}
                </Tab>
                <Tab id="patient-tab-messages" value="messages">
                    {{ t('patient.tabs.messages') }}
                </Tab>
            </TabList>

            <TabPanels>
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

                        <AnamnesisSection
                            v-if="isPodiatry"
                            :patient="patient"
                            :anamnesis="anamnesis"
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
                    <PatientSmsLogList :logs="smsLogs" />
                </TabPanel>
            </TabPanels>
        </Tabs>

        <RecordPaymentDialog
            v-if="canRecordPayment"
            v-model:visible="showPaymentDialog"
            :patient-id="patient.id"
            :treatments="treatments"
        />
    </div>
</template>
