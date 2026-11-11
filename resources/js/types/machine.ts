export type MachineStatus = 'ok' | 'needs_work' | 'reject';

export type Machine = {
    id: number;
    status: MachineStatus;
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

export type FlagSeverity = 'high' | 'medium' | 'low';

export type FlagAdvice = {
    severity: FlagSeverity;
    headline: string;
    advice: string | null;
    raw: string;
};

export type MachineCard = Machine & {
    top_issue: FlagAdvice | null;
    issue_count: number;
    stale: boolean;
};

export type ReportFacts = {
    free_disk: string | null;
    memory: string | null;
    cpu: string | null;
};

export type ReportScript = {
    version: string | null;
    current: string;
    outdated: boolean;
    ranOutdated: boolean;
};

export type LatestReport = {
    id: number;
    created_at: string;
    stale: boolean;
    script: ReportScript | null;
    problems: string[];
    advice: FlagAdvice[];
    facts: ReportFacts;
    markdown: string;
};

export type DetailTone = 'good' | 'bad' | 'neutral';

export type DetailRow = {
    label: string;
    value: string;
    tone: DetailTone;
};

export type DetailBar = {
    label: string;
    value: string;
    detail: string | null;
    share: number;
    inside?: { title: string; items: DetailBar[] };
    children?: DetailBar[];
};

export type DetailBarGroup = {
    title: string;
    items: DetailBar[];
};

export type DetailStatus = 'attention' | 'fine' | 'neutral';

export type DetailCard = {
    id: string;
    title: string;
    rows: DetailRow[];
    list: { title: string; items: DetailRow[] } | null;
    summary?: string | null;
    bars?: DetailBarGroup[];
    notes?: string[];
    group: string;
    status: DetailStatus;
};

export type VerdictCard = Omit<DetailCard, 'group' | 'status'>;

export type MachineDetails = Pick<
    Machine,
    | 'id'
    | 'name'
    | 'serial'
    | 'hardware_uuid'
    | 'os'
    | 'device_type'
    | 'model'
    | 'status'
>;

export type OwnerMachineDetails = MachineDetails & {
    rustdesk_id: string | null;
};

export type SummaryReport = Pick<ReportSummary, 'created_at' | 'flags'>;

export type SummaryChecklistItem = Omit<ChecklistItem, 'outcome'>;

export type SummaryChecklist = Record<
    ChecklistOutcome | 'unchecked',
    SummaryChecklistItem[]
>;

export type ReportKind = 'audit' | 'after-fix';

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

export type FixStatus = 'applied' | 'skipped' | 'failed';

export type FixEntry = {
    id: string;
    status: FixStatus;
    command: string;
    undo: string | null;
};

export type AfterFixReport = {
    id: number;
    created_at: string;
    flags: string[];
    fixes: FixEntry[];
    before: { id: number; created_at: string; flags: string[] } | null;
    resolved: string[];
    open: string[];
    introduced: string[];
};

export type BenchmarkRating = 'healthy' | 'fair' | 'poor';

export type BenchmarkMetricRow = {
    key: string;
    label: string;
    unit: string;
    value: number;
    rating: BenchmarkRating;
    healthy: number;
    poor: number;
    higher_is_better: boolean;
};

export type BenchmarkHistoryValue = {
    key: string;
    label: string;
    unit: string;
    value: number;
    delta: number | null;
    improved: boolean | null;
};

export type BenchmarkHistoryRow = {
    id: number;
    created_at: string;
    kind: ReportKind;
    values: BenchmarkHistoryValue[];
};

export type BenchmarkComparisonRow = {
    key: string;
    label: string;
    unit: string;
    before: number;
    after: number;
    delta: number;
    improved: boolean | null;
};

export type MachineBenchmark = {
    latest: {
        id: number;
        created_at: string;
        kind: ReportKind;
        metrics: BenchmarkMetricRow[];
    };
    history: BenchmarkHistoryRow[];
    comparison: BenchmarkComparisonRow[] | null;
};

export type NumberChange = {
    from: string;
    to: string;
};

export type ReportChanges = {
    previous_at: string | null;
    new: string[];
    fixed: string[];
    open: number;
    newly_checked: string[];
    disk: NumberChange | null;
    memory: NumberChange | null;
    same_rights: boolean;
};
