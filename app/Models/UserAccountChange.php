<?php

namespace App\Models;

use App\Exceptions\ImmutableUserAccountChangeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row for account provisioning and identity changes.
 *
 * Model events do not fire for query-builder mass updates/deletes — do not
 * use DB::table(...)->update/delete on this table from application code.
 */
class UserAccountChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableUserAccountChangeException::forOperation('update');
        });

        static::deleting(function () {
            throw ImmutableUserAccountChangeException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableUserAccountChangeException::forOperation('update');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw ImmutableUserAccountChangeException::forOperation('delete');
        }

        return parent::delete();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
