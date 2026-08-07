/** One matrix column: the role row that is effective for the active clinic. */
export type RoleColumn = {
    id: number;
    /** 'owner' | 'manager' | 'doctor' | 'receptionist' | 'assistant' | <custom>. */
    name: string;
    /** Server-resolved label from lang/<locale>/role.php, falls back to `name`. */
    label: string;
    /** True when this clinic holds its own copy of a BASELINE role. */
    is_customized: boolean;
    /** True when the clinic created this role itself (never a baseline name). */
    is_custom: boolean;
    /** True when the acting user holds this role in the active clinic. */
    is_own: boolean;
    /** Assignments in THIS clinic only. */
    assigned_users_count: number;
    /** Mirrors exactly what the server allows to be deleted. */
    can_delete: boolean;
};

/** One matrix row: a single permission and the columns that hold it. */
export type PermissionRow = {
    /** Raw permission name, e.g. 'patients.note.update'. */
    name: string;
    /** Server-resolved label from lang/<locale>/permission.php, falls back to `name`. */
    label: string;
    /** Ids from the `roles` column list that hold this permission. */
    role_ids: number[];
    /** Cells the server refuses to change (self-lockout protection). */
    locked_role_ids: number[];
};

/** Permissions bucketed by resource group, in server-decided display order. */
export type PermissionGroup = {
    key: string;
    label: string;
    permissions: PermissionRow[];
};

export type RoleMatrixProps = {
    roles: RoleColumn[];
    groups: PermissionGroup[];
};

/** Create form for a clinic's own role; renaming is not supported. */
export type RoleFormData = {
    name: string;
};

/** One entry of the bulk matrix save — only roles whose set actually changed. */
export type RolePermissionPayload = {
    id: number;
    permissions: string[];
};

/** Working copy of the matrix: role id → the permission names it should hold. */
export type PermissionDraft = Record<number, string[]>;
