export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/**
 * The shape Laravel's LengthAwarePaginator serialises to. `links` is the
 * rendered previous/number/next set and always carries a first and last entry,
 * which is why the Paginator component slices them off rather than filtering.
 */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    first_page_url: string;
    from: number | null;
    last_page: number;
    last_page_url: string;
    links: PaginationLink[];
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
};
