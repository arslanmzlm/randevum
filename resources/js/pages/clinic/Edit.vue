<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBuildingHospital,
    IconCalendarX,
    IconClock,
    IconMapPin,
    IconPhone,
    IconPhoto,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ClinicAddressFields from '@/components/clinic/ClinicAddressFields.vue';
import ClinicAppointmentSettingsFields from '@/components/clinic/ClinicAppointmentSettingsFields.vue';
import ClinicContactFields from '@/components/clinic/ClinicContactFields.vue';
import ClinicHoursFields from '@/components/clinic/ClinicHoursFields.vue';
import ClinicInfoFields from '@/components/clinic/ClinicInfoFields.vue';
import { provideClinicForm } from '@/components/clinic/formContext';
import ImageUploadField from '@/components/ImageUploadField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { update } from '@/routes/clinic';
import {
    remove as removeMedia,
    update as updateMedia,
} from '@/routes/clinic/media';
import type {
    Clinic,
    ClinicCity,
    ClinicCountry,
    ClinicVertical,
    WorkingHours,
} from '@/types/clinic';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    clinic: Clinic;
    vertical: ClinicVertical;
    countries: ClinicCountry[];
    cities: ClinicCity[];
}>();

const { t, te } = useI18n();

const form = useForm({
    name: props.clinic.name,
    slug: props.clinic.slug,
    description: props.clinic.description ?? '',
    phone: props.clinic.phone ?? '',
    email: props.clinic.email ?? '',
    website: props.clinic.website ?? '',
    country_id: props.clinic.country_id,
    city_id: props.clinic.city_id,
    district: props.clinic.district ?? '',
    address: props.clinic.address ?? '',
    postal_code: props.clinic.postal_code ?? '',
    default_slot_duration_minutes: props.clinic.default_slot_duration_minutes,
    auto_no_show_enabled: props.clinic.auto_no_show_enabled,
    auto_no_show_grace_hours: props.clinic.auto_no_show_grace_hours,
    // structuredClone throws DataCloneError on Inertia's reactive proxy; JSON clone
    // gives the form its own deep copy so edits don't mutate the prop.
    working_hours: JSON.parse(
        JSON.stringify(props.clinic.working_hours),
    ) as WorkingHours,
});

// Shared with the field partials (ClinicInfoFields / ClinicContactFields / ClinicAddressFields / ClinicHoursFields).
provideClinicForm(form);

const verticalKey = `clinic.verticals.${props.vertical.name}`;
const verticalLabel = te(verticalKey) ? t(verticalKey) : props.vertical.name;

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('clinic.title')" />

        <PageHeader
            :title="t('clinic.title')"
            :description="t('clinic.subtitle')"
            :breadcrumbs="[{ label: t('nav.clinic') }]"
        />

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                <div class="flex flex-col gap-6">
                    <SectionCard
                        :icon="IconBuildingHospital"
                        :title="t('clinic.sections.info')"
                    >
                        <ClinicInfoFields :vertical-label="verticalLabel" />
                    </SectionCard>

                    <SectionCard
                        :icon="IconPhone"
                        :title="t('clinic.sections.contact')"
                    >
                        <ClinicContactFields />
                    </SectionCard>

                    <SectionCard
                        :icon="IconMapPin"
                        :title="t('clinic.sections.address')"
                    >
                        <ClinicAddressFields
                            :countries="countries"
                            :cities="cities"
                        />
                    </SectionCard>
                </div>

                <div class="flex flex-col gap-6">
                    <SectionCard
                        :icon="IconClock"
                        :title="t('clinic.sections.hours')"
                    >
                        <ClinicHoursFields />
                    </SectionCard>

                    <SectionCard
                        :icon="IconCalendarX"
                        :title="t('clinic.sections.appointment_settings')"
                    >
                        <ClinicAppointmentSettingsFields />
                    </SectionCard>

                    <SectionCard
                        :icon="IconPhoto"
                        :title="t('clinic.sections.media')"
                    >
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <ImageUploadField
                                :url="clinic.logo_url"
                                :label="t('clinic.media.logo')"
                                :hint="t('clinic.media.logo_hint')"
                                :upload-url="updateMedia('logo').url"
                                :remove-url="removeMedia('logo').url"
                                :remove-confirm="
                                    t('clinic.media.remove_confirm')
                                "
                                aspect-class="aspect-square"
                            />
                            <ImageUploadField
                                :url="clinic.cover_url"
                                :label="t('clinic.media.cover')"
                                :hint="t('clinic.media.cover_hint')"
                                :upload-url="updateMedia('cover').url"
                                :remove-url="removeMedia('cover').url"
                                :remove-confirm="
                                    t('clinic.media.remove_confirm')
                                "
                                aspect-class="aspect-video"
                            />
                            <ImageUploadField
                                :url="clinic.cover_mobile_url"
                                :label="t('clinic.media.cover_mobile')"
                                :hint="t('clinic.media.cover_mobile_hint')"
                                :upload-url="updateMedia('cover_mobile').url"
                                :remove-url="removeMedia('cover_mobile').url"
                                :remove-confirm="
                                    t('clinic.media.remove_confirm')
                                "
                                aspect-class="aspect-square"
                            />
                        </div>
                    </SectionCard>
                </div>
            </div>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('clinic.save')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
