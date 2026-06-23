<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures\Inheritance;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['alpha'])]
class ChildOverridingModel extends ParentFillableModel {}
