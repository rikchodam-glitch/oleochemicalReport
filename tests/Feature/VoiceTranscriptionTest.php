<?php

use App\Console\Commands\PollTelegramUpdates;
use App\Models\AiProvider;
use App\Models\Technician;
use App\Models\VoiceTranscription;
use App\Services\Telegram\ReportWizardService;
use App\Services\Telegram\SparepartRequestWizardService;
use App\Services\Telegram\VoiceStorageService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

/**
 * Pesan suara Telegram: transkripsi Groq Whisper, konfirmasi, dan pemrosesan
 * sebagai teks biasa. Audio tidak boleh dipakai saat langkah foto.
 */
beforeEach(function () {
    $this->seed();

    Storage::fake('local');

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Suara',
        'nik'         => 'VOC001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '33001',
    ]);

    AiProvider::create([
        'name'              => 'Groq Suara Uji',
        'provider_type'     => 'groq',
        'api_key_encrypted' => 'kunci-uji-voice',
        'model'             => 'openai/gpt-oss-120b',
        'status'            => 'healthy',
        'priority'          => 1,
    ]);

    config([
        'services.telegram.bot_token' => 'token-uji-voice',
        'voice.enabled'                => true,
        'voice.disk'                   => 'local',
        'voice.model'                  => 'whisper-large-v3-turbo',
        'voice.language'               => 'id',
        'voice.rate_limit_per_menit'   => 5,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response([
            'ok'     => true,
            'result' => ['file_path' => 'voice/file_1.oga', 'file_size' => 100],
        ], 200),
        'api.telegram.org/file/bot*' => Http::response('audio-bytes', 200),
        'api.groq.com/openai/v1/audio/transcriptions' => Http::response([
            'text'     => 'Pompa dosing area dua bocor, kode alat BRBT001.',
            'language' => 'id',
            'duration' => 5,
            'segments' => [
                ['no_speech_prob' => 0.05, 'avg_logprob' => -0.2],
            ],
        ], 200),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        'api.groq.com/*'     => Http::response(['choices' => []], 200),
    ]);
});

/**
 * Kirim satu pesan (voice/teks) ke poller seperti update Telegram.
 */
function kirimUpdateVoice(string $chatId, array $pesan): void
{
    $command = app(PollTelegramUpdates::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

    $metode = new ReflectionMethod($command, 'processUpdate');
    $metode->setAccessible(true);

    $metode->invoke($command, [
        'update_id' => random_int(100000, 999999),
        'message'   => array_merge([
            'message_id' => 1,
            'chat'       => ['id' => (int) $chatId],
            'from'       => ['id' => (int) $chatId, 'first_name' => 'Teknisi Uji'],
        ], $pesan),
    ]);
}

/**
 * Kirim callback inline ke poller.
 */
function kirimCallbackVoice(string $chatId, string $data): void
{
    $command = app(PollTelegramUpdates::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

    $metode = new ReflectionMethod($command, 'processCallbackQuery');
    $metode->setAccessible(true);

    $metode->invoke($command, [
        'update_id'      => random_int(100000, 999999),
        'callback_query' => [
            'id'   => 'cb-' . uniqid(),
            'from' => ['id' => (int) $chatId, 'first_name' => 'Teknisi Uji'],
            'message' => [
                'message_id' => 99,
                'chat'       => ['id' => (int) $chatId],
            ],
            'data' => $data,
        ],
    ]);
}

test('voice note Telegram .oga disimpan sebagai .ogg agar diterima Groq', function () {
    $storage = app(VoiceStorageService::class);
    $hasil   = $storage->store('voice-1', '33001');

    expect($hasil)->not->toBeNull()
        ->and($hasil['path'])->toEndWith('.ogg');

    $storage->delete($hasil['path']);
});

test('voice note ditranskripsi dan menunggu persetujuan', function () {
    kirimUpdateVoice('33001', [
        'voice' => ['file_id' => 'voice-1', 'duration' => 5, 'mime_type' => 'audio/ogg', 'file_size' => 100],
    ]);

    expect(Cache::has('voice_pending:33001'))->toBeTrue()
        ->and(VoiceTranscription::query()->where('status', 'sukses')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/audio/transcriptions'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) ($request['text'] ?? ''), 'Pompa dosing')
        && (($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === 'suara:pakai'));

    expect(Storage::disk('local')->allFiles('telegram-voices'))->toBe([]);
});

test('teks GUNAKAN meneruskan transkrip seperti teks biasa', function () {
    kirimUpdateVoice('33001', [
        'voice' => ['file_id' => 'voice-2', 'duration' => 5],
    ]);

    kirimUpdateVoice('33001', ['text' => 'GUNAKAN']);

    expect(Cache::has('voice_pending:33001'))->toBeFalse()
        ->and(app(ReportWizardService::class)->hasActiveWizard('33001'))->toBeTrue();
});

test('callback Gunakan juga meneruskan transkrip', function () {
    kirimUpdateVoice('33001', [
        'voice' => ['file_id' => 'voice-3', 'duration' => 5],
    ]);

    kirimCallbackVoice('33001', 'suara:pakai');

    expect(Cache::has('voice_pending:33001'))->toBeFalse()
        ->and(app(ReportWizardService::class)->hasActiveWizard('33001'))->toBeTrue();
});

test('voice note ditolak saat wizard request menunggu foto', function () {
    $wizard = app(SparepartRequestWizardService::class);
    $wizard->mulai('33001', $this->teknisi, 'Seal bocor');
    $wizard->handleText('33001', '25 mm');
    $wizard->handleText('33001', '2');
    $wizard->handleText('33001', 'pcs');

    expect($wizard->menungguFoto('33001'))->toBeTrue();

    kirimUpdateVoice('33001', [
        'voice' => ['file_id' => 'voice-4', 'duration' => 5],
    ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/getFile'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) ($request['text'] ?? ''), 'membutuhkan foto'));
});

test('voice note gagal ditangani tanpa menyimpan transkrip', function () {
    Http::swap(new \Illuminate\Http\Client\Factory());

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/audio/transcriptions')) {
            return Http::response(['error' => ['message' => 'audio tidak valid']], 400);
        }

        if (str_contains($request->url(), '/getFile')) {
            return Http::response([
                'ok'     => true,
                'result' => ['file_path' => 'voice/file_1.oga', 'file_size' => 100],
            ], 200);
        }

        if (str_contains($request->url(), '/file/bot')) {
            return Http::response('audio-bytes', 200);
        }

        return Http::response(['ok' => true, 'result' => []], 200);
    });

    kirimUpdateVoice('33001', [
        'voice' => ['file_id' => 'voice-5', 'duration' => 5],
    ]);

    expect(Cache::has('voice_pending:33001'))->toBeFalse()
        ->and(VoiceTranscription::query()->where('status', 'gagal')->exists())->toBeTrue();
});

test('webhook dapat memproses voice note dan balasan GUNAKAN', function () {
    $this->postJson('/telegram/webhook', [
        'update_id' => 1,
        'message'   => [
            'message_id' => 1,
            'chat'       => ['id' => 33001],
            'from'       => ['id' => 33001, 'first_name' => 'Teknisi Uji'],
            'voice'      => ['file_id' => 'voice-6', 'duration' => 5],
        ],
    ])->assertOk();

    expect(Cache::has('voice_pending:33001'))->toBeTrue();

    $this->postJson('/telegram/webhook', [
        'update_id' => 2,
        'message'   => [
            'message_id' => 2,
            'chat'       => ['id' => 33001],
            'from'       => ['id' => 33001, 'first_name' => 'Teknisi Uji'],
            'text'       => 'GUNAKAN',
        ],
    ])->assertOk();

    expect(Cache::has('voice_pending:33001'))->toBeFalse()
        ->and(app(ReportWizardService::class)->hasActiveWizard('33001'))->toBeTrue();
});
