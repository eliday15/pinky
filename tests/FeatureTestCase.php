<?php

namespace Tests;

use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;

/**
 * Base class for HTTP/feature tests that exercise the authenticated app.
 *
 * Migrates a fresh SQLite database and seeds the canonical roles &
 * permissions so role-based access control can be asserted. Use the
 * InteractsWithAuth helpers (adminUser(), actingAsRrhh(), ...) to create
 * users that satisfy every middleware guard.
 */
abstract class FeatureTestCase extends TestCase
{
    use InteractsWithAuth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El memo estático de settings sobrevive entre tests del mismo proceso;
        // sin esta limpieza un test heredaría valores del anterior.
        \App\Models\SystemSetting::forgetMemo();

        $this->seed(RolesPermissionsSeeder::class);
    }
}
