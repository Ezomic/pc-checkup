<?php

declare(strict_types=1);

it('configures the sqlite connection for concurrent writers', function (): void {
    expect(config('database.connections.sqlite'))
        ->busy_timeout->toBe(5000)
        ->journal_mode->toBe('wal')
        ->synchronous->toBe('normal');
});
