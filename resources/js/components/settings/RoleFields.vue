<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import type { RoleFormData } from '@/types/role';

const { t } = useI18n();

const form = useCrudForm<RoleFormData>();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('role.fields.name')"
            :error="form.errors.name"
            :hint="t('role.hints.name')"
            required
        >
            <InputText v-model="form.name" maxlength="50" fluid />
        </FormField>
    </div>
</template>
