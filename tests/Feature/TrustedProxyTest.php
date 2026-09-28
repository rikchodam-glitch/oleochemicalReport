<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

// ============================================================
// TRUSTED PROXY (untuk akses lewat Cloudflare Tunnel)
// ============================================================

test('proxy lokal dipercaya: skema https dan host publik ikut dipakai', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host'  => 'app.saranapendidik.com',
        ])
        ->post('/login', [
            'email'    => 'admin@oleochemical.pro',
            'password' => 'password123',
        ])
        ->assertRedirect('https://app.saranapendidik.com/dashboard');
});

test('proxy tak dikenal diabaikan: header X-Forwarded tidak dipercaya', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host'  => 'app.saranapendidik.com',
        ])
        ->post('/login', [
            'email'    => 'admin@oleochemical.pro',
            'password' => 'password123',
        ])
        // Host yang dipakai tetap host permintaan sebenarnya (APP_URL pengujian),
        // bukan host yang dikirim header X-Forwarded.
        ->assertRedirect('http://oleochemicalreport.test/dashboard');
});
