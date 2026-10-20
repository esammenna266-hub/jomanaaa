<?php

namespace App\Modules\Core\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an external office pays the company for one service on one route (AG-03). Internal: it is never
 * printed on a document the passenger or the customer gets.
 */
class AgentPrice extends Model
{
    use Auditable;

    protected $table = 'agent_prices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'active' => 'boolean'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
