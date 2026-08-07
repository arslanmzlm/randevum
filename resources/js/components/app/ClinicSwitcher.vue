<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    IconBuildingHospital,
    IconCheck,
    IconChevronDown,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { switchMethod as switchClinic } from '@/routes/clinics';

// Topbar branch picker: only the clinics the viewer holds a clinic-scoped role in, and only when
// there is more than one. Switching is reversible, so no confirm dialog — but it must drop the
// current page state (the new branch has different data), hence preserveState: false.
const { t } = useI18n();
const page = usePage();

const clinics = computed(() => page.props.availableClinics ?? []);
const activeClinicId = computed(() => page.props.activeClinic?.id ?? null);
const activeName = computed(
    () =>
        page.props.activeClinic?.name ??
        clinics.value.find((clinic) => clinic.id === activeClinicId.value)
            ?.name ??
        '',
);

const menu = ref();

const items = computed(() =>
    clinics.value.map((clinic) => ({
        label: clinic.name,
        current: clinic.id === activeClinicId.value,
        command: () => switchTo(clinic.id),
    })),
);

function toggle(event: Event): void {
    menu.value?.toggle(event);
}

function switchTo(clinicId: number): void {
    if (clinicId === activeClinicId.value) {
        return;
    }

    router.post(
        switchClinic().url,
        { clinic_id: clinicId },
        { preserveState: false },
    );
}
</script>

<template>
    <div v-if="clinics.length > 1" class="flex min-w-0 items-center">
        <Button
            type="button"
            severity="secondary"
            text
            class="gap-2"
            aria-haspopup="true"
            aria-controls="clinic-switcher-menu"
            :aria-label="t('branch.switcher_label')"
            @click="toggle"
        >
            <IconBuildingHospital class="size-5 shrink-0" />
            <span class="max-w-40 truncate">{{ activeName }}</span>
            <IconChevronDown class="size-4 shrink-0 text-surface-400" />
        </Button>

        <Menu id="clinic-switcher-menu" ref="menu" :model="items" :popup="true">
            <template #item="{ item, props: itemProps }">
                <a
                    class="flex items-center gap-2"
                    :class="
                        item.current ? 'pointer-events-none font-semibold' : ''
                    "
                    :aria-current="item.current ? 'true' : undefined"
                    v-bind="itemProps.action"
                >
                    <IconCheck
                        v-if="item.current"
                        class="size-4 shrink-0 text-primary-500"
                    />
                    <span v-else class="size-4 shrink-0" aria-hidden="true" />
                    <span class="truncate">{{ item.label }}</span>
                </a>
            </template>
        </Menu>
    </div>
</template>
