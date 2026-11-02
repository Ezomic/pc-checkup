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
};
