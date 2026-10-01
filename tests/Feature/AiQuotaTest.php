<?php

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Services\AiQuotaService;
use App\Services\Traits\AiProviderCallerTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Pembungkus untuk menguji method protected pada AiProviderCallerTrait.
 */
class PengujiPemanggilAi
{
    use AiProviderCallerTrait;

    /**
     * Membuka getBestProvider (protected) untuk pengujian.
     *
     * @return AiProvider|null
     */
    public function pilihProvider(): ?AiProvider
    {
        return $this->getBestProvider();
    }

    /**
     * Membuka callGroq (protected) untuk pengujian.
     *
     * @param  AiProvider  $provider
     * @param  string      $prompt
     * @return string|null
     */
    public function panggil(AiProvider $provider, string $prompt): ?string
    {
        return $this->callGroq($provider, $prompt);
    }
}

beforeEach(function () {
    $this->quota = app(AiQuotaService::class);

    $this->provider = AiProvider::create([
        'name'                => 'Provider Kuota Uji',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => 'kunci-uji',
        'model'               => 'openai/gpt-oss-120b',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 1,
        'monthly_token_limit' => 3000000,
        'daily_token_limit'   => 100000,
        'status'              => 'healthy',
    ]);
});

// ============================================================
// PEMBACAAN HEADER KUOTA DARI API
// ============================================================

test('header x-ratelimit dari API disimpan apa adanya ke provider', function () {
    $this->quota->recordFromHeaders($this->provider, [
        'x-ratelimit-limit-tokens'       => ['8000'],
        'x-ratelimit-remaining-tokens'   => ['7927'],
        'x-ratelimit-limit-requests'     => ['1000'],
        'x-ratelimit-remaining-requests' => ['999'],
        'X-RateLimit-Reset-Tokens'       => ['547ms'],
    ]);

    $provider = $this->provider->fresh();

    expect($provider->quota_limit_tokens_per_minute)->toBe(8000)
        ->and($provider->quota_remaining_tokens_per_minute)->toBe(7927)
        ->and($provider->quota_limit_requests_per_minute)->toBe(1000)
        ->and($provider->quota_remaining_requests_per_minute)->toBe(999)
        ->and($provider->quota_checked_at)->not->toBeNull()
        ->and($provider->cooldown_until)->toBeNull();
});

test('panggilan sukses memulihkan status exhausted dan menghapus masa tunggu', function () {
    $this->provider->update([
        'status'         => 'exhausted',
        'cooldown_until' => now()->addMinutes(5),
    ]);

    $this->quota->recordFromHeaders($this->provider, ['x-ratelimit-remaining-tokens' => '1000']);

    $provider = $this->provider->fresh();

    expect($provider->status)->toBe('healthy')
        ->and($provider->cooldown_until)->toBeNull();
});

// ============================================================
// PENANGANAN 429 (BATAS API)
// ============================================================

test('HTTP 429 memakai waktu tunggu dari header retry-after', function () {
    $detik = $this->quota->applyRateLimit($this->provider, [
        'retry-after'                  => '45',
        'x-ratelimit-remaining-tokens' => '0',
    ]);

    $provider = $this->provider->fresh();

    expect($detik)->toBe(45)
        ->and($provider->status)->toBe('exhausted')
        ->and($this->quota->isCoolingDown($provider))->toBeTrue()
        ->and($this->quota->cooldownRemainingSeconds($provider))->toBeGreaterThan(40)
        ->and($provider->quota_remaining_tokens_per_minute)->toBe(0);
});

test('waktu tunggu bisa dibaca dari format reset token milik API', function () {
    $detik = $this->quota->applyRateLimit($this->provider, ['x-ratelimit-reset-tokens' => '1m26.4s']);

    expect($detik)->toBe(87);
});

test('tanpa header waktu reset dipakai waktu tunggu bawaan', function () {
    $detik = $this->quota->applyRateLimit($this->provider, []);

    expect($detik)->toBe(AiQuotaService::DEFAULT_COOLDOWN_SECONDS);
});

test('berbagai format durasi header dibaca dengan benar', function () {
    expect($this->quota->parseDurationSeconds('547ms'))->toBe(1)
        ->and($this->quota->parseDurationSeconds('1.5s'))->toBe(2)
        ->and($this->quota->parseDurationSeconds('45s'))->toBe(45)
        ->and($this->quota->parseDurationSeconds('2h30m'))->toBe(9000)
        ->and($this->quota->parseDurationSeconds('30'))->toBe(30)
        ->and($this->quota->parseDurationSeconds(''))->toBeNull()
        ->and($this->quota->parseDurationSeconds('tidak diketahui'))->toBeNull();
});

// ============================================================
// RESET OTOMATIS PENGHITUNG HARIAN / BULANAN
// ============================================================

test('penghitung harian direset otomatis saat hari berganti', function () {
    // Jam dipatok ke pertengahan bulan supaya "kemarin" pasti masih berada di
    // bulan yang sama. Tanpa ini, test gagal setiap tanggal 1 karena kemarin
    // sudah masuk bulan sebelumnya sehingga penghitung bulanan ikut direset
    // (perilaku aplikasi memang benar, ekspektasi test-nya yang tidak stabil).
    $this->travelTo(now()->startOfMonth()->addDays(14));

    $this->provider->update([
        'tokens_used_today' => 114096,
        'tokens_used_month' => 282264,
        'request_count_24h' => 120,
        'quota_day'         => now()->subDay()->toDateString(),
        'status'            => 'exhausted',
    ]);

    expect($this->quota->resetCounterIfNewPeriod($this->provider))->toBeTrue();

    $provider = $this->provider->fresh();

    expect($provider->tokens_used_today)->toBe(0)
        ->and($provider->request_count_24h)->toBe(0)
        ->and($provider->status)->toBe('healthy')
        ->and($provider->quota_day->toDateString())->toBe(now()->toDateString())
        // Bulan sama -> penghitung bulanan TIDAK direset.
        ->and($provider->tokens_used_month)->toBe(282264);
});

test('penghitung bulanan ikut direset saat bulan berganti', function () {
    $this->provider->update([
        'tokens_used_month' => 100000,
        'quota_day'         => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
    ]);

    $this->quota->resetCounterIfNewPeriod($this->provider);

    expect($this->provider->fresh()->tokens_used_month)->toBe(0);
});

test('reset tidak dijalankan dua kali pada hari yang sama', function () {
    $this->provider->update(['quota_day' => now()->toDateString(), 'tokens_used_today' => 500]);

    expect($this->quota->resetCounterIfNewPeriod($this->provider))->toBeFalse()
        ->and($this->provider->fresh()->tokens_used_today)->toBe(500);
});

// ============================================================
// PERILAKU PEMILIHAN PROVIDER (TIDAK LAGI MATI DIAM-DIAM)
// ============================================================

test('kuota lokal yang terlampaui tidak mematikan AI bila penjaga lokal dimatikan', function () {
    config()->set('ai.quota.enforce_local_limit', false);

    $this->provider->update([
        'tokens_used_today' => 114096,
        'daily_token_limit' => 100000,
        'quota_day'         => now()->toDateString(),
    ]);

    expect((new PengujiPemanggilAi)->pilihProvider())->not->toBeNull();
});

test('provider yang sedang menunggu setelah 429 dilewati sampai masa tunggu habis', function () {
    $this->provider->update([
        'status'         => 'exhausted',
        'cooldown_until' => now()->addMinutes(10),
    ]);

    expect((new PengujiPemanggilAi)->pilihProvider())->toBeNull();

    $this->provider->update(['cooldown_until' => now()->subMinute(), 'status' => 'healthy']);

    expect((new PengujiPemanggilAi)->pilihProvider()?->id)->toBe($this->provider->id);
});

// ============================================================
// JALUR NYATA: callGroq MENCATAT KUOTA DAN MENGHORMATI 429
// ============================================================

test('callGroq menyimpan header kuota saat respons sukses', function () {
    Http::fake([
        '*/chat/completions' => Http::response(
            [
                'choices' => [['message' => ['content' => '{"report_type":"general"}']]],
                'usage'   => ['total_tokens' => 73],
            ],
            200,
            [
                'x-ratelimit-limit-tokens'     => '8000',
                'x-ratelimit-remaining-tokens' => '7927',
            ]
        ),
    ]);

    $hasil    = (new PengujiPemanggilAi)->panggil($this->provider, 'ping');
    $provider = $this->provider->fresh();

    expect($hasil)->toBe('{"report_type":"general"}')
        ->and($provider->quota_limit_tokens_per_minute)->toBe(8000)
        ->and($provider->quota_remaining_tokens_per_minute)->toBe(7927)
        ->and($provider->tokens_used_today)->toBe(73);
});

test('callGroq menandai masa tunggu saat provider membalas 429', function () {
    Http::fake([
        '*/chat/completions' => Http::response(
            ['error' => ['message' => 'Rate limit reached']],
            429,
            ['retry-after' => '30']
        ),
    ]);

    expect((new PengujiPemanggilAi)->panggil($this->provider, 'ping'))->toBeNull();

    $provider = $this->provider->fresh();

    expect($provider->status)->toBe('exhausted')
        ->and($provider->cooldown_until)->not->toBeNull()
        ->and($provider->cooldown_until->isFuture())->toBeTrue()
        ->and(AiUsageLog::where('provider_id', $provider->id)->where('status', 'error')->exists())->toBeTrue();
});

