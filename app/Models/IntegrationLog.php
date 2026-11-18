<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    protected $fillable = ['system', 'operation', 'idempotency_key', 'status', 'attempts', 'payload', 'response', 'error'];

    protected $casts = ['payload' => 'array', 'response' => 'array'];
}
