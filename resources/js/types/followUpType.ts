import type { Paginated, TableState } from '@/types/table';

/** Canonical follow-up-type shape emitted by FollowUpTypeResource (index + edit). */
export type FollowUpType = {
    id: number;
    name: string;
    /** Seeded row: never deletable, only deactivated. */
    is_system: boolean;
    is_active: boolean;
};

/** Server-side list state echoed back by the controller. */
export type FollowUpTypeQuery = TableState<{
    is_active: boolean | null;
}>;

export type FollowUpTypeIndexProps = {
    followUpTypes: Paginated<FollowUpType>;
    query: FollowUpTypeQuery;
    /** Row behind a `?edit=<id>` link, resolved server-side so it opens even when off-page. */
    editing: FollowUpType | null;
};

/** Editable fields shared by the create and edit forms. */
export type FollowUpTypeFormData = {
    name: string;
    is_active: boolean;
};
