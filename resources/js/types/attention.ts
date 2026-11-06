export type AttentionLevel = 'urgent' | 'soon';

export type AttentionReason = {
    level: AttentionLevel;
    text: string;
};

export type AttentionRow = {
    id: number;
    name: string;
    client: { id: number; name: string } | null;
    level: AttentionLevel;
    reasons: AttentionReason[];
    reason_count: number;
};

export type NeedsAttentionOverview = {
    total: number;
    urgent: number;
    rows: AttentionRow[];
    showing_all: boolean;
};
