<?php

namespace App\Models;

use App\Integrations\Connectors\Catalog;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** One connector (library, exams): whether it is connected, its keys, and which of its tools are on. */
class Connector extends Model
{
    use Audited;

    protected $fillable = ['key', 'status', 'base_url', 'outgoing_token', 'incoming_token_hash', 'incoming_token_hint', 'tools', 'remote', 'state',
        'connected_by', 'connected_at', 'last_seen_at', 'last_sync_at', 'last_error', 'last_error_at'];

    protected $hidden = ['outgoing_token', 'incoming_token_hash'];

    protected function casts(): array
    {
        return [
            'outgoing_token' => 'encrypted', 'tools' => 'array', 'remote' => 'array', 'state' => 'array',
            'connected_at' => 'datetime', 'last_seen_at' => 'datetime', 'last_sync_at' => 'datetime', 'last_error_at' => 'datetime',
        ];
    }

    public static function for(string $key): self
    {
        abort_unless(Catalog::has($key), 404);

        return self::firstOrCreate(['key' => $key], ['tools' => Catalog::defaultTools($key)]);
    }

    /** The connector when it is connected and the tool is on; null otherwise. */
    public static function active(string $key, ?string $tool = null): ?self
    {
        $c = self::where('key', $key)->first();

        return $c && $c->isConnected() && ($tool === null || $c->toolOn($tool)) ? $c : null;
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function toolOn(string $tool): bool
    {
        return in_array($tool, $this->tools ?? [], true);
    }

    public function failed(string $error): void
    {
        $this->forceFill(['last_error' => mb_substr($error, 0, 500), 'last_error_at' => now()])->saveQuietly();
    }

    public function seen(): void
    {
        // at most one write a minute
        if (! $this->last_seen_at || $this->last_seen_at->lt(now()->subMinute())) {
            $this->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }
}
