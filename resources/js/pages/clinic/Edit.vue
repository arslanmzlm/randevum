<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBuildingHospital,
    IconCalendarX,
    IconClock,
    IconMapPin,
    IconMessage,
    IconPhone,
    IconPhoto,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ClinicAddressFields from '@/components/clinic/ClinicAddressFields.vue';
import ClinicAppointmentSettingsFields from '@/components/clinic/ClinicAppointmentSettingsFields.vue';
import ClinicContactFields from '@/components/clinic/ClinicContactFields.vue';
import ClinicHoursFields from '@/components/clinic/ClinicHoursFields.vue';
import ClinicInfoFields from '@/components/clinic/ClinicInfoFields.vue';
import ClinicSmsSettingsForm from '@/components/clinic/ClinicSmsSettingsForm.vue';
import { provideClinicForm } from '@/components/clinic/formContext';
import ImageUploadField from '@/components/ImageUploadField.vue';
import type { MapDefaults } from '@/components/map/types';
import PageHeader from '@/components/PageHeader.vue';
import PillTabs from '@/components/PillTabs.vue';
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
    ClinicSmsPanel,
    ClinicVertical,
    WorkingHours,
} from '@/types/clinic';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    clinic: Clinic;
    vertical: ClinicVertical;
    countries: ClinicCountry[];
    cities: ClinicCity[];
    /** Null when the viewer lacks smsSettings.view — the SMS tab is then not rendered. */
    sms: ClinicSmsPanel | null;
    /** clinic.update — false for a viewer who only holds the SMS permission. */
    canEditClinic: boolean;
    /** Where the location picker opens when the clinic has no saved coordinates. */
    mapDefaults: MapDefaults;
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
    latitude: props.clinic.latitude,
    longitude: props.clinic.longitude,
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

// Deep-linkable so the sidebar can point straight at a tab (?tab=sms) and so a reader can share
// the tab they are looking at.
const validTabs = props.canEditClinic
    ? ['clinic', 'contact', 'appointments', 'sms']
    : ['sms'];
const requestedTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(
    requestedTab && validTabs.includes(requestedTab)
        ? requestedTab
        : validTabs[0],
);

// The first three tabs edit ONE clinic form, so the save row belongs to them; the SMS tab carries
// its own form and its own submit.
const tabs = computed(() => [
    ...(props.canEditClinic
        ? [
              {
                  id: 'clinic-tab-clinic',
                  value: 'clinic',
                  label: t('clinic.tabs.clinic'),
                  icon: IconBuildingHospital,
              },
              {
                  id: 'clinic-tab-contact',
                  value: 'contact',
                  label: t('clinic.tabs.contact'),
                  icon: IconPhone,
              },
              {
                  id: 'clinic-tab-appointments',
                  value: 'appointments',
                  label: t('clinic.tabs.appointments'),
                  icon: IconCalendarX,
              },
          ]
        : []),
    ...(props.sms
        ? [
              {
                  id: 'clinic-tab-sms',
                  value: 'sms',
                  label: t('clinic.tabs.sms'),
                  icon: IconMessage,
              },
          ]
        : []),
]);

// Keep the address bar on the tab being read, so a refresh or a shared link lands in the same
// place. replaceState (not push) — tab switching is not navigation history. The existing state is
// carried over: Inertia keeps its page snapshot there, and wiping it turns back/forward into a
// full reload.
watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
});

const showClinicSubmit = computed(
    () => props.canEditClinic && activeTab.value !== 'sms',
);

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

        <PillTabs v-model="activeTab" :tabs="tabs">
            <TabPanel v-if="canEditClinic" value="clinic">
                <div class="flex flex-col gap-6">
                    <SectionCard
                        :icon="IconBuildingHospital"
                        :title="t('clinic.sections.info')"
                    >
                        <ClinicInfoFields :vertical-label="verticalLabel" />
                    </SectionCard>

                    <SectionCard
                        :icon="IconClock"
                        :title="t('clinic.sections.hours')"
                    >
                        <ClinicHoursFields />
                    </SectionCard>

                    <SectionCard
                        :icon="IconPhoto"
                        :title="t('clinic.sections.media')"
                    >
                        <div class="flex flex-col gap-8">
                            <div class="flex flex-col gap-4">
                                <h3
                                    class="text-sm font-semibold text-surface-900"
                                >
                                    {{ t('clinic.media.group_logo') }}
                                </h3>
                                <div
                                    class="grid grid-cols-1 gap-6 sm:grid-cols-3"
                                >
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
                                        :url="clinic.logo_dark_url"
                                        :label="t('clinic.media.logo_dark')"
                                        :hint="t('clinic.media.logo_dark_hint')"
                                        :upload-url="
                                            updateMedia('logo_dark').url
                                        "
                                        :remove-url="
                                            removeMedia('logo_dark').url
                                        "
                                        :remove-confirm="
                                            t('clinic.media.remove_confirm')
                                        "
                                        aspect-class="aspect-square"
                                        tile-class="bg-surface-900"
                                    />
                                    <ImageUploadField
                                        :url="clinic.logo_icon_url"
                                        :label="t('clinic.media.logo_icon')"
                                        :hint="t('clinic.media.logo_icon_hint')"
                                        :upload-url="
                                            updateMedia('logo_icon').url
                                        "
                                        :remove-url="
                                            removeMedia('logo_icon').url
                                        "
                                        :remove-confirm="
                                            t('clinic.media.remove_confirm')
                                        "
                                        aspect-class="aspect-square"
                                    />
                                </div>
                            </div>

                            <div class="flex flex-col gap-4">
                                <h3
                                    class="text-sm font-semibold text-surface-900"
                                >
                                    {{ t('clinic.media.group_cover') }}
                                </h3>
                                <div
                                    class="grid grid-cols-1 gap-6 sm:grid-cols-2"
                                >
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
                                        :hint="
                                            t('clinic.media.cover_mobile_hint')
                                        "
                                        :upload-url="
                                            updateMedia('cover_mobile').url
                                        "
                                        :remove-url="
                                            removeMedia('cover_mobile').url
                                        "
                                        :remove-confirm="
                                            t('clinic.media.remove_confirm')
                                        "
                                        aspect-class="aspect-square"
                                    />
                                </div>
                            </div>
                        </div>
                    </SectionCard>
                </div>
            </TabPanel>

            <TabPanel v-if="canEditClinic" value="contact">
                <div class="flex flex-col gap-6">
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
                            :map-defaults="mapDefaults"
                        />
                    </SectionCard>
                </div>
            </TabPanel>

            <TabPanel v-if="canEditClinic" value="appointments">
                <SectionCard
                    :icon="IconCalendarX"
                    :title="t('clinic.sections.appointment_settings')"
                >
                    <ClinicAppointmentSettingsFields />
                </SectionCard>
            </TabPanel>

            <TabPanel v-if="sms" value="sms">
                <ClinicSmsSettingsForm
                    :settings="sms.settings"
                    :templates="sms.templates"
                    :defaults="sms.defaults"
                    :variables="sms.variables"
                    :sample="sms.sample"
                    :quota="sms.quota"
                />
            </TabPanel>
        </PillTabs>

        <!-- One form across the three clinic tabs: fields can be edited on any of them and saved
             once. The SMS tab brings its own form and submit. -->
        <form
            v-if="showClinicSubmit"
            novalidate
            class="flex justify-end"
            @submit.prevent="submit"
        >
            <Button
                type="submit"
                :label="t('clinic.save')"
                :loading="form.processing"
            />
        </form>
    </div>
</template>
