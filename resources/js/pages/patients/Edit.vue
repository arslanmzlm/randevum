<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconPhone,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show, update } from '@/routes/patients';
import type { PatientEditProps, PatientFormData } from '@/types/patient';
import { parseDateString, toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientEditProps>();

const { t } = useI18n();

const genderOptions = computed(() => [
    { label: t('patient.gender.male'), value: 'male' as const },
    { label: t('patient.gender.female'), value: 'female' as const },
    { label: t('patient.gender.other'), value: 'other' as const },
]);

const maxBirthDate = new Date();

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
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconUser class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('patient.sections.info') }}
                        </h2>
                    </header>

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
                </section>

                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconPhone class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('patient.sections.contact') }}
                        </h2>
                    </header>

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
                </section>

                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8 lg:col-span-2"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconSettings class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('patient.sections.preferences') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
                        <div
                            class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <span
                                    class="text-sm font-medium text-surface-900"
                                >
                                    {{
                                        t('patient.fields.notification_enabled')
                                    }}
                                </span>
                                <span class="text-xs text-surface-500">
                                    {{
                                        t('patient.hints.notification_enabled')
                                    }}
                                </span>
                            </div>
                            <ToggleSwitch v-model="form.notification_enabled" />
                        </div>

                        <div
                            class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <span
                                    class="text-sm font-medium text-surface-900"
                                >
                                    {{ t('patient.fields.is_legacy') }}
                                </span>
                                <span class="text-xs text-surface-500">
                                    {{ t('patient.hints.is_legacy') }}
                                </span>
                            </div>
                            <ToggleSwitch v-model="form.is_legacy" />
                        </div>

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
                </section>
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
