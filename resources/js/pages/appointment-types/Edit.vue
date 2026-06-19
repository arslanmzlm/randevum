<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconTags } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import ColorField from '@/components/ColorField.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingRow from '@/components/SettingRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, update } from '@/routes/appointment-types';
import type {
    AppointmentTypeEditProps,
    AppointmentTypeFormData,
} from '@/types/appointmentType';
import { COLOR_PRESETS } from '@/utils/colorPresets';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentTypeEditProps>();

const { t } = useI18n();

const form = useForm<AppointmentTypeFormData>({
    name: props.appointmentType.name,
    color: props.appointmentType.color,
    default_duration_minutes: props.appointmentType.default_duration_minutes,
    is_active: props.appointmentType.is_active,
});

function submit(): void {
    form.put(update(props.appointmentType.id).url, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_type.edit_title')" />

        <PageHeader
            :title="appointmentType.name || t('appointment_type.edit_title')"
            :description="t('appointment_type.edit_subtitle')"
            :breadcrumbs="[
                { label: t('nav.appointment_types'), href: index().url },
                {
                    label:
                        appointmentType.name ||
                        t('appointment_type.edit_title'),
                },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('appointment_type.back')"
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
            <section
                class="max-w-xl rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <header class="mb-6 flex items-center gap-2">
                    <IconTags class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('appointment_type.sections.info') }}
                    </h2>
                </header>

                <div class="flex flex-col gap-5">
                    <FormField
                        :label="t('appointment_type.fields.name')"
                        :error="form.errors.name"
                        required
                    >
                        <InputText v-model="form.name" fluid />
                    </FormField>

                    <ColorField
                        v-model="form.color"
                        :label="t('appointment_type.fields.color')"
                        :error="form.errors.color"
                        :hint="t('appointment_type.hints.color')"
                        :presets="COLOR_PRESETS"
                    />

                    <FormField
                        :label="
                            t(
                                'appointment_type.fields.default_duration_minutes',
                            )
                        "
                        :error="form.errors.default_duration_minutes"
                        :hint="
                            t('appointment_type.hints.default_duration_minutes')
                        "
                    >
                        <InputNumber
                            v-model="form.default_duration_minutes"
                            suffix=" dk"
                            :min="5"
                            :max="480"
                            :step="5"
                            show-buttons
                            :use-grouping="false"
                            fluid
                        />
                    </FormField>

                    <SettingRow
                        :label="t('appointment_type.fields.is_active')"
                        :description="t('appointment_type.hints.is_active')"
                    >
                        <ToggleSwitch v-model="form.is_active" />
                    </SettingRow>
                </div>
            </section>

            <div class="flex max-w-xl justify-end">
                <Button
                    type="submit"
                    :label="t('appointment_type.save')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
