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
