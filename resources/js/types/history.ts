import type { Machine, NumberChange, ReportKind } from './machine';

export type ScanVerdict = 'Keep' | 'Repair' | 'Replace';

export type HistoryMachine = Pick<
    Machine,
    'id' | 'name' | 'model' | 'device_type'
>;

export type HistoryScan = {
    id: number;
    created_at: string;
    kind: ReportKind;
    script_version: string | null;
    ran_as_admin: boolean | null;
    free_disk: string | null;
    flags_count: number;
    verdict: ScanVerdict | null;
};

export type ScanReference = {
    id: number;
    created_at: string;
};

export type ScanComparisonResult = {
    before: ScanReference;
    after: ScanReference;
    new: string[];
    fixed: string[];
    open: number;
    disk: NumberChange | null;
    memory: NumberChange | null;
    verdict: { from: ScanVerdict; to: ScanVerdict } | null;
    same_rights: boolean;
};
