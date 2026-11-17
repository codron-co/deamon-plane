<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Agent\ModuleAgentResult;
use App\Services\Agent\SiteAgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Site detail → Modüller: the CMS module catalog read live over the signed agent
 * (GET/POST /internal/control/v1/modules). Turning a module on installs it with
 * its dependencies when needed; turning it off only disables it (data kept).
 * Plane stores nothing about modules; every toggle lands in the audit log.
 */
class SiteModuleController extends Controller
{
    /** Display order of the CMS module-store categories; unknown ones go last ("other"). */
    public const CATEGORY_ORDER = [
        'content', 'catalog', 'commerce', 'marketing', 'forms', 'media', 'analytics', 'seo', 'integrations', 'business',
    ];

    public function __construct(
        private readonly SiteAgentClient $agent = new SiteAgentClient,
    ) {}

    /**
     * HTML fragment for the Modüller tab, fetched after the page so a slow or
     * unreachable CMS never holds the detail page open.
     */
    public function panel(Request $request, Site $site): Response
    {
        $this->authorize('view', $site);

        $result = $this->agent->listModules($site);

        return response()->view('ops.sites._modules_body', [
            'site' => $site,
            'modulesResult' => $result,
            'moduleGroups' => self::group($result->modules),
            'canToggleModules' => $request->user()?->can('update', $site) ?? false,
        ]);
    }

    /** JSON catalog (MCP `list_site_modules`). */
    public function index(Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        $result = $this->agent->listModules($site);
        if (! $result->ok) {
            return $this->failure($result);
        }

        return response()->json([
            'ok' => true,
            'type' => 'status',
            'message' => '',
            'modules' => $result->modules,
        ]);
    }

    /** Turn one module on or off (panel fetch and MCP `set_site_module`). Always JSON. */
    public function update(Request $request, Site $site): JsonResponse
    {
        $this->authorize('update', $site);

        $validator = Validator::make($request->all(), [
            'module' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'enabled' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'type' => 'validation_error',
                'message' => (string) $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $slug = (string) $request->input('module');
        $enabled = $request->boolean('enabled');

        // Read the catalog first: an unknown or core module never reaches the toggle,
        // and the audit row records the state before the change.
        $catalog = $this->agent->listModules($site);
        if (! $catalog->ok) {
            $this->audit($request, $site, 'site.module.toggle_failed', null, [
                'module' => $slug,
                'enabled' => $enabled,
                'error' => $catalog->errorCode ?? ($catalog->unsupported ? 'unsupported' : 'agent_error'),
                'message' => $catalog->safeMessage,
            ]);

            return $this->failure($catalog);
        }

        $current = collect($catalog->modules)->firstWhere('slug', $slug);
        if (! is_array($current)) {
            return response()->json([
                'ok' => false,
                'type' => 'validation_error',
                'message' => (string) __('site_modules.errors.unknown_module'),
            ], 422);
        }

        if ($current['locked'] && ! $enabled) {
            return response()->json([
                'ok' => false,
                'type' => 'validation_error',
                'message' => (string) __('site_modules.errors.locked'),
            ], 422);
        }

        $before = ['module' => $slug, 'installed' => $current['installed'], 'enabled' => $current['enabled']];
        $result = $this->agent->setModule($site, $slug, $enabled);

        if (! $result->ok) {
            $this->audit($request, $site, 'site.module.toggle_failed', $before, [
                'module' => $slug,
                'enabled' => $enabled,
                'error' => $result->errorCode ?? ($result->unsupported ? 'unsupported' : 'agent_error'),
                'message' => $result->safeMessage,
            ]);

            return $this->failure($result);
        }

        $nowEnabled = $result->enabled ?? $enabled;
        $this->audit($request, $site, $nowEnabled ? 'site.module.enabled' : 'site.module.disabled', $before, [
            'module' => $slug,
            'enabled' => $nowEnabled,
            'changed' => $result->changed,
        ]);

        $message = match (true) {
            $result->changed === [] => __('site_modules.flash.unchanged'),
            $nowEnabled => __('site_modules.flash.enabled', ['list' => implode(', ', $result->changed)]),
            default => __('site_modules.flash.disabled', ['list' => implode(', ', $result->changed)]),
        };

        return response()->json([
            'ok' => true,
            'type' => 'status',
            'message' => (string) $message,
            'module' => $slug,
            'enabled' => $nowEnabled,
            'changed' => $result->changed,
        ]);
    }

    /**
     * Modules grouped by category in a fixed order, each group sorted by name.
     *
     * @param  list<array<string, mixed>>  $modules
     * @return list<array{key: string, label: string, modules: list<array<string, mixed>>}>
     */
    public static function group(array $modules): array
    {
        $groups = [];
        foreach ($modules as $module) {
            $category = (string) ($module['category'] ?? '');
            $key = in_array($category, self::CATEGORY_ORDER, true) ? $category : 'other';
            $groups[$key][] = $module;
        }

        $ordered = [];
        foreach ([...self::CATEGORY_ORDER, 'other'] as $key) {
            if (! isset($groups[$key])) {
                continue;
            }

            $rows = $groups[$key];
            usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
            $ordered[] = [
                'key' => $key,
                'label' => (string) __('site_modules.categories.'.$key),
                'modules' => $rows,
            ];
        }

        return $ordered;
    }

    private function failure(ModuleAgentResult $result): JsonResponse
    {
        $type = match (true) {
            $result->needsSecret => 'needs_agent_secret',
            $result->unsupported => 'unsupported',
            default => 'agent_error',
        };

        return response()->json([
            'ok' => false,
            'type' => $type,
            'message' => $result->safeMessage,
            'error' => $result->errorCode,
        ], $result->httpStatus === 422 ? 422 : 502);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, Site $site, string $action, ?array $before, array $after): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
