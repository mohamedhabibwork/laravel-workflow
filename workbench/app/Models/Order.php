<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    protected $table = 'host_orders_attributes';

    protected $guarded = [];
}
