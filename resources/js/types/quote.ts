export type PriceItem = {
    id: number;
    name: string;
    price_cents: number;
    unit: string | null;
};

export type EstimateVerdict = 'Repair' | 'Replace';

export type Estimate = {
    verdict: EstimateVerdict;
    reasons: string[];
};

export type Warranty = {
    maker: string;
    url: string;
    serial: string;
    serial_in_url: boolean;
};
