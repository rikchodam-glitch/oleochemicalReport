<?php

use App\Models\Area;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

test('root redirects to dashboard', function () {
    $response = $this->get('/');

    $response->assertRedirect('/dashboard');
});

test('login page returns successful response', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('admin can access dashboard', function () {
    $user = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
});

test('admin can access reports index (fitur ZPM terintegrasi)', function () {
    $user = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $response = $this->actingAs($user)->get(route('reports.index'));

    $response->assertStatus(200);
});

test('admin can access report show with ZPM classification badge', function () {
    $user = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $technician = Technician::create([
        'name'        => 'Teknisi Test',
        'nik'         => 'TEST001',
        'status'      => 'active',
        'telegram_id' => null,
    ]);
    $area = Area::firstOrFail();

    $report = Report::create([
        'technician_id'        => $technician->id,
        'creator_id'           => $technician->id,
        'report_date'          => now(),
        'report_code'          => 'RPT-' . now()->format('Ymd') . '-TEST',
        'work_description'     => 'Penggantian bearing pompa',
        'work_duration_minutes'=> 60,
        'root_cause'           => 'Keausan normal',
        'area_id'              => $area->id,
        'report_type'          => 'equipment_repair',
        'work_classification'  => 'zpm1',
        'status'               => 'completed',
    ]);

    $this->actingAs($user)->get(route('reports.show', $report))->assertStatus(200)->assertSee('ZPM 1');
});

test('admin can access report edit with ZPM classification dropdown', function () {
    $user = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $technician = Technician::create([
        'name'        => 'Teknisi Test 2',
        'nik'         => 'TEST002',
        'status'      => 'active',
        'telegram_id' => null,
    ]);
    $area = Area::firstOrFail();

    $report = Report::create([
        'technician_id'        => $technician->id,
        'creator_id'           => $technician->id,
        'report_date'          => now(),
        'report_code'          => 'RPT-' . now()->format('Ymd') . '-TEST2',
        'work_description'     => 'Perbaikan pipa bocor',
        'work_duration_minutes'=> 45,
        'root_cause'           => 'Korosi',
        'area_id'              => $area->id,
        'report_type'          => 'area_work',
        'work_classification'  => 'zpm7',
        'status'               => 'needs_review',
    ]);

    $this->actingAs($user)->get(route('reports.show', $report))->assertStatus(200)->assertSee('ZPM 7 - Sipil');
});
