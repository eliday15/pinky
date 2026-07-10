<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Services\ZktecoSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

/**
 * syncEmployees() importa usuarios nuevos del checador como empleados. Cuando
 * RRHH desvincula a un empleado (marcar "No checa" borra su zkteco_user_id) o
 * lo da de baja (el soft-delete renombra su número a EMP-XXXX_deleted_<id>),
 * el usuario del checador queda huérfano y el sync NO debe re-importarlo:
 * chocaría con el unique de employee_number (esto congeló el sync completo del
 * 2026-07-08 al 2026-07-10) o resucitaría una baja como empleado fantasma.
 */
class ZktecoSyncEmployeesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Las tablas del checador (`users`/`attendance`) las crea el agente
        // Python directo en MySQL; no existen como migraciones. Se recrean
        // aquí con las columnas que lee syncEmployees().
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name')->nullable();
            $table->integer('privilege')->default(0);
            $table->string('group_id')->nullable();
            $table->integer('device_id')->default(1);
            $table->timestamps();
        });

        Schema::dropIfExists('attendance');
        Schema::create('attendance', function ($table) {
            $table->increments('id');
            $table->integer('device_id')->default(1);
            $table->integer('user_id');
            $table->dateTime('timestamp');
            $table->integer('status')->default(0);
            $table->integer('punch')->default(0);
        });

        // SQLite no trae el operador REGEXP (usado por el subquery de nombres);
        // se registra la función para que `X REGEXP Y` → regexp(Y, X) funcione.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction(
                'regexp',
                fn ($pattern, $value) => preg_match('/'.$pattern.'/', (string) $value) ? 1 : 0
            );
        }
    }

    private function seedDeviceUser(int $userId, string $name): void
    {
        DB::table('users')->insert([
            'user_id' => $userId,
            'name' => $name,
            'privilege' => 0,
            'device_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance')->insert([
            'device_id' => 1,
            'user_id' => $userId,
            'timestamp' => Carbon::now()->subDay(),
        ]);
    }

    public function test_importa_usuario_nuevo_del_checador(): void
    {
        $this->seedDeviceUser(500, 'JUAN PEREZ LOPEZ');

        $stats = app(ZktecoSyncService::class)->syncEmployees();

        $this->assertSame(1, $stats['imported']);
        $this->assertDatabaseHas('employees', [
            'employee_number' => 'EMP-0500',
            'zkteco_user_id' => 500,
            'status' => 'active',
        ]);
    }

    public function test_no_aborta_ni_reimporta_cuando_el_numero_pertenece_a_un_desvinculado(): void
    {
        // Caso prod 2026-07-08: Ramiro (EMP-0013) marcado "No checa" → el UI
        // borró su zkteco_user_id; el usuario 13 (Clara) sigue en el checador.
        Employee::factory()->create([
            'employee_number' => 'EMP-0013',
            'zkteco_user_id' => null,
            'is_attendance_exempt' => true,
        ]);
        $this->seedDeviceUser(13, 'CLARA ALCAZAR MORALES');
        $this->seedDeviceUser(500, 'JUAN PEREZ LOPEZ');

        $stats = app(ZktecoSyncService::class)->syncEmployees();

        // El usuario 13 se salta sin excepción y el sync continúa con el 500
        // (el 13 tiene user_id menor, así que se procesa primero).
        $this->assertSame(1, $stats['imported']);
        $this->assertNull(Employee::withTrashed()->where('zkteco_user_id', 13)->first());
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-0500']);
    }

    public function test_no_resucita_empleado_dado_de_baja(): void
    {
        // Caso prod 2026-07-09: baja de Ramiro Emanuel → el soft-delete liberó
        // su número renombrándolo a EMP-0187_deleted_<id>, pero el usuario 187
        // sigue en el checador. Sin el guard, el sync lo recrearía como
        // empleado fantasma activo.
        $employee = Employee::factory()->create([
            'employee_number' => 'EMP-0187',
            'zkteco_user_id' => null,
        ]);
        $employee->delete();
        $this->assertDatabaseHas('employees', [
            'employee_number' => 'EMP-0187_deleted_'.$employee->id,
        ]);

        $this->seedDeviceUser(187, 'NN-187');

        $stats = app(ZktecoSyncService::class)->syncEmployees();

        $this->assertSame(0, $stats['imported']);
        $this->assertNull(Employee::withTrashed()->where('zkteco_user_id', 187)->first());
        $this->assertNull(Employee::where('employee_number', 'EMP-0187')->first());
    }
}
