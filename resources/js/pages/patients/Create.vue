<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconPhone,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import { providePatientForm } from '@/components/patients/formContext';
import PatientContactFields from '@/components/patients/PatientContactFields.vue';
import PatientInfoFields from '@/components/patients/PatientInfoFields.vue';
import PatientMetaFields from '@/components/patients/PatientMetaFields.vue';
import SectionCard from '@/components/SectionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, restore, store } from '@/routes/patients';
import type { PatientFormData, RestorablePatient } from '@/types/patient';
import { toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const page = usePage();

const form = useForm<PatientFormData>({
    first_name: '',
    last_name: '',
    phone: '',
    contact_phone: '',
    email: '',
    birth_date: null,
    gender: null,
    notification_enabled: true,
    is_legacy: false,
    notes: '',
});

form.transform((data) => ({
    ...data,
    birth_date: data.birth_date ? toDateString(data.birth_date) : null,
}));

// Shared with the field partials (PatientInfoFields / PatientContactFields / PatientMetaFields).
providePatientForm(form);

function submit(): void {
    form.post(store().url);
}

// Restore-on-reuse: a store with a phone owned by a soft-deleted patient redirects
// back with `flash.restorable_patient` instead of a validation error.
const restoreVisible = ref(false);
const restorable = ref<RestorablePatient | null>(null);

watch(
    () =>
        (page.props.flash as { restorable_patient?: RestorablePatient | null })
            ?.restorable_patient,
    (value) => {
        if (value) {
            restorable.value = value;
            restoreVisible.value = true;
        }
    },
    { immediate: true },
);

function acceptRestore(): void {
    if (!restorable.value) {
        return;
    }

    router.post(restore(restorable.value.id).url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('patient.create_title')" />

        <PageHeader
            :title="t('patient.create_title')"
            :description="t('patient.create_subtitle')"
            :breadcrumbs="[
                { label: t('nav.patients'), href: index().url },
                { label: t('patient.create_title') },
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
                    :label="t('patient.create_submit')"
                    :loading="form.processing"
                />
            </div>
        </form>

        <Dialog
            v-model:visible="restoreVisible"
            modal
            :draggable="false"
            :header="t('patient.restore.title')"
            class="w-full max-w-md"
        >
            <p class="text-sm text-surface-600">
                {{
                    t('patient.restore.message', {
                        name: restorable?.full_name ?? '',
                    })
                }}
            </p>
            <template #footer>
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('patient.restore.reject')"
                    @click="restoreVisible = false"
                />
                <Button
                    type="button"
                    :label="t('patient.restore.accept')"
                    @click="acceptRestore"
                />
            </template>
        </Dialog>
    </div>
</template>
