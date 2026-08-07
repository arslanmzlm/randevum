/** One matrix column: the role row that is effective for the active clinic. */
export type RoleColumn = {
    id: number;
    /** 'owner' | 'manager' | 'doctor' | 'receptionist' | 'assistant' | <custom>. */
    name: string;
    /** Server-resolved label from lang/<locale>/role.php, falls back to `name`. */
    label: string;
    /** True when this clinic holds its own copy of the baseline role. */
    is_customized: boolean;
};

/** One matrix row: a single permission and the columns that hold it. */
export type PermissionRow = {
    /** Raw permission name, e.g. 'patients.note.update'. */
    name: string;
    /** Server-resolved label from lang/<locale>/permission.php, falls back to `name`. */
    label: string;
    /** Ids from the `roles` column list that hold this permission. */
    role_ids: number[];
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
