<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconEye,
    IconEyeOff,
    IconNotes,
    IconStethoscope,
    IconUser,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/doctors';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
    title: '',
    specialization: '',
    license_number: '',
    bio: '',
    certificate: '',
    is_active: true,
});

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('doctor.create_title')" />

        <PageHeader
            :title="t('doctor.create_title')"
            :description="t('doctor.create_subtitle')"
            :breadcrumbs="[
                { label: t('nav.doctors'), href: index().url },
                { label: t('doctor.create_title') },
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

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconUser class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('doctor.sections.account') }}
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
                            :error="form.errors.email"
                            required
                        >
                            <InputText
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('doctor.fields.password')"
                            :error="form.errors.password"
                            :hint="t('doctor.hints.password')"
                            required
                        >
                            <Password
                                v-model="form.password"
                                autocomplete="new-password"
                                toggle-mask
                                fluid
                            >
                                <template #maskicon="{ toggleCallback }">
                                    <IconEye
                                        class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                        @click="toggleCallback"
                                    />
                                </template>
                                <template #unmaskicon="{ toggleCallback }">
                                    <IconEyeOff
                                        class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                        @click="toggleCallback"
                                    />
                                </template>
                            </Password>
                        </FormField>

                        <FormField
                            :label="t('doctor.fields.password_confirmation')"
                            :error="form.errors.password_confirmation"
                            required
                        >
                            <Password
                                v-model="form.password_confirmation"
                                autocomplete="new-password"
                                :feedback="false"
                                toggle-mask
                                fluid
                            >
                                <template #maskicon="{ toggleCallback }">
                                    <IconEye
                                        class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                        @click="toggleCallback"
                                    />
                                </template>
                                <template #unmaskicon="{ toggleCallback }">
                                    <IconEyeOff
                                        class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                        @click="toggleCallback"
                                    />
                                </template>
                            </Password>
                        </FormField>
                    </div>
                </section>

                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconStethoscope class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('doctor.sections.info') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
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
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8 lg:col-span-2"
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
            </div>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('doctor.create_submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
