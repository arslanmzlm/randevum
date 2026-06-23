<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBuildingHospital,
    IconClock,
    IconMapPin,
    IconPhone,
    IconPhoto,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import ImageUploadField from '@/components/ImageUploadField.vue';
import PageHeader from '@/components/PageHeader.vue';
import WorkingHoursEditor from '@/components/WorkingHoursEditor.vue';
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
    // structuredClone throws DataCloneError on Inertia's reactive proxy; JSON clone
    // gives the form its own deep copy so edits don't mutate the prop.
    working_hours: JSON.parse(
        JSON.stringify(props.clinic.working_hours),
    ) as WorkingHours,
});

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
                    <section
                        class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                    >
                        <header class="mb-6 flex items-center gap-2">
                            <IconBuildingHospital
                                class="size-5 text-surface-500"
                            />
                            <h2 class="text-lg font-semibold text-surface-900">
                                {{ t('clinic.sections.info') }}
                            </h2>
                        </header>

                        <div class="flex flex-col gap-5">
                            <FormField
                                :label="t('clinic.fields.name')"
                                :error="form.errors.name"
                                required
                            >
                                <InputText v-model="form.name" fluid />
                            </FormField>

                            <FormField
                                :label="t('clinic.fields.slug')"
                                :error="form.errors.slug"
                                :hint="t('clinic.hints.slug')"
                                required
                            >
                                <InputText v-model="form.slug" fluid />
                            </FormField>

                            <FormField
                                :label="t('clinic.fields.description')"
                                :error="form.errors.description"
                            >
                                <Textarea
                                    v-model="form.description"
                                    rows="3"
                                    auto-resize
                                    fluid
                                />
                            </FormField>

                            <FormField :label="t('clinic.fields.vertical')">
                                <InputText
                                    :model-value="verticalLabel"
                                    fluid
                                    disabled
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
                                {{ t('clinic.sections.contact') }}
                            </h2>
                        </header>

                        <div class="flex flex-col gap-5">
                            <FormField
                                :label="t('clinic.fields.phone')"
                                :error="form.errors.phone"
                            >
                                <InputText
                                    v-model="form.phone"
                                    type="tel"
                                    fluid
                                />
                            </FormField>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormField
                                    :label="t('clinic.fields.email')"
                                    :error="form.errors.email"
                                >
                                    <InputText
                                        v-model="form.email"
                                        type="email"
                                        fluid
                                    />
                                </FormField>

                                <FormField
                                    :label="t('clinic.fields.website')"
                                    :error="form.errors.website"
                                >
                                    <InputText
                                        v-model="form.website"
                                        type="url"
                                        fluid
                                    />
                                </FormField>
                            </div>
                        </div>
                    </section>

                    <section
                        class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                    >
                        <header class="mb-6 flex items-center gap-2">
                            <IconMapPin class="size-5 text-surface-500" />
                            <h2 class="text-lg font-semibold text-surface-900">
                                {{ t('clinic.sections.address') }}
                            </h2>
                        </header>

                        <div class="flex flex-col gap-5">
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormField
                                    :label="t('clinic.fields.country')"
                                    :error="form.errors.country_id"
                                    required
                                >
                                    <Select
                                        v-model="form.country_id"
                                        :options="countries"
                                        option-label="name"
                                        option-value="id"
                                        fluid
                                    />
                                </FormField>

                                <FormField
                                    :label="t('clinic.fields.city')"
                                    :error="form.errors.city_id"
                                >
                                    <Select
                                        v-model="form.city_id"
                                        :options="cities"
                                        option-label="name"
                                        option-value="id"
                                        show-clear
                                        filter
                                        fluid
                                    />
                                </FormField>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <FormField
                                    :label="t('clinic.fields.district')"
                                    :error="form.errors.district"
                                >
                                    <InputText v-model="form.district" fluid />
                                </FormField>

                                <FormField
                                    :label="t('clinic.fields.postal_code')"
                                    :error="form.errors.postal_code"
                                >
                                    <InputText
                                        v-model="form.postal_code"
                                        fluid
                                    />
                                </FormField>
                            </div>

                            <FormField
                                :label="t('clinic.fields.address')"
                                :error="form.errors.address"
                            >
                                <Textarea
                                    v-model="form.address"
                                    rows="2"
                                    auto-resize
                                    fluid
                                />
                            </FormField>
                        </div>
                    </section>
                </div>

                <div class="flex flex-col gap-6">
                    <section
                        class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                    >
                        <header class="mb-6 flex items-center gap-2">
                            <IconClock class="size-5 text-surface-500" />
                            <h2 class="text-lg font-semibold text-surface-900">
                                {{ t('clinic.sections.hours') }}
                            </h2>
                        </header>

                        <div class="flex flex-col gap-6">
                            <FormField
                                :label="t('clinic.fields.slot_duration')"
                                :error="
                                    form.errors.default_slot_duration_minutes
                                "
                                :hint="t('clinic.hints.slot_duration')"
                                required
                            >
                                <InputNumber
                                    v-model="form.default_slot_duration_minutes"
                                    :min="5"
                                    :max="480"
                                    :step="5"
                                    show-buttons
                                    suffix=" dk"
                                    fluid
                                />
                            </FormField>

                            <WorkingHoursEditor
                                v-model="form.working_hours"
                                :errors="form.errors"
                            />
                        </div>
                    </section>

                    <section
                        class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                    >
                        <header class="mb-6 flex items-center gap-2">
                            <IconPhoto class="size-5 text-surface-500" />
                            <h2 class="text-lg font-semibold text-surface-900">
                                {{ t('clinic.sections.media') }}
                            </h2>
                        </header>

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
                    </section>
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
