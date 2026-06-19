<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconLogout,
    IconNotes,
    IconPhoto,
    IconSettings,
    IconUser,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import OffboardDialog from '@/components/doctors/OffboardDialog.vue';
import FormField from '@/components/FormField.vue';
import ImageUploadField from '@/components/ImageUploadField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingRow from '@/components/SettingRow.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
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
const { can } = useCan();
const { formatDate } = useDateTime();
const canManage = computed(() => can('doctors.update'));

// Offboarding lives here (not on the list) — a deliberate, rare action behind opening the doctor.
// Hidden for self and when the user lacks the ability; an already-departed doctor shows a read-only note.
const canOffboard = computed(
    () => can('doctors.offboard') && !props.doctor.is_self,
);
const offboardVisible = ref(false);

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
                        <IconArrowLeft />
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

                        <SettingRow
                            v-if="canManage"
                            :label="t('doctor.fields.is_active')"
                            :description="t('doctor.hints.is_active')"
                        >
                            <ToggleSwitch v-model="form.is_active" />
                        </SettingRow>
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

            <div class="flex flex-col gap-6">
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

                <section
                    v-if="canOffboard"
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconSettings class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('doctor.sections.actions') }}
                        </h2>
                    </header>

                    <div
                        v-if="doctor.is_offboarded"
                        class="flex items-center gap-2 text-sm text-surface-600"
                    >
                        <IconLogout class="size-4 text-surface-400" />
                        <span>
                            {{
                                t('doctor.offboarded_on', {
                                    date: doctor.left_at
                                        ? formatDate(doctor.left_at)
                                        : '',
                                })
                            }}
                        </span>
                    </div>

                    <Button
                        v-else
                        type="button"
                        severity="danger"
                        outlined
                        fluid
                        :label="t('doctor.offboard')"
                        @click="offboardVisible = true"
                    >
                        <template #icon>
                            <IconLogout />
                        </template>
                    </Button>
                </section>
            </div>
        </div>

        <OffboardDialog v-model:visible="offboardVisible" :doctor="doctor" />
    </div>
</template>
