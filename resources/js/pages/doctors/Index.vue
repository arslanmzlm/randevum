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
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit, storeOwn } from '@/routes/doctors';
import type { Doctor, DoctorIndexProps } from '@/types/doctor';

defineOptions({ layout: AppLayout });

const props = defineProps<DoctorIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const canManage = computed(() => can('doctors.update'));

const ownForm = useForm({});

// Client-side filter (the list is a custom <ul>, not a DataTable): name/specialization search +
// an active/passive status filter (null = all).
const search = ref('');
const statusFilter = ref<boolean | null>(null);

const statusOptions = computed(() => [
    { label: t('doctor.active'), value: true },
    { label: t('doctor.passive'), value: false },
]);

const filteredDoctors = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.doctors.filter((doctor) => {
        const matchesSearch =
            query === '' ||
            [doctor.display_name, doctor.specialization]
                .filter(Boolean)
                .some((value) => value!.toLowerCase().includes(query));
        const matchesStatus =
            statusFilter.value === null ||
            doctor.is_active === statusFilter.value;

        return matchesSearch && matchesStatus;
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
                    <Select
                        v-model="statusFilter"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('doctor.filter_status')"
                        show-clear
                        class="w-full sm:w-44"
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

                        <Tag
                            :severity="
                                doctor.is_active ? 'success' : 'secondary'
                            "
                            :value="
                                doctor.is_active
                                    ? t('doctor.active')
                                    : t('doctor.passive')
                            "
                        />

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

            <div
                v-else
                class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <IconStethoscope class="size-10 text-surface-300" />
                <p class="text-sm text-surface-500">{{ t('doctor.empty') }}</p>
            </div>
        </section>
    </div>
</template>
