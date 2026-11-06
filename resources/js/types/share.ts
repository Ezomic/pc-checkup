import type {
    FlagAdvice,
    Machine,
    MachineStatus,
    ReportFacts,
} from './machine';

export type SharedAdvice = Pick<FlagAdvice, 'severity' | 'headline' | 'advice'>;

export type SharedMachine = Pick<
    Machine,
    'id' | 'name' | 'os' | 'device_type' | 'model'
> & {
    status: Exclude<MachineStatus, 'reject'> | null;
};

export type SharedMachineCard = SharedMachine & {
    top_issue: SharedAdvice | null;
    issue_count: number;
    scanned_at: string | null;
};

export type SharedReport = {
    scanned_at: string | null;
    facts: ReportFacts;
    advice: SharedAdvice[];
    fixed_count: number | null;
};

export type BeforeAfterNumberKey =
    | 'free_disk'
    | 'memory'
    | 'startup'
    | 'battery_cycles'
    | 'battery_condition'
    | 'disk_read'
    | 'disk_write';

export type BeforeAfterNumber = {
    key: BeforeAfterNumberKey;
    from: string;
    to: string;
    improved: boolean | null;
};

export type BeforeAfter = {
    before: string | null;
    after: string | null;
    same_rights: boolean;
    fixed: string[];
    open: string[];
    new: string[];
    numbers: BeforeAfterNumber[];
};

export type ScanOption = {
    id: number;
    scanned_at: string | null;
};
