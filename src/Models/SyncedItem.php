<?php

namespace Opotopo\SellerApi\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedItem extends Model
{
    protected $table = 'opotopo_items';

    protected $guarded = [];

    protected $casts = [
        'error_details' => 'array',
        'synced_at' => 'datetime',
    ];
}
