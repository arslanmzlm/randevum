<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconPhone,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import { providePatientForm } from '@/components/patients/formContext';
import PatientContactFields from '@/components/patients/PatientContactFields.vue';
import PatientInfoFields from '@/components/patients/PatientInfoFields.vue';
import PatientMetaFields from '@/components/patients/PatientMetaFields.vue';
import SectionCard from '@/components/SectionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show, update } from '@/routes/patients';
import type { PatientEditProps, PatientFormData } from '@/types/patient';
import { parseDateString, toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientEditProps>();

const { t } = useI18n();

// Stored phones are E.164 (+90…); the mask works on the 10-digit national part.
function toNationalDigits(value: string | null): string {
    if (!value) {
        return '';
    }

    const digits = value.replace(/\D/g, '');

    return digits.startsWith('90') ? digits.slice(2) : digits;
}

const form = useForm<PatientFormData>({
    first_name: props.patient.first_name,
    last_name: props.patient.last_name,
    phone: toNationalDigits(props.patient.phone),
    contact_phone: toNationalDigits(props.patient.contact_phone),
    email: props.patient.email ?? '',
    birth_date: props.patient.birth_date
        ? parseDateString(props.patient.birth_date)
        : null,
    gender: props.patient.gender,
    notification_enabled: props.patient.notification_enabled,
    is_legacy: props.patient.is_legacy,
    notes: props.patient.notes ?? '',
});

form.transform((data) => ({
    ...data,
    birth_date: data.birth_date ? toDateString(data.birth_date) : null,
}));

// Shared with the field partials (PatientInfoFields / PatientContactFields / PatientMetaFields).
providePatientForm(form);

function submit(): void {
    form.put(update(props.patient.id).url, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('patient.edit_title')" />

        <PageHeader
            :title="patient.full_name || t('patient.edit_title')"
            :description="t('patient.edit_subtitle')"
            :breadcrumbs="[
                { label: t('nav.patients'), href: index().url },
                {
                    label: patient.full_name || t('patient.edit_title'),
                    href: show(patient.id).url,
                },
                { label: t('patient.edit') },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="show(patient.id).url"
                    :label="t('patient.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                <SectionCard
                    :icon="IconUser"
                    :title="t('patient.sections.info')"
                >
                    <PatientInfoFields />
                </SectionCard>

                <SectionCard
                    :icon="IconPhone"
                    :title="t('patient.sections.contact')"
                >
                    <PatientContactFields />
                </SectionCard>

                <SectionCard
                    class="lg:col-span-2"
                    :icon="IconSettings"
                    :title="t('patient.sections.preferences')"
                >
                    <PatientMetaFields />
                </SectionCard>
            </div>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('patient.save')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
