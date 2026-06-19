<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    IconPlus,
    IconSearch,
    IconStethoscope,
    IconTrash,
    IconUser,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit, storeOwn } from '@/routes/doctors';
import type { Doctor, DoctorIndexProps } from '@/types/doctor';

defineOptions({ layout: AppLayout });

const props = defineProps<DoctorIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const { formatDate } = useDateTime();
const canManage = computed(() => can('doctors.update'));

const ownForm = useForm({});

// Client-side filter (the list is a custom <ul>, not a DataTable): name/specialization search +
// a status multiselect. Defaults to employed (active + passive); leavers are opt-in so an
// offboarded doctor only shows when the user adds "Ayrılanlar". Empty selection = show all.
type StatusFilter = 'active' | 'passive' | 'left';

const search = ref('');
const statusFilters = ref<StatusFilter[]>(['active', 'passive']);

const statusOptions = computed(() => [
    { label: t('doctor.active'), value: 'active' as const },
    { label: t('doctor.passive'), value: 'passive' as const },
    { label: t('doctor.filter_left'), value: 'left' as const },
]);

function doctorStatus(doctor: Doctor): StatusFilter {
    if (doctor.is_offboarded) {
        return 'left';
    }

    return doctor.is_active ? 'active' : 'passive';
}

function matchesStatus(doctor: Doctor): boolean {
    return (
        statusFilters.value.length === 0 ||
        statusFilters.value.includes(doctorStatus(doctor))
    );
}

const filteredDoctors = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.doctors.filter((doctor) => {
        const matchesSearch =
            query === '' ||
            [doctor.display_name, doctor.specialization]
                .filter(Boolean)
                .some((value) => value!.toLowerCase().includes(query));

        return matchesSearch && matchesStatus(doctor);
    });
});

function createOwnProfile(): void {
    ownForm.post(storeOwn().url, { preserveScroll: true });
}

function removeDoctor(doctor: Doctor): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('doctor.remove_confirm', { name: doctor.display_name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(doctor.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('doctor.title')" />

        <PageHeader
            :title="t('doctor.title')"
            :description="t('doctor.subtitle')"
            :breadcrumbs="[{ label: t('nav.doctors') }]"
        >
            <template #actions>
                <Button
                    v-if="canCreateOwn"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('doctor.create_own')"
                    :loading="ownForm.processing"
                    @click="createOwnProfile"
                >
                    <template #icon>
                        <IconStethoscope />
                    </template>
                </Button>

                <ButtonLink
                    v-if="canManage"
                    :href="create().url"
                    :label="t('doctor.add')"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <section
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <template v-if="doctors.length">
                <div
                    class="flex flex-col gap-2 p-2 sm:flex-row sm:items-center"
                >
                    <IconField>
                        <InputIcon>
                            <IconSearch class="size-4 text-surface-400" />
                        </InputIcon>
                        <InputText
                            v-model="search"
                            :placeholder="t('doctor.search_placeholder')"
                            class="w-full sm:w-72"
                        />
                    </IconField>
                    <MultiSelect
                        v-model="statusFilters"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('doctor.filter_status')"
                        :show-toggle-all="false"
                        class="w-full sm:w-56"
                    />
                </div>

                <ul v-if="filteredDoctors.length" class="flex flex-col">
                    <li
                        v-for="doctor in filteredDoctors"
                        :key="doctor.id"
                        class="flex items-center gap-4 rounded-lg p-3 sm:p-4"
                    >
                        <Avatar
                            v-if="doctor.avatar_url"
                            :image="doctor.avatar_url"
                            shape="circle"
                            size="large"
                        />
                        <Avatar
                            v-else
                            shape="circle"
                            size="large"
                            :aria-label="doctor.display_name"
                        >
                            <IconUser class="size-6 text-surface-400" />
                        </Avatar>

                        <div class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate font-medium text-surface-900">
                                {{ doctor.display_name }}
                            </span>
                            <span
                                v-if="doctor.specialization"
                                class="truncate text-sm text-surface-500"
                            >
                                {{ doctor.specialization }}
                            </span>
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            <Tag
                                v-if="doctor.is_offboarded"
                                severity="danger"
                                :value="t('doctor.status_left')"
                            />
                            <Tag
                                v-else
                                :severity="
                                    doctor.is_active ? 'success' : 'secondary'
                                "
                                :value="
                                    doctor.is_active
                                        ? t('doctor.active')
                                        : t('doctor.passive')
                                "
                            />
                            <span
                                v-if="doctor.is_offboarded && doctor.left_at"
                                class="text-xs text-surface-400"
                            >
                                {{ t('doctor.left_at_label') }}:
                                {{ formatDate(doctor.left_at) }}
                            </span>
                        </div>

                        <ButtonLink
                            v-if="canManage || doctor.is_self"
                            :href="edit(doctor.id).url"
                            :label="t('doctor.edit')"
                            severity="secondary"
                            outlined
                            size="small"
                        />

                        <Button
                            v-if="canManage"
                            type="button"
                            severity="danger"
                            text
                            size="small"
                            :aria-label="t('doctor.remove')"
                            @click="removeDoctor(doctor)"
                        >
                            <IconTrash />
                        </Button>
                    </li>
                </ul>

                <div
                    v-else
                    class="px-6 py-10 text-center text-sm text-surface-500"
                >
                    {{ t('doctor.empty_filtered') }}
                </div>
            </template>

            <EmptyState
                v-else
                :icon="IconStethoscope"
                :message="t('doctor.empty')"
                :bordered="false"
            />
        </section>
    </div>
</template>
