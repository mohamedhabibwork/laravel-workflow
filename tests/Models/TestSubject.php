<?php

namespace HFlow\LaravelWorkflow\Tests\Models;

use HFlow\LaravelWorkflow\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

class TestSubject extends Model
{
    use HasWorkflow;

    protected $guarded = [];

    public $table = 'test_subjects';
}
