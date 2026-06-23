<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures\Inheritance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['alpha', 'beta'])]
#[Hidden(['secret'])]
class ParentFillableModel extends Model {}
