<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconShieldLock } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PermissionMatrix from '@/components/settings/PermissionMatrix.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { RoleMatrixProps } from '@/types/role';

defineOptions({ layout: AppLayout });

const props = defineProps<RoleMatrixProps>();

const { t } = useI18n();

const hasMatrix = computed(
    () => props.roles.length > 0 && props.groups.length > 0,
);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('role.title')" />

        <PageHeader
            :title="t('role.title')"
            :description="t('role.subtitle')"
            :breadcrumbs="[{ label: t('nav.roles') }]"
        />

        <Message severity="secondary" :closable="false">
            {{ t('role.read_only_note') }}
        </Message>

        <PermissionMatrix v-if="hasMatrix" :roles="roles" :groups="groups" />

        <EmptyState v-else :icon="IconShieldLock" :message="t('role.empty')" />
    </div>
</template>
