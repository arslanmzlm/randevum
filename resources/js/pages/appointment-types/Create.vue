<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconTags } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import ColorField from '@/components/ColorField.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import SettingRow from '@/components/SettingRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/appointment-types';
import type { AppointmentTypeFormData } from '@/types/appointmentType';
import { COLOR_PRESETS } from '@/utils/colorPresets';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const form = useForm<AppointmentTypeFormData>({
    name: '',
    color: COLOR_PRESETS[0],
    default_duration_minutes: 30,
    is_active: true,
});

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_type.create_title')" />

        <PageHeader
            :title="t('appointment_type.create_title')"
            :description="t('appointment_type.create_subtitle')"
            :breadcrumbs="[
                { label: t('nav.appointment_types'), href: index().url },
                { label: t('appointment_type.create_title') },
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
            <SectionCard
                class="max-w-xl"
                :icon="IconTags"
                :title="t('appointment_type.sections.info')"
            >
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
            </SectionCard>

            <div class="flex max-w-xl justify-end">
                <Button
                    type="submit"
                    :label="t('appointment_type.create_submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
