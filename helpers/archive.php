<?php
/**
 * Shared soft-archive helpers.
 *
 * `is_archived`/`archived_at` are the new source of truth. The legacy status
 * value is included in the predicates so rows archived by older releases stay
 * hidden until an explicit unarchive action is performed.
 */

function archiveColumn(string $alias, string $column): string
{
    $prefix = $alias === '' ? '' : $alias . '.';
    return $prefix . $column;
}

function archivedSql(string $alias = ''): string
{
    $prefix = $alias === '' ? '' : $alias . '.';
    return "(COALESCE({$prefix}is_archived, 0) = 1 OR {$prefix}status = 'ARCHIVED')";
}

function activeSql(string $alias = ''): string
{
    $prefix = $alias === '' ? '' : $alias . '.';
    return "(COALESCE({$prefix}is_archived, 0) = 0 AND {$prefix}status <> 'ARCHIVED')";
}

function effectiveArchiveStatus(array $row): string
{
    if ((int) ($row['is_archived'] ?? 0) === 1 || strtoupper((string) ($row['status'] ?? '')) === 'ARCHIVED') {
        return 'ARCHIVED';
    }
    return strtoupper((string) ($row['status'] ?? ''));
}

function addEffectiveArchiveFields(array &$row): void
{
    $row['is_archived'] = (int) ($row['is_archived'] ?? 0) === 1 ? 1 : 0;
    $row['archived_at'] = $row['archived_at'] ?? null;
    $row['status'] = effectiveArchiveStatus($row);
}
