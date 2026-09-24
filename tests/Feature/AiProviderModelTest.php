<?php

use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiModelDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

/**
 * Palsukan respons endpoint GET /models milik provider AI.
 *
 * @param  array<int, string>  $ids     Daftar nama model yang dianggap tersedia
 * @param  int                 $status  Kode HTTP balasan
 * @return void
 */
function fakeAiModelList(array $ids, int $status = 200): void
{
    Http::fake([
        '*/models' => Http::response([
            'data' => array_map(fn ($id) => ['id' => $id], $ids),
        ], $status),
    ]);
}

/**
 * Palsukan respons chat/completions beserta header kuota dari API.
 *
 * Dipakai karena menyimpan provider kini sekaligus melakukan ping ringan untuk
 * mengisi kuota dari header x-ratelimit.
 *
 * @param  array<string, string>  $headers  Header tambahan (mis. kuota)
 * @return void
 */
function fakeAiChat(array $headers = []): void
{
    Http::fake([
        '*/chat/completions' => Http::response(
            [
                'choices' => [['message' => ['content' => '{"report_type":"general"}']]],
                'usage'   => ['total_tokens' => 12],
            ],
            200,
            $headers
        ),
    ]);
}

/**
 * Palsukan kedua endpoint sekaligus: GET /models dan POST /chat/completions.
 *
 * @param  array<int, string>     $ids      Daftar model yang tersedia di akun
 * @param  array<string, string>  $headers  Header kuota pada respons chat
 * @return void
 */
function fakeAiApi(array $ids, array $headers = []): void
{
    Http::fake([
        '*/models'           => Http::response([
            'data' => array_map(fn ($id) => ['id' => $id], $ids),
        ], 200),
        '*/chat/completions' => Http::response(
            [
                'choices' => [['message' => ['content' => '{"report_type":"general"}']]],
                'usage'   => ['total_tokens' => 12],
            ],
            200,
            $headers
        ),
    ]);
}

// ============================================================
// LAYANAN DETEKSI MODEL
// ============================================================

test('layanan deteksi model memilih model chat dan menolak model non-chat', function () {
    $svc = app(AiModelDiscoveryService::class);

    // Model prioritas tertinggi dipilih walau bukan yang pertama di daftar.
    expect($svc->pickChatModel(['whisper-large-v3', 'openai/gpt-oss-120b']))
        ->toBe('openai/gpt-oss-120b');

    // Hanya model audio/penjaga prompt -> tidak ada yang layak dipakai chat.
    expect($svc->pickChatModel(['whisper-large-v3', 'llama-prompt-guard-2-86m']))
        ->toBeNull();

    // Base URL dihitung dari endpoint chat/completions.
    expect($svc->resolveBaseUrl('https://api.groq.com/openai/v1/chat/completions'))
        ->toBe('https://api.groq.com/openai/v1');
});

test('deteksi model gagal memberikan pesan error tanpa menyentuh kolom model', function () {
    $provider = AiProvider::create([
        'name'                => 'Provider Uji Gagal',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => 'kunci-uji',
        'model'               => 'groq/compound',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 9,
        'monthly_token_limit' => 1000,
        'daily_token_limit'   => 100,
        'status'              => 'healthy',
    ]);

    fakeAiModelList([], 401);

    $this->actingAs($this->admin)
        ->post("/ai-providers/{$provider->id}/refresh-models")
        ->assertRedirect(route('ai-providers.index'))
        ->assertSessionHas('error');

    expect($provider->fresh()->model)->toBe('groq/compound');
});

// ============================================================
// SIMPAN PROVIDER: MODEL & KUOTA OTOMATIS DARI API
// ============================================================

test('tambah provider tanpa field model -> model dan kuota terisi otomatis', function () {
    fakeAiApi(
        ['whisper-large-v3', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b'],
        ['x-ratelimit-limit-tokens' => '8000', 'x-ratelimit-remaining-tokens' => '7927']
    );

    $this->actingAs($this->admin)->post('/ai-providers', [
        'name'                => 'Groq Uji Otomatis',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => 'kunci-uji',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 5,
        'monthly_token_limit' => 100000,
        'daily_token_limit'   => 10000,
        'status'              => 'healthy',
    ])->assertRedirect(route('ai-providers.index'));

    $provider = AiProvider::where('name', 'Groq Uji Otomatis')->firstOrFail();

    expect($provider->model)->toBe('openai/gpt-oss-120b')
        ->and($provider->quota_remaining_tokens_per_minute)->toBe(7927)
        ->and($provider->quota_limit_tokens_per_minute)->toBe(8000)
        ->and($provider->quota_checked_at)->not->toBeNull()
        ->and($provider->status)->toBe('healthy');
});

test('ubah provider -> model disinkronkan ulang dari daftar model akun', function () {
    $provider = AiProvider::create([
        'name'                => 'Provider Uji Update',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => 'kunci-uji',
        'model'               => 'groq/compound',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 7,
        'monthly_token_limit' => 100000,
        'daily_token_limit'   => 10000,
        'status'              => 'healthy',
    ]);

    fakeAiApi(['openai/gpt-oss-20b', 'openai/gpt-oss-120b']);

    $this->actingAs($this->admin)->put("/ai-providers/{$provider->id}", [
        'name'                => 'Provider Uji Update',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => '',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 7,
        'monthly_token_limit' => 100000,
        'daily_token_limit'   => 10000,
        'status'              => 'healthy',
    ])->assertRedirect(route('ai-providers.index'));

    // Nama model lama yang tidak valid diganti model yang benar-benar ada di akun.
    expect($provider->fresh()->model)->toBe('openai/gpt-oss-120b');
});

// ============================================================
// HALAMAN PANEL AI
// ============================================================

test('GET /ai-providers -> 200 tanpa field model (model otomatis dari API)', function () {
    fakeAiApi(['openai/gpt-oss-120b']);

    // Provider dengan data kuota dari API agar bagian kuota ikut dirender.
    AiProvider::create([
        'name'                                => 'Provider Tampil Kuota',
        'provider_type'                       => 'groq',
        'api_key_encrypted'                   => 'kunci-uji',
        'model'                               => 'openai/gpt-oss-120b',
        'priority'                            => 2,
        'monthly_token_limit'                 => 100000,
        'daily_token_limit'                   => 10000,
        'status'                              => 'healthy',
        'quota_limit_tokens_per_minute'       => 8000,
        'quota_remaining_tokens_per_minute'   => 7927,
        'quota_limit_requests_per_minute'     => 1000,
        'quota_remaining_requests_per_minute' => 999,
        'quota_checked_at'                    => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/ai-providers')
        ->assertStatus(200)
        // Field isian model sudah tidak ada lagi di form.
        ->assertDontSee('name="model"', false)
        // Diganti keterangan bahwa model & kuota diambil otomatis dari API.
        ->assertSee('otomatis dari API', false)
        ->assertSee('Kuota per menit (menurut API)', false)
        ->assertSee('7,927', false);
});
