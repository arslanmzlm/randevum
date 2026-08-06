<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import LocationPicker from '@/components/map/LocationPicker.vue';
import type { MapDefaults, MapPoint } from '@/components/map/types';
import type { ClinicCity, ClinicCountry } from '@/types/clinic';
import { useClinicForm } from './formContext';

defineProps<{
    countries: ClinicCountry[];
    cities: ClinicCity[];
    mapDefaults: MapDefaults;
}>();

const { t } = useI18n();

const form = useClinicForm();

// The form keeps the two columns the backend stores; the picker speaks in points. Half a
// coordinate pair is never valid, so both go together or both go null.
const location = computed<MapPoint | null>({
    get: () =>
        form.latitude === null || form.longitude === null
            ? null
            : { lat: form.latitude, lng: form.longitude },
    set: (point) => {
        form.latitude = point?.lat ?? null;
        form.longitude = point?.lng ?? null;
    },
});
</script>

<template>
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
                <InputText v-model="form.postal_code" fluid />
            </FormField>
        </div>

        <FormField
            :label="t('clinic.fields.address')"
            :error="form.errors.address"
        >
            <Textarea v-model="form.address" rows="2" auto-resize fluid />
        </FormField>

        <div class="form-group">
            <span class="text-sm font-medium text-surface-900">{{
                t('clinic.map.title')
            }}</span>
            <p class="text-xs text-surface-400">{{ t('clinic.map.hint') }}</p>

            <LocationPicker
                v-model="location"
                :defaults="mapDefaults"
                :error="form.errors.latitude ?? form.errors.longitude"
            />
        </div>
    </div>
</template>
