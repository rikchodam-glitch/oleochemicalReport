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
// SIMPAN PROVIDER TANPA MENGISI MODEL
// ============================================================

test('tambah provider baru tanpa mengisi model -> model terdeteksi otomatis', function () {
    fakeAiModelList(['whisper-large-v3', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b']);

    $this->actingAs($this->admin)->post('/ai-providers', [
        'name'                => 'Groq Uji Otomatis',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => 'kunci-uji',
        'model'               => '',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 5,
        'monthly_token_limit' => 100000,
        'daily_token_limit'   => 10000,
        'status'              => 'healthy',
    ])->assertRedirect(route('ai-providers.index'));

    $provider = AiProvider::where('name', 'Groq Uji Otomatis')->firstOrFail();

    expect($provider->model)->toBe('openai/gpt-oss-120b');
});

test('ubah provider tanpa mengisi model -> model lama tidak terhapus', function () {
    $provider = AiProvider::create([
        'name'                => 'Provider Uji Update',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => 'kunci-uji',
        'model'               => 'openai/gpt-oss-120b',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 7,
        'monthly_token_limit' => 100000,
        'daily_token_limit'   => 10000,
        'status'              => 'healthy',
    ]);

    fakeAiModelList(['openai/gpt-oss-120b']);

    $this->actingAs($this->admin)->put("/ai-providers/{$provider->id}", [
        'name'                => 'Provider Uji Update',
        'provider_type'       => 'groq',
        'api_key_encrypted'   => '',
        'model'               => '',
        'endpoint_url'        => 'https://api.groq.com/openai/v1/chat/completions',
        'priority'            => 7,
        'monthly_token_limit' => 100000,
        'daily_token_limit'   => 10000,
        'status'              => 'healthy',
    ])->assertRedirect(route('ai-providers.index'));

    expect($provider->fresh()->model)->toBe('openai/gpt-oss-120b');
});

// ============================================================
// HALAMAN PANEL AI
// ============================================================

test('GET /ai-providers -> 200 dan menyediakan pilihan nama model', function () {
    $this->actingAs($this->admin)
        ->get('/ai-providers')
        ->assertStatus(200)
        ->assertSee('daftar-model-ai', false)
        ->assertSee('otomatis dideteksi dari API', false);
});
