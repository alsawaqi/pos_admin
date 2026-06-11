<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI checkouts have no built frontend (public/build is gitignored),
        // so any view rendering @vite would 500 with "Vite manifest not
        // found". Locally a manifest may exist from past builds — disabling
        // Vite in tests keeps both environments identical.
        $this->withoutVite();
    }
}
