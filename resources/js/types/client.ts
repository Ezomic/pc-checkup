import type { AttentionLevel, AttentionReason } from './attention';
import type { ScanVerdict } from './history';
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
    locale: 'nl' | 'en';
    share_url: string | null;
    last_viewed_at: string | null;
    created_at: string;
};

export type ClientMachine = Pick<
    Machine,
    'id' | 'name' | 'model' | 'os' | 'device_type' | 'status'
> & {
    last_scan_at: string | null;
    level: AttentionLevel | 'fine';
    reasons: AttentionReason[];
    reason_count: number;
    verdict: ScanVerdict | null;
};

export type ClientMachineSummary = {
    total: number;
    urgent: number;
    soon: number;
    fine: number;
};

export type AssignableMachine = Pick<Machine, 'id' | 'name' | 'model'> & {
    client_name: string | null;
};

export type ClientOption = {
    id: number;
    name: string;
};
