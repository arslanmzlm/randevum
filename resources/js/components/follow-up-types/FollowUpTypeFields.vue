<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import type { FollowUpTypeFormData } from '@/types/followUpType';

const { t } = useI18n();

const form = useCrudForm<FollowUpTypeFormData>();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('follow_up_type.fields.name')"
            :error="form.errors.name"
            required
        >
            <InputText v-model="form.name" maxlength="100" fluid />
        </FormField>

        <SettingRow
            :label="t('follow_up_type.fields.is_active')"
            :description="t('follow_up_type.hints.is_active')"
        >
            <ToggleSwitch v-model="form.is_active" />
        </SettingRow>
    </div>
</template>
