<script setup lang="ts">
import { IconCoffee, IconCopy, IconX } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { WEEK_DAYS } from '@/types/clinic';
import type { DayHours, WorkingHours } from '@/types/clinic';

const { t } = useI18n();

const model = defineModel<WorkingHours>({ required: true });

defineProps<{
    /** Inertia validation errors keyed by `working_hours.<day>`. */
    errors?: Record<string, string>;
}>();

function isOpen(day: DayHours): day is Exclude<DayHours, { closed: true }> {
    return !('closed' in day);
}

function setOpen(dayKey: keyof WorkingHours, open: boolean): void {
    model.value[dayKey] = open
        ? { open: '09:00', close: '18:00', break: null }
        : { closed: true };
}

function updateTime(
    dayKey: keyof WorkingHours,
    key: 'open' | 'close',
    value: string,
): void {
    const day = model.value[dayKey];

    if (isOpen(day)) {
        day[key] = value;
    }
}

function toggleBreak(dayKey: keyof WorkingHours, on: boolean): void {
    const day = model.value[dayKey];

    if (isOpen(day)) {
        day.break = on ? ['12:00', '13:00'] : null;
    }
}

function updateBreak(
    dayKey: keyof WorkingHours,
    index: 0 | 1,
    value: string,
): void {
    const day = model.value[dayKey];

    if (isOpen(day) && Array.isArray(day.break)) {
        day.break[index] = value;
    }
}

function copyMondayToAll(): void {
    const monday = JSON.parse(JSON.stringify(model.value.monday)) as DayHours;

    for (const day of WEEK_DAYS) {
        if (day !== 'monday') {
            model.value[day] = JSON.parse(JSON.stringify(monday)) as DayHours;
        }
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-end gap-3">
            <span class="hidden text-xs text-surface-400 sm:inline">
                {{ t('clinic.hours.copy_to_all_hint') }}
            </span>
            <Button
                type="button"
                severity="secondary"
                outlined
                size="small"
                @click="copyMondayToAll"
            >
                <IconCopy />
                {{ t('clinic.hours.copy_to_all') }}
            </Button>
        </div>

        <ul class="flex flex-col divide-y divide-surface-200">
            <li
                v-for="day in WEEK_DAYS"
                :key="day"
                class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <ToggleSwitch
                            :model-value="isOpen(model[day])"
                            :input-id="`wh-${day}`"
                            @update:model-value="
                                (v: boolean) => setOpen(day, v)
                            "
                        />
                        <label
                            :for="`wh-${day}`"
                            class="text-sm font-medium text-surface-900"
                        >
                            {{ t(`clinic.days.${day}`) }}
                        </label>
                    </div>

                    <div
                        v-if="isOpen(model[day])"
                        class="flex items-center gap-2"
                    >
                        <InputMask
                            :model-value="model[day].open"
                            mask="99:99"
                            placeholder="--:--"
                            class="w-20"
                            :aria-label="t('clinic.hours.open')"
                            @update:model-value="
                                (v: string) => updateTime(day, 'open', v ?? '')
                            "
                        />
                        <span class="text-surface-400">–</span>
                        <InputMask
                            :model-value="model[day].close"
                            mask="99:99"
                            placeholder="--:--"
                            class="w-20"
                            :aria-label="t('clinic.hours.close')"
                            @update:model-value="
                                (v: string) => updateTime(day, 'close', v ?? '')
                            "
                        />
                    </div>

                    <span v-else class="text-sm text-surface-400">
                        {{ t('clinic.hours.closed') }}
                    </span>
                </div>

                <div
                    v-if="isOpen(model[day])"
                    class="flex items-center gap-2 pl-[3.25rem]"
                >
                    <template
                        v-if="
                            isOpen(model[day]) &&
                            Array.isArray(model[day].break)
                        "
                    >
                        <IconCoffee class="size-4 shrink-0 text-surface-400" />
                        <InputMask
                            :model-value="model[day].break![0]"
                            mask="99:99"
                            placeholder="--:--"
                            class="w-20"
                            :aria-label="t('clinic.hours.break_start')"
                            @update:model-value="
                                (v: string) => updateBreak(day, 0, v ?? '')
                            "
                        />
                        <span class="text-surface-400">–</span>
                        <InputMask
                            :model-value="model[day].break![1]"
                            mask="99:99"
                            placeholder="--:--"
                            class="w-20"
                            :aria-label="t('clinic.hours.break_end')"
                            @update:model-value="
                                (v: string) => updateBreak(day, 1, v ?? '')
                            "
                        />
                        <Button
                            type="button"
                            severity="secondary"
                            text
                            rounded
                            size="small"
                            :aria-label="t('clinic.hours.remove_break')"
                            @click="toggleBreak(day, false)"
                        >
                            <IconX />
                        </Button>
                    </template>

                    <Button
                        v-else
                        type="button"
                        severity="secondary"
                        text
                        size="small"
                        @click="toggleBreak(day, true)"
                    >
                        <IconCoffee />
                        {{ t('clinic.hours.add_break') }}
                    </Button>
                </div>

                <small
                    v-if="errors?.[`working_hours.${day}`]"
                    class="text-xs text-red-500"
                >
                    {{ errors[`working_hours.${day}`] }}
                </small>
            </li>
        </ul>
    </div>
</template>
