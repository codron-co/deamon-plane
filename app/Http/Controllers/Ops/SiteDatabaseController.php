<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Databases\DatabaseQueryResult;
use App\Services\Databases\DatabaseServerClient;
use App\Services\Databases\DatabaseServerException;
use App\Services\Databases\SqlStatementGuard;
use App\Services\Databases\SqlStatementRejected;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A site's database on a shared server: tables and a one-statement SQL console.
 * Viewing customer data needs ops.write; changing it needs ops.danger plus the
 * database name typed back. Every statement is written to the audit log.
 */
class SiteDatabaseController extends Controller
{
    public function show(Request $request, Site $site, DatabaseServerClient $client): View
    {
        $this->authorize('ops.write');

        return $this->page($request, $site, $client);
    }

    public function run(Request $request, Site $site, DatabaseServerClient $client): View
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:20000'],
            'allow_write' => ['sometimes', 'boolean'],
            'confirm' => ['nullable', 'string', 'max:64'],
        ]);

        $sql = (string) $validated['sql'];
        $allowWrite = (bool) ($validated['allow_write'] ?? false);

        if (! $site->usesSharedDatabase()) {
            return $this->page($request, $site, $client, sql: $sql, error: __('databases.errors.not_shared'));
        }

        if ($allowWrite) {
            $this->authorize('ops.danger');
            if (trim((string) ($validated['confirm'] ?? '')) !== (string) $site->db_database) {
                return $this->page($request, $site, $client, sql: $sql, error: __('databases.errors.confirm_mismatch'));
            }
        }

        try {
            $result = $client->run($site, $sql, $allowWrite);
        } catch (SqlStatementRejected $e) {
            return $this->page($request, $site, $client, sql: $sql, error: __('databases.errors.rejected.'.$e->reason));
        } catch (DatabaseServerException $e) {
            $this->audit($request, $site, $sql, null, $e->getMessage());

            return $this->page($request, $site, $client, sql: $sql, error: __('databases.errors.query_failed', ['message' => $e->getMessage()]));
        }

        $this->audit($request, $site, $sql, $result, null);

        return $this->page($request, $site, $client, sql: $sql, result: $result);
    }

    private function page(Request $request, Site $site, DatabaseServerClient $client, string $sql = '', ?DatabaseQueryResult $result = null, ?string $error = null): View
    {
        $site->loadMissing('databaseServer');
        $tables = [];
        $tablesError = null;

        if ($site->usesSharedDatabase()) {
            try {
                $tables = $client->tables($site);
            } catch (DatabaseServerException $e) {
                $tablesError = __('databases.errors.query_failed', ['message' => $e->getMessage()]);
            }
        }

        return view('ops.sites.database', [
            'site' => $site,
            'tables' => $tables,
            'tablesError' => $tablesError,
            'sql' => $sql,
            'result' => $result,
            'error' => $error,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
            'maxRows' => DatabaseServerClient::MAX_ROWS,
        ]);
    }

    private function audit(Request $request, Site $site, string $sql, ?DatabaseQueryResult $result, ?string $error): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $result?->kind === SqlStatementGuard::WRITE ? 'site.database_write' : 'site.database_query',
            'before' => null,
            'after' => array_filter([
                'database' => $site->db_database,
                'sql' => Str::limit(SqlStatementGuard::normalize($sql), 2000),
                'rows' => $result !== null && $result->affected === null ? count($result->rows) : null,
                'affected' => $result?->affected,
                'error' => $error !== null ? Str::limit($error, 500) : null,
            ], static fn (mixed $value): bool => $value !== null),
            'ip' => $request->ip(),
        ]);
    }
}
