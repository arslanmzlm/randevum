<script setup lang="ts">
import { IconUserSearch } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { search as searchRoute } from '@/routes/patients';
import type { PatientSearchResult } from '@/types/patient';

// Reusable patient typeahead (the 1.6 create-appointment seam). Calls the slim JSON
// `patients.search` endpoint per keystroke; v-model is the picked PatientSearchResult.
const props = withDefaults(
    defineProps<{
        autofocus?: boolean;
        placeholder?: string;
        disabled?: boolean;
        invalid?: boolean;
    }>(),
    {
        autofocus: false,
        placeholder: undefined,
        disabled: false,
        invalid: false,
    },
);

const emit = defineEmits<{
    select: [patient: PatientSearchResult];
}>();

const model = defineModel<PatientSearchResult | null>({ default: null });

const { t } = useI18n();

const MIN_CHARS = 2;
const DEBOUNCE_MS = 300;

const suggestions = ref<PatientSearchResult[]>([]);
const loading = ref(false);
const typedQuery = ref('');
let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let activeRequest: AbortController | undefined;

const placeholderText = computed(
    () => props.placeholder ?? t('patient.quick_find_placeholder'),
);

// True once the user has typed something but not yet enough to trigger a search,
// so the panel can show a "type at least N chars" hint instead of "no results".
const belowMinChars = computed(
    () => typedQuery.value.length > 0 && typedQuery.value.length < MIN_CHARS,
);

function onComplete(event: { query: string }): void {
    const query = event.query.trim();
    typedQuery.value = query;

    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    // The endpoint returns nothing under MIN_CHARS — skip the round-trip, but keep
    // suggestions an empty array so the panel still opens and shows the hint.
    if (query.length < MIN_CHARS) {
        suggestions.value = [];
        loading.value = false;

        return;
    }

    loading.value = true;
    debounceTimer = setTimeout(() => void runSearch(query), DEBOUNCE_MS);
}

async function runSearch(query: string): Promise<void> {
    // Cancel the previous in-flight request so a slow earlier response can't
    // clobber the suggestions for a newer keystroke.
    activeRequest?.abort();
    activeRequest = new AbortController();

    try {
        const response = await fetch(searchRoute({ query: { q: query } }).url, {
            headers: { Accept: 'application/json' },
            signal: activeRequest.signal,
        });

        if (!response.ok) {
            suggestions.value = [];

            return;
        }

        const body = (await response.json()) as { data: PatientSearchResult[] };
        suggestions.value = body.data;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            suggestions.value = [];
        }
    } finally {
        loading.value = false;
    }
}

function onItemSelect(event: { value: PatientSearchResult }): void {
    emit('select', event.value);
}
</script>

<template>
    <IconField>
        <InputIcon>
            <IconUserSearch class="size-4 text-surface-400" />
        </InputIcon>
        <AutoComplete
            v-model="model"
            :suggestions="suggestions"
            option-label="full_name"
            :loading="loading"
            :autofocus="autofocus"
            :disabled="disabled"
            :invalid="invalid"
            :placeholder="placeholderText"
            :complete-on-focus="false"
            show-clear
            input-class="w-full pl-10"
            fluid
            @complete="onComplete"
            @item-select="onItemSelect"
        >
            <template #option="{ option }">
                <div class="flex min-w-0 flex-col">
                    <span class="truncate font-medium text-surface-800">
                        {{ option.full_name }}
                    </span>
                    <span class="text-xs text-surface-500">
                        {{ option.phone ?? '—' }}
                    </span>
                </div>
            </template>
            <template #empty>
                <div class="px-3 py-2 text-sm text-surface-500">
                    {{
                        belowMinChars
                            ? t('patient.quick_find_min_chars', {
                                  count: MIN_CHARS,
                              })
                            : t('patient.quick_find_no_results')
                    }}
                </div>
            </template>
        </AutoComplete>
    </IconField>
</template>
