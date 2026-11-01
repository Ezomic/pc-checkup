export type ApiToken = {
    id: number;
    name: string;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
    expires_at_diff: string | null;
    expired: boolean;
};
