<script
    setup
    lang="ts"
    generic="
        TForm extends object,
        TItem extends { id: number },
        TOption extends object
    "
>
import { router } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import type { CrudResource } from '@/types/crud';
import { shouldFilterSelect } from '@/utils/selectFilter';

// A Select that can create the thing it is missing: picking "add" opens the entity's own dialog
// in inline mode, so the surrounding form keeps its state and the new row lands in the list,
// already selected. The caller stays in charge of how an option looks (slots) and of turning a
// saved row into one (`toOption`).
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    options: TOption[];
    optionLabel: string;
    optionValue: string;
    resource: CrudResource<TForm, TItem>;
    /** Maps a saved row onto this select's option shape. */
    toOption: (item: TItem) => TOption;
    /**
     * Page props holding this select's option list. Given them, a saved row is picked up by
     * reloading those props BEFORE selecting it, so whatever the page derives from the option
     * (a service's price, a type's duration) resolves against the real row.
     */
    reloadOnly?: string[];
    /** Extra page data the dialog's field partial needs. */
    context?: object;
    /** Hides the add action when the viewer may not create the entity. */
    canCreate?: boolean;
    showClear?: boolean;
    /** Declared so FormField's cloneVNode injection reaches the inner Select. */
    inputId?: string;
    invalid?: boolean;
}>();

const model = defineModel<number | null>({ required: true });

const emit = defineEmits<{ created: [item: TItem] }>();

const { t } = useI18n();

// Fallback for callers that can't reload a prop: the row lives here until the next page load.
// Filtered against the server list so a later reload doesn't list it twice.
const created = ref<TOption[]>([]) as { value: TOption[] };

const allOptions = computed(() => [
    ...props.options,
    ...created.value.filter(
        (row) => !props.options.some((option) => value(option) === value(row)),
    ),
]);

function value(option: TOption): unknown {
    return (option as Record<string, unknown>)[props.optionValue];
}

function optionFor(selected: unknown): TOption | undefined {
    return allOptions.value.find((option) => value(option) === selected);
}

const dialogVisible = ref(false);

function onSaved(item: TItem): void {
    emit('created', item);

    if (props.reloadOnly?.length) {
        router.reload({
            only: props.reloadOnly,
            onSuccess: () => {
                model.value = item.id;
            },
        });

        return;
    }

    created.value = [...created.value, props.toOption(item)];
    model.value = item.id;
}
</script>

<template>
    <div>
        <Select
            v-model="model"
            v-bind="$attrs"
            :options="allOptions"
            :option-label="optionLabel"
            :option-value="optionValue"
            :input-id="inputId"
            :invalid="invalid"
            :show-clear="showClear"
            :filter="shouldFilterSelect(allOptions.length)"
            :filter-placeholder="t('common.search')"
            fluid
        >
            <!-- PrimeVue hands the #value slot the raw value; the matching option is resolved
                 here so a row created from this dropdown renders like any other. -->
            <template v-if="$slots.value" #value="slotProps">
                <slot
                    name="value"
                    v-bind="slotProps"
                    :option="optionFor(slotProps.value)"
                />
            </template>

            <template v-if="$slots.option" #option="slotProps">
                <slot name="option" v-bind="slotProps" />
            </template>

            <template v-if="canCreate" #footer>
                <div class="border-t border-surface-200 p-1">
                    <Button
                        type="button"
                        severity="secondary"
                        text
                        size="small"
                        class="w-full justify-start"
                        :label="t(`${resource.lang}.add`)"
                        @click="dialogVisible = true"
                    >
                        <template #icon>
                            <IconPlus class="size-4" />
                        </template>
                    </Button>
                </div>
            </template>
        </Select>

        <CrudDialog
            v-if="canCreate"
            v-model:visible="dialogVisible"
            mode="inline"
            :resource="resource"
            :item="null"
            :context="context"
            @saved="onSaved"
        />
    </div>
</template>
