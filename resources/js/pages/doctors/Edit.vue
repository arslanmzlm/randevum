<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconNotes,
    IconPhoto,
    IconUser,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import ImageUploadField from '@/components/ImageUploadField.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, update } from '@/routes/doctors';
import {
    remove as removeAvatar,
    update as updateAvatar,
} from '@/routes/doctors/avatar';
import type { DoctorEditProps } from '@/types/doctor';

defineOptions({ layout: AppLayout });

const props = defineProps<DoctorEditProps>();

const { t } = useI18n();

const form = useForm({
    first_name: props.doctor.first_name,
    last_name: props.doctor.last_name,
    title: props.doctor.title ?? '',
    specialization: props.doctor.specialization ?? '',
    bio: props.doctor.bio ?? '',
    license_number: props.doctor.license_number ?? '',
    certificate: props.doctor.certificate ?? '',
    is_active: props.doctor.is_active,
});

function submit(): void {
    form.put(update(props.doctor.id).url, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('doctor.edit_title')" />

        <PageHeader
            :title="doctor.display_name || t('doctor.edit_title')"
            :description="t('doctor.edit_subtitle')"
            :breadcrumbs="[
                { label: t('nav.doctors'), href: index().url },
                { label: doctor.display_name || t('doctor.edit_title') },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('doctor.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft class="size-4" />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <form
                novalidate
                class="flex flex-col gap-6 lg:col-span-2"
                @submit.prevent="submit"
            >
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconUser class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('doctor.sections.info') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormField
                                :label="t('doctor.fields.first_name')"
                                :error="form.errors.first_name"
                                required
                            >
                                <InputText
                                    v-model="form.first_name"
                                    autocomplete="given-name"
                                    fluid
                                />
                            </FormField>

                            <FormField
                                :label="t('doctor.fields.last_name')"
                                :error="form.errors.last_name"
                                required
                            >
                                <InputText
                                    v-model="form.last_name"
                                    autocomplete="family-name"
                                    fluid
                                />
                            </FormField>
                        </div>

                        <FormField
                            :label="t('doctor.fields.email')"
                            :hint="t('doctor.hints.email_readonly')"
                        >
                            <InputText
                                :model-value="doctor.email"
                                type="email"
                                fluid
                                disabled
                            />
                        </FormField>

                        <FormField
                            :label="t('doctor.fields.title')"
                            :hint="t('doctor.hints.title')"
                            :error="form.errors.title"
                        >
                            <InputText v-model="form.title" fluid />
                        </FormField>

                        <FormField
                            :label="t('doctor.fields.specialization')"
                            :error="form.errors.specialization"
                        >
                            <InputText v-model="form.specialization" fluid />
                        </FormField>

                        <FormField
                            :label="t('doctor.fields.license_number')"
                            :error="form.errors.license_number"
                        >
                            <InputText v-model="form.license_number" fluid />
                        </FormField>

                        <div
                            v-if="canManage"
                            class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <span
                                    class="text-sm font-medium text-surface-900"
                                >
                                    {{ t('doctor.fields.is_active') }}
                                </span>
                                <span class="text-xs text-surface-500">
                                    {{ t('doctor.hints.is_active') }}
                                </span>
                            </div>
                            <ToggleSwitch v-model="form.is_active" />
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconNotes class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('doctor.sections.about') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
                        <FormField
                            :label="t('doctor.fields.bio')"
                            :error="form.errors.bio"
                        >
                            <Textarea
                                v-model="form.bio"
                                rows="4"
                                auto-resize
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('doctor.fields.certificate')"
                            :error="form.errors.certificate"
                        >
                            <Textarea
                                v-model="form.certificate"
                                rows="3"
                                auto-resize
                                fluid
                            />
                        </FormField>
                    </div>
                </section>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        :label="t('doctor.save')"
                        :loading="form.processing"
                    />
                </div>
            </form>

            <section
                class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <header class="mb-6 flex items-center gap-2">
                    <IconPhoto class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('doctor.sections.avatar') }}
                    </h2>
                </header>

                <ImageUploadField
                    :url="doctor.avatar_url"
                    :label="t('doctor.fields.avatar')"
                    :hint="t('doctor.avatar.hint')"
                    :upload-url="updateAvatar(doctor.id).url"
                    :remove-url="removeAvatar(doctor.id).url"
                    :remove-confirm="t('doctor.avatar.remove_confirm')"
                    aspect-class="aspect-square"
                />
            </section>
        </div>
    </div>
</template>
