export type Machine = {
    id: number;
    serial: string | null;
    hardware_uuid: string | null;
    name: string | null;
    os: string | null;
    device_type: string | null;
    model: string | null;
    reports_count: number;
    last_report_at: string | null;
    created_at: string;
};

export type MachineDetails = Pick<
    Machine,
    'id' | 'name' | 'serial' | 'hardware_uuid' | 'os' | 'device_type' | 'model'
>;

export type ReportSummary = {
    id: number;
    created_at: string;
    flags: string[];
    markdown: string;
};

export type ChecklistOutcome = 'pass' | 'fail' | 'na';

export type ChecklistItem = {
    key: string;
    label: string;
    group: string;
    outcome: ChecklistOutcome | null;
    note: string | null;
};

export type MachineNote = {
    id: number;
    body: string;
    created_at: string;
};

export type MachinePhoto = {
    id: number;
    caption: string | null;
    width: number;
    height: number;
    url: string;
};
