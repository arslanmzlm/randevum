import type { Paginated, TableState } from '@/types/table';
/** Canonical tag shape emitted by TagResource (list filter options + chip lookup). */
export type Tag = {
    id: number;
    name: string;
    /** '#RRGGBB'. */
    color: string;
};

/** Tag row on the management screen — adds the attached-patient count. */
export type TagWithCount = Tag & {
    patients_count: number;
};

export type TagIndexProps = {
    tags: Paginated<TagWithCount>;
    query: TableState;
};

/** Editable fields for the create/edit tag dialog. */
export type TagFormData = {
    name: string;
    /** Always '#RRGGBB'; the ColorField manages the leading '#'. */
    color: string;
};
