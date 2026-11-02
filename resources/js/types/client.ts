import type { Machine } from './machine';

export type ClientSummary = {
    id: number;
    name: string;
    machines_count: number;
    has_share_link: boolean;
    last_viewed_at: string | null;
};

export type ClientDetails = {
    id: number;
    name: string;
    share_url: string | null;
    last_viewed_at: string | null;
    created_at: string;
};

export type ClientMachine = Pick<
    Machine,
    'id' | 'name' | 'model' | 'os' | 'device_type' | 'status'
>;

export type AssignableMachine = Pick<Machine, 'id' | 'name' | 'model'> & {
    client_name: string | null;
};

export type ClientOption = {
    id: number;
    name: string;
};
