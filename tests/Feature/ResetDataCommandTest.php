<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * zulu:reset-data — wipes entered data, keeps the super admin + all config.
 */
class ResetDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipes_data_keeps_super_admin_and_config(): void
    {
        $superRole = Role::firstOrCreate(['name' => 'super_admin', 'scope' => 'platform']);
        $opRole = Role::firstOrCreate(['name' => 'tdd_op', 'scope' => 'company']);

        // Keep-set: arshak (super admin) + his company + pivot.
        $arshak = User::query()->create([
            'name' => 'Arshak', 'email' => 'arshakbabayan@gmail.com',
            'password' => bcrypt('secret'), 'status' => User::STATUS_ACTIVE,
        ]);
        $arshakCo = Company::query()->create(['name' => 'ZULU Platform', 'type' => 'operator']);
        UserCompany::query()->create(['user_id' => $arshak->id, 'company_id' => $arshakCo->id, 'role_id' => $superRole->id]);

        // Extra tenant + customer — must be deleted.
        $other = User::query()->create([
            'name' => 'Cust', 'email' => 'cust@example.test',
            'password' => bcrypt('secret'), 'status' => User::STATUS_ACTIVE,
        ]);
        $otherCo = Company::query()->create(['name' => 'Op Co', 'type' => 'operator']);
        UserCompany::query()->create(['user_id' => $other->id, 'company_id' => $otherCo->id, 'role_id' => $opRole->id]);

        // Config that MUST survive (a KEEP table not in the truncate set).
        DB::table('statuses')->insert(['entity_type' => 'booking', 'name' => 'Confirmed', 'code' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        $statusBefore = DB::table('statuses')->count();

        // Entered DATA that must be wiped (a table in the truncate set).
        DB::table('faqs')->insert([
            'category' => 'general', 'question_hy' => 'ա', 'question_ru' => 'а', 'question_en' => 'a',
            'answer_hy' => 'բ', 'answer_ru' => 'б', 'answer_en' => 'b', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('zulu:reset-data', ['--force' => true])->assertExitCode(0);

        // Super admin preserved
        $this->assertSame(1, DB::table('users')->count());
        $this->assertNotNull(DB::table('users')->where('id', $arshak->id)->first());
        $this->assertTrue((bool) User::find($arshak->id)->is_super_admin);

        // Only his company remains
        $this->assertSame(1, DB::table('companies')->count());
        $this->assertNotNull(DB::table('companies')->where('id', $arshakCo->id)->first());

        // Other tenant gone
        $this->assertNull(DB::table('users')->where('id', $other->id)->first());
        $this->assertNull(DB::table('companies')->where('id', $otherCo->id)->first());

        // Config survived, data wiped
        $this->assertSame($statusBefore, DB::table('statuses')->count());
        $this->assertSame(0, DB::table('faqs')->count());
    }

    public function test_aborts_and_deletes_nothing_when_super_admin_unresolvable(): void
    {
        $u = User::query()->create([
            'name' => 'X', 'email' => 'x@example.test',
            'password' => bcrypt('secret'), 'status' => User::STATUS_ACTIVE,
        ]);

        $this->artisan('zulu:reset-data', ['--force' => true, '--keep-email' => 'nobody@nowhere.test'])
            ->assertExitCode(1);

        // Nothing deleted — the user is still there.
        $this->assertNotNull(DB::table('users')->where('id', $u->id)->first());
    }
}
