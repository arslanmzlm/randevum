import { inject, provide  } from 'vue';
import type {InjectionKey} from 'vue';
import type { FollowUpInterval } from '@/types/treatment';

/**
 * Client-only generator params: they shape the occurrence list but are never submitted (only
 * `occurrences` is). The page owns the state so the details card can host count/interval while
 * the generator below owns start date/time and the seeds; both read the same object instead of
 * passing it down as a prop (which children may not mutate).
 */
export interface BulkGeneratorState {
    date: Date | null;
    time: string;
    count: number;
    interval: FollowUpInterval;
    /** Spacing in days when `interval` is 'custom'. */
    interval_days: number;
    duration_minutes: number | null;
    appointment_type_id: number | null;
}

const key = Symbol('bulk-generator') as InjectionKey<BulkGeneratorState>;

export function provideBulkGenerator(state: BulkGeneratorState): void {
    provide(key, state);
}

export function useBulkGenerator(): BulkGeneratorState {
    const state = inject(key);

    if (!state) {
        throw new Error('Bulk generator state was not provided.');
    }

    return state;
}
