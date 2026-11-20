<?php

namespace App\Http\Controllers;

use App\Integrations\Connectors\Catalog;
use App\Integrations\Connectors\ConnectorException;
use App\Integrations\Connectors\LibraryClient;
use App\Jobs\SyncLibraryConnector;
use App\Models\Connector;
use App\Models\ConnectorMarkBatch;
use App\Models\IntegrationLog;
use App\Models\LibraryStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «الموصلات»: connect or disconnect the library and the exams system, and switch each tool on or off.
 * Only the system admin. A new key is shown once and never again.
 */
class ConnectorController extends Controller
{
    private const LOG_SYSTEM = ['library' => 'library', 'exams' => 'examnest'];

    public function index(): Response
    {
        Gate::authorize('manage-connectors');
        $connectors = collect(Catalog::CONNECTORS)->map(function (array $def, string $key) {
            $c = Connector::where('key', $key)->first();
            $at = fn ($d) => $d?->timezone('Asia/Baghdad')->format('Y-m-d H:i');

            return [
                'key' => $key, 'name' => $def['name'], 'product' => $def['product'], 'description' => $def['description'],
                'direction' => $def['direction'], 'url_required' => $def['url_required'],
                'connected' => (bool) $c?->isConnected(),
                'base_url' => $c?->base_url,
                'key_hint' => $c?->incoming_token_hint,
                'tools' => collect($def['tools'])->map(fn ($t, $k) => $t + ['key' => $k, 'on' => $c ? $c->toolOn($k) : true])->values(),
                'remote' => $c?->remote,
                'connected_at' => $at($c?->connected_at), 'last_seen_at' => $at($c?->last_seen_at), 'last_sync_at' => $at($c?->last_sync_at),
                'last_error' => $c?->last_error, 'last_error_at' => $at($c?->last_error_at),
                'activity' => IntegrationLog::where('system', self::LOG_SYSTEM[$key])->latest('id')->limit(6)->get()
                    ->map(fn ($l) => ['operation' => $l->operation, 'status' => $l->status, 'at' => $at($l->created_at), 'error' => $l->error ? Str::limit($l->error, 140) : null]),
                'stats' => $key === 'library'
                    ? ['students' => LibraryStatus::count(), 'not_clear' => LibraryStatus::where(fn ($q) => $q->where('active_loans', '>', 0)->orWhere('fines_due', '>', 0))->count()]
                    : ['pending' => ConnectorMarkBatch::where('status', 'pending')->count(), 'applied' => ConnectorMarkBatch::where('status', 'applied')->count()],
            ];
        })->values();

        return Inertia::render('Connectors/Index', [
            'connectors' => $connectors,
            'newKey' => session('connector_key'),
            'apiBase' => url('/api/connectors/v1'),
        ]);
    }

    public function connect(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('manage-connectors');
        abort_unless(Catalog::has($key), 404);
        $def = Catalog::get($key);
        $data = $request->validate([
            'base_url' => [$def['url_required'] ? 'required' : 'nullable', 'url:http,https', 'max:255'],
            'token' => [$def['direction'] === 'out' ? 'required' : 'nullable', 'string', 'min:20', 'max:255'],
        ], [], ['base_url' => 'العنوان', 'token' => 'المفتاح']);
        $url = isset($data['base_url']) ? rtrim($data['base_url'], '/') : null;

        $c = Connector::for($key);
        $fill = ['base_url' => $url, 'status' => 'connected', 'connected_by' => $request->user()->id, 'connected_at' => now(),
            'last_error' => null, 'last_error_at' => null, 'tools' => $c->tools ?: Catalog::defaultTools($key)];

        if ($def['direction'] === 'out') {
            try {
                $manifest = (new LibraryClient($url, $data['token']))->manifest();
            } catch (ConnectorException $e) {
                throw ValidationException::withMessages(['base_url' => $e->getMessage()]);
            }
            $c->update($fill + ['outgoing_token' => $data['token'], 'last_seen_at' => now(),
                'remote' => ['name' => Str::limit((string) ($manifest['name'] ?? 'FileNest'), 80), 'version' => Str::limit((string) ($manifest['version'] ?? ''), 20)]]);
            SyncLibraryConnector::dispatch(true);

            return back()->with('success', 'رُبطت المكتبة. تبدأ المزامنة الأولى الآن في الخلفية.');
        }

        $plain = $this->issueKey($c);
        $c->update($fill);

        return back()->with('success', 'رُبط نظام الامتحانات. انسخ المفتاح الآن، فلن يظهر مرة أخرى.')
            ->with('connector_key', ['connector' => $key, 'key' => $plain]);
    }

    public function disconnect(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('manage-connectors');
        $c = Connector::for($key);
        $c->update(['status' => 'disconnected', 'outgoing_token' => null, 'incoming_token_hash' => null, 'incoming_token_hint' => null]);

        return back()->with('success', 'أُلغي الربط. لم يعد بإمكان «'.Catalog::get($key)['name'].'» الوصول إلى النظام.');
    }

    public function tools(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('manage-connectors');
        $c = Connector::for($key);
        $data = $request->validate(['tools' => ['present', 'array'], 'tools.*' => ['string', 'in:'.implode(',', Catalog::toolKeys($key))]]);
        $c->update(['tools' => array_values(array_unique($data['tools']))]);

        return back()->with('success', 'حُفظت أدوات الموصل.');
    }

    public function rotate(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('manage-connectors');
        abort_unless(Catalog::has($key) && Catalog::get($key)['direction'] === 'in', 404);
        $c = Connector::for($key);
        abort_unless($c->isConnected(), 409);
        $plain = $this->issueKey($c);
        $c->save();

        return back()->with('success', 'صدر مفتاح جديد وتوقف القديم. ضعه في نظام الامتحانات.')
            ->with('connector_key', ['connector' => $key, 'key' => $plain]);
    }

    public function test(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('manage-connectors');
        $c = Connector::for($key);
        abort_unless($c->isConnected() && Catalog::get($key)['direction'] === 'out', 409);
        try {
            LibraryClient::for($c)->manifest();
            $c->forceFill(['last_seen_at' => now(), 'last_error' => null, 'last_error_at' => null])->saveQuietly();
        } catch (ConnectorException $e) {
            $c->failed($e->getMessage());

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'الاتصال بالمكتبة يعمل.');
    }

    public function sync(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('manage-connectors');
        abort_unless($key === 'library' && Connector::active('library'), 409);
        SyncLibraryConnector::dispatch(true);

        return back()->with('success', 'بدأت المزامنة في الخلفية. حدّث الصفحة بعد دقيقة.');
    }

    private function issueKey(Connector $c): string
    {
        $plain = 'sisc_'.Str::random(48);
        $c->fill(['incoming_token_hash' => hash('sha256', $plain), 'incoming_token_hint' => substr($plain, -4)]);

        return $plain;
    }
}
