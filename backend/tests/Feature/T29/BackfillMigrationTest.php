<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

function vvT29Backfill(): Migration
{
    return require database_path('migrations/2026_10_20_110000_backfill_parent_consent_status.php');
}

test('(f) backfill: moi dong pending/granted/revoked ve not_required, chay lan 2 khong loi', function () {
    $users = collect(['pending', 'granted', 'revoked', 'not_required', 'pending'])
        ->map(fn ($s) => User::factory()->student()->create())
        ->each(fn () => null);

    foreach (['pending', 'granted', 'revoked', 'not_required', 'pending'] as $i => $status) {
        DB::table('users')->where('id', $users[$i]->id)->update(['parent_consent_status' => $status]);
    }

    vvT29Backfill()->up();

    expect(DB::table('users')->where('parent_consent_status', '<>', 'not_required')->count())->toBe(0);

    vvT29Backfill()->up(); // idempotent
    expect(DB::table('users')->where('parent_consent_status', '<>', 'not_required')->count())->toBe(0);
});

test('(f) backfill chay theo lo 1.000 id: dong o nhieu lo deu duoc cap nhat', function () {
    $low = User::factory()->student()->create();
    $high = User::factory()->student()->create();

    DB::table('users')->where('id', $low->id)->update(['parent_consent_status' => 'pending']);
    // Day id len qua 2 lo de chac chan vong lap chay nhieu lan
    DB::table('users')->where('id', $high->id)->update(['id' => $low->id + 2500, 'parent_consent_status' => 'revoked']);

    vvT29Backfill()->up();

    expect(DB::table('users')->whereIn('id', [$low->id, $low->id + 2500])->pluck('parent_consent_status')->unique()->all())->toBe(['not_required']);
});

test('(f) backfill khong dung vao consents va down() khong lam gi', function () {
    $user = User::factory()->student()->create();
    DB::table('consents')->insert([
        'user_id' => $user->id, 'type' => 'terms', 'policy_version' => '2026-09', 'granted_by' => 'self',
        'channel' => 'web_form', 'granted_at' => now(), 'created_at' => now(),
    ]);
    $before = DB::table('consents')->count();

    vvT29Backfill()->up();
    vvT29Backfill()->down();

    expect(DB::table('consents')->count())->toBe($before);
});

test('migration cot parent_notice_opt_out_at: nullable timestamp, down() go cot, up() tao lai', function () {
    expect(Schema::hasColumn('users', 'parent_notice_opt_out_at'))->toBeTrue();

    $column = collect(Schema::getColumns('users'))->firstWhere('name', 'parent_notice_opt_out_at');
    expect($column['nullable'])->toBeTrue()->and($column['type_name'])->toBe('timestamp');

    $migration = require database_path('migrations/2026_10_20_100000_add_parent_notice_opt_out_at_to_users.php');

    // DDL tu commit ngam trong MySQL: khong bao boc transaction, luon khoi phuc cot trong finally.
    try {
        $migration->down();
        expect(Schema::hasColumn('users', 'parent_notice_opt_out_at'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('users', 'parent_notice_opt_out_at')) {
            $migration->up();
        }
    }

    expect(Schema::hasColumn('users', 'parent_notice_opt_out_at'))->toBeTrue();
});
