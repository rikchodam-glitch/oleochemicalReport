<?php

use App\Jobs\KirimBroadcastKolaborasi;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\ReportCollaborationNotifier;
use App\Services\ReportCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ============================================================
// BROADCAST KOLABORASI: DETAIL PEKERJAAN KE SEMUA REKAN
// ============================================================

/**
 * Buat teknisi uji dengan Telegram ID.
 */
function teknisiBroadcast(string $nama, string $nik, string $telegram): Technician
{
    return Technician::create([
        'name'        => $nama,
        'nik'         => $nik,
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => $telegram,
    ]);
}

beforeEach(function () {
    $this->seed();

    $this->pelaksana = teknisiBroadcast('Pelaksana Uji', 'BC001', '900001');
    $this->rekanA    = teknisiBroadcast('Rekan A', 'BC002', '900002');
    $this->rekanB    = teknisiBroadcast('Rekan B', 'BC003', '900003');
    $this->rekanC    = teknisiBroadcast('Rekan C', 'BC004', '900004');

    $this->laporan = Report::create([
        'technician_id'         => $this->pelaksana->id,
        'creator_id'            => $this->pelaksana->id,
        'report_date'           => '2026-10-20',
        'report_code'           => 'RPT-BROADCAST-01',
        'work_description'      => 'Perbaikan pompa transfer utama',
        'work_classification'   => 'CM',
        'jenis_pekerjaan'       => 'CM',
        'work_duration_minutes' => 90,
        'root_cause'            => 'Bearing aus',
        'finding'               => 'Getaran tinggi',
        'status'                => 'completed',
        'report_type'           => 'equipment_repair',
    ]);

    $salinan = app(ReportCopyService::class);

    $salinan->buatSalinanKolaborasi($this->laporan, $this->rekanA);
    $salinan->buatSalinanKolaborasi($this->laporan, $this->rekanB);
    $salinan->buatSalinanKolaborasi($this->laporan, $this->rekanC);
});

test('semua kolaborator menerima broadcast detail pekerjaan dan nama rekan lain', function () {
    config(['services.telegram.bot_token' => '123:broadcast-uji']);
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $hasil = app(ReportCollaborationNotifier::class)->kirim($this->laporan->refresh());

    expect($hasil)->toHaveCount(3);
    Http::assertSentCount(3);

    foreach ([$this->rekanA, $this->rekanB, $this->rekanC] as $rekan) {
        Http::assertSent(function ($request) use ($rekan) {
            $teks = (string) $request['text'];

            return (string) $request['chat_id'] === (string) $rekan->telegram_id
                && str_contains($teks, 'BROADCAST KOLABORASI')
                && str_contains($teks, 'Pelaksana Uji')
                && str_contains($teks, 'Rekan A')
                && str_contains($teks, 'Rekan B')
                && str_contains($teks, 'Rekan C')
                && str_contains($teks, 'Perbaikan pompa transfer utama')
                && str_contains($teks, 'Bearing aus')
                && str_contains($teks, 'RPT-BROADCAST-01')
                && str_contains($teks, '/reports/' . $this->laporan->id);
        });
    }

    // Pelaksana tidak dikirimi broadcast kolaborasi.
    Http::assertNotSent(fn ($request) => (string) $request['chat_id'] === (string) $this->pelaksana->telegram_id);
});

test('kolaborator tanpa telegram_id dilewati', function () {
    $tanpaTelegram = teknisiBroadcast('Rekan Tanpa Telegram', 'BC005', '');
    app(ReportCopyService::class)->buatSalinanKolaborasi($this->laporan, $tanpaTelegram);

    config(['services.telegram.bot_token' => '123:broadcast-uji']);
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $hasil = app(ReportCollaborationNotifier::class)->kirim($this->laporan->refresh());

    expect($hasil)->toHaveCount(3);
    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request) => (string) $request['chat_id'] === (string) $tanpaTelegram->telegram_id);
});

test('menambah kolaborator lewat web mengirim job broadcast untuk teknisi baru', function () {
    Queue::fake();

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $baru  = teknisiBroadcast('Rekan Baru', 'BC006', '900006');

    $this->actingAs($admin)
        ->post(route('reports.collaborators.store', $this->laporan), [
            'technician_id' => $baru->id,
        ])
        ->assertRedirect();

    Queue::assertPushed(KirimBroadcastKolaborasi::class, function (KirimBroadcastKolaborasi $job) use ($baru) {
        return $job->reportId === $this->laporan->id
            && $job->penerimaIds === [$baru->id];
    });
});
