<?php

namespace App\Modules\Documents\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An archived PDF. It is generated once; reprints serve the stored file (AU-03).
 */
class Document extends Model
{
    use Auditable;

    protected $table = 'documents';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /** The storage path is internal: files are only served through the download endpoint. */
    protected $hidden = ['pdf_path'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime', 'whatsapp_sent_at' => 'datetime'];
    }

    public function editRequests(): HasMany
    {
        return $this->hasMany(EditRequest::class);
    }
}
