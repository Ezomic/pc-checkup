export type PairingStatus =
    'pending' | 'approved' | 'rejected' | 'expired' | 'used';

export type PairingMachine = {
    id: number;
    label: string;
};

export type PairingClient = {
    id: number;
    name: string;
    machines: PairingMachine[];
};

export type PairingDetails = {
    id: string;
    checkCode: string;
    status: PairingStatus;
    expiresAt: string;
    client: { id: number; name: string } | null;
    machine: PairingMachine | null;
};
