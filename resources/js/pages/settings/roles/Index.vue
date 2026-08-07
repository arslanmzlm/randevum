<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { IconPlus, IconRotate, IconShieldLock } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PermissionMatrix from '@/components/settings/PermissionMatrix.vue';
import { useCan } from '@/composables/useCan';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { roleResource } from '@/crud/role';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, revert } from '@/routes/settings/roles';
import { update as updatePermissions } from '@/routes/settings/roles/permissions';
import type {
    PermissionDraft,
    RoleColumn,
    RoleMatrixProps,
    RolePermissionPayload,
} from '@/types/role';

defineOptions({ layout: AppLayout });

const props = defineProps<RoleMatrixProps>();

const { t } = useI18n();
const { can } = useCan();
const confirm = useConfirm();

const editable = computed(() => can('roles.manage'));

const page = usePage();

// Refusals (self-lockout, own-role delete, role still assigned) arrive as validation
// errors on keys no field renders, so they'd only surface as the generic error toast.
const pageError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.role ?? errors?.permissions;
});

const hasMatrix = computed(
    () => props.roles.length > 0 && props.groups.length > 0,
);

function buildDraft(): PermissionDraft {
    const draft: PermissionDraft = {};

    for (const role of props.roles) {
        draft[role.id] = [];
    }

    for (const group of props.groups) {
        for (const permission of group.permissions) {
            for (const roleId of permission.role_ids) {
                draft[roleId]?.push(permission.name);
            }
        }
    }

    return draft;
}

// Every mutating visit runs with `preserveState: false`, so the component remounts and both the
// draft and this snapshot are rebuilt from the fresh props — role ids change after a copy-on-write.
const initial = buildDraft();
const draft = ref<PermissionDraft>(buildDraft());

function sameSet(a: string[], b: string[]): boolean {
    if (a.length !== b.length) {
        return false;
    }

    const held = new Set(a);

    return b.every((name) => held.has(name));
}

const changedRoleIds = computed(() =>
    props.roles
        .filter(
            (role) =>
                !sameSet(draft.value[role.id] ?? [], initial[role.id] ?? []),
        )
        .map((role) => role.id),
);

const isDirty = computed(() => changedRoleIds.value.length > 0);

const customizedRoles = computed(() =>
    props.roles.filter((role) => role.is_customized),
);

function onToggle(roleId: number, permission: string, granted: boolean): void {
    const current = draft.value[roleId] ?? [];

    draft.value[roleId] = granted
        ? current.includes(permission)
            ? current
            : [...current, permission]
        : current.filter((name) => name !== permission);
}

function onToggleGroup(
    roleId: number,
    groupKey: string,
    granted: boolean,
): void {
    const group = props.groups.find((item) => item.key === groupKey);

    if (!group) {
        return;
    }

    for (const permission of group.permissions) {
        if (permission.locked_role_ids.includes(roleId)) {
            continue;
        }

        onToggle(roleId, permission.name, granted);
    }
}

function onDiscard(): void {
    draft.value = Object.fromEntries(
        Object.entries(initial).map(([id, names]) => [Number(id), [...names]]),
    );
}

const form = useForm<{ roles: RolePermissionPayload[] }>({ roles: [] });

function save(): void {
    form.roles = changedRoleIds.value.map((id) => ({
        id,
        permissions: draft.value[id] ?? [],
    }));

    form.put(updatePermissions().url, {
        preserveScroll: true,
        // Remount on success (copy-on-write hands back new role ids), but keep the page — and
        // with it the user's unsaved ticks — whenever the server rejected the save.
        preserveState: (page) =>
            Object.keys(page.props.errors ?? {}).length > 0,
    });
}

useUnsavedChanges(
    () => isDirty.value,
    t('role.unsaved_warning'),
    () => [updatePermissions().url],
);

const { visible, item, openCreate, confirmDelete } = useCrudDialog<RoleColumn>({
    lang: roleResource.lang,
    destroy,
    canCreate: () => editable.value,
});

function confirmRevert(): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('role.revert_confirm', {
            names: customizedRoles.value.map((role) => role.label).join(', '),
        }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('role.revert_accept'), severity: 'danger' },
        accept: () => router.delete(revert().url, { preserveState: false }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('role.title')" />

        <PageHeader
            :title="t('role.title')"
            :description="t('role.subtitle')"
            :breadcrumbs="[{ label: t('nav.roles') }]"
        >
            <template #actions>
                <Tag
                    v-if="editable && isDirty"
                    severity="warn"
                    :value="t('role.dirty_badge')"
                />
                <Button
                    v-if="editable && isDirty"
                    type="button"
                    severity="secondary"
                    text
                    :label="t('role.discard')"
                    @click="onDiscard"
                />
                <Button
                    v-if="editable && customizedRoles.length > 0"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('role.revert_all')"
                    @click="confirmRevert"
                >
                    <template #icon>
                        <IconRotate />
                    </template>
                </Button>
                <Button
                    v-if="editable"
                    type="button"
                    severity="secondary"
                    :label="t('role.add_custom')"
                    @click="openCreate"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
                <Button
                    v-if="editable"
                    type="button"
                    :label="t('role.save')"
                    :disabled="!isDirty"
                    :loading="form.processing"
                    @click="save"
                />
            </template>
        </PageHeader>

        <Message v-if="pageError" severity="error" :closable="false">
            {{ pageError }}
        </Message>

        <Message severity="secondary" :closable="false">
            {{ editable ? t('role.edit_note') : t('role.read_only_note') }}
        </Message>

        <CrudDialog
            v-if="editable"
            v-model:visible="visible"
            :resource="roleResource"
            :item="item"
        />

        <PermissionMatrix
            v-if="hasMatrix"
            :roles="roles"
            :groups="groups"
            :editable="editable"
            :draft="draft"
            @toggle="onToggle"
            @toggle-group="onToggleGroup"
            @delete="(role) => confirmDelete(role, role.label)"
        />

        <EmptyState v-else :icon="IconShieldLock" :message="t('role.empty')" />
    </div>
</template>
