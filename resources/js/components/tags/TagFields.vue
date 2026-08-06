<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ColorField from '@/components/ColorField.vue';
import { useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import type { TagFormData } from '@/types/tag';
import { COLOR_PRESETS } from '@/utils/colorPresets';

const { t } = useI18n();

const form = useCrudForm<TagFormData>();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('tag.fields.name')"
            :error="form.errors.name"
            required
        >
            <InputText v-model="form.name" maxlength="50" fluid />
        </FormField>

        <ColorField
            v-model="form.color"
            :label="t('tag.fields.color')"
            :error="form.errors.color"
            :presets="COLOR_PRESETS"
        />
    </div>
</template>
