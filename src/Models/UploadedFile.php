<?php

namespace Opotopo\SellerApi\Models;

use Illuminate\Database\Eloquent\Model;

class UploadedFile extends Model
{
    protected $table = 'opotopo_files';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'modified_at' => 'integer',
    ];
}
