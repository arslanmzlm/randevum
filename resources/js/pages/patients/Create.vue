<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconPhone,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import SectionCard from '@/components/SectionCard.vue';
import SettingRow from '@/components/SettingRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, restore, store } from '@/routes/patients';
import type { PatientFormData, RestorablePatient } from '@/types/patient';
import { toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const page = usePage();

const genderOptions = computed(() => [
    { label: t('patient.gender.male'), value: 'male' as const },
    { label: t('patient.gender.female'), value: 'female' as const },
    { label: t('patient.gender.other'), value: 'other' as const },
]);

const maxBirthDate = new Date();

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
                    <div class="flex flex-col gap-5">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormField
                                :label="t('patient.fields.first_name')"
                                :error="form.errors.first_name"
                                required
                            >
                                <InputText v-model="form.first_name" fluid />
                            </FormField>

                            <FormField
                                :label="t('patient.fields.last_name')"
                                :error="form.errors.last_name"
                                required
                            >
                                <InputText v-model="form.last_name" fluid />
                            </FormField>
                        </div>

                        <FormField
                            :label="t('patient.fields.birth_date')"
                            :error="form.errors.birth_date"
                        >
                            <DatePicker
                                v-model="form.birth_date"
                                date-format="dd.mm.yy"
                                :max-date="maxBirthDate"
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('patient.fields.gender')"
                            :error="form.errors.gender"
                        >
                            <Select
                                v-model="form.gender"
                                :options="genderOptions"
                                option-label="label"
                                option-value="value"
                                show-clear
                                fluid
                            />
                        </FormField>
                    </div>
                </SectionCard>

                <SectionCard
                    :icon="IconPhone"
                    :title="t('patient.sections.contact')"
                >
                    <div class="flex flex-col gap-5">
                        <FormField
                            :label="t('patient.fields.phone')"
                            :error="form.errors.phone"
                        >
                            <PhoneInput v-model="form.phone" />
                        </FormField>

                        <FormField
                            :label="t('patient.fields.contact_phone')"
                            :error="form.errors.contact_phone"
                            :hint="t('patient.hints.contact_phone')"
                        >
                            <PhoneInput v-model="form.contact_phone" />
                        </FormField>

                        <FormField
                            :label="t('patient.fields.email')"
                            :error="form.errors.email"
                        >
                            <InputText
                                v-model="form.email"
                                type="email"
                                fluid
                            />
                        </FormField>
                    </div>
                </SectionCard>

                <SectionCard
                    class="lg:col-span-2"
                    :icon="IconSettings"
                    :title="t('patient.sections.preferences')"
                >
                    <div class="flex flex-col gap-5">
                        <SettingRow
                            :label="t('patient.fields.notification_enabled')"
                            :description="
                                t('patient.hints.notification_enabled')
                            "
                        >
                            <ToggleSwitch v-model="form.notification_enabled" />
                        </SettingRow>

                        <SettingRow
                            :label="t('patient.fields.is_legacy')"
                            :description="t('patient.hints.is_legacy')"
                        >
                            <ToggleSwitch v-model="form.is_legacy" />
                        </SettingRow>

                        <FormField
                            :label="t('patient.fields.notes')"
                            :error="form.errors.notes"
                        >
                            <Textarea
                                v-model="form.notes"
                                rows="3"
                                auto-resize
                                fluid
                            />
                        </FormField>
                    </div>
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
