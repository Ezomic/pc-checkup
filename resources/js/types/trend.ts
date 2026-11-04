export type TrendPoint = {
    at: string;
    value: number;
    label: string;
};

export type Trend = {
    id: 'disk' | 'battery' | 'memory';
    title: string;
    points: TrendPoint[];
    latest: string;
    change: string;
    sentence: string | null;
    tone: 'bad' | 'neutral';
};
