<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Geser seluruh kolom DATETIME/TIMESTAMP yang selama ini disimpan dalam UTC
 * menjadi WIB (UTC+7).
 *
 * Latar belakang: sebelum migrasi ini config('app.timezone') = UTC, sehingga
 * created_at/updated_at dan kolom datetime lain yang dihasilkan now() tersimpan
 * sebagai jam UTC (mundur 7 jam dari WIB). Setelah timezone aplikasi diubah ke
 * Asia/Jakarta, seluruh data lama perlu digeser +7 jam agar konsisten dengan
 * penulisan data baru yang sudah WIB.
 *
 * Catatan: down() mengurangi 7 jam dan HANYA aman dijalankan bila belum ada
 * data baru yang ditulis setelah timezone WIB aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shiftHours(7);
    }

    public function down(): void
    {
        $this->shiftHours(-7);
    }

    /**
     * Geser semua kolom bertipe datetime/timestamp pada database aktif sebesar
     * interval jam tertentu, khusus baris yang nilainya tidak null.
     *
     * @param  int  $hours  Boleh negatif untuk arah sebaliknya.
     */
    private function shiftHours(int $hours): void
    {
        // Perintah ini khusus MySQL (information_schema + DATE_ADD). Pada driver
        // lain (SQLite saat test) tidak ada data legacy yang perlu digeser.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $columns = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ?
                AND DATA_TYPE IN ("datetime", "timestamp")
              ORDER BY TABLE_NAME, COLUMN_NAME',
            [DB::getDatabaseName()]
        );

        DB::transaction(function () use ($columns, $hours) {
            foreach ($columns as $column) {
                $table = $this->ident($column->t);
                $field = $this->ident($column->c);
                $sign  = $hours >= 0 ? '+' : '-';
                $abs   = abs($hours);

                DB::statement(
                    "UPDATE {$table} SET {$field} = DATE_ADD({$field}, INTERVAL {$sign}{$abs} HOUR) WHERE {$field} IS NOT NULL"
                );
            }
        });
    }

    /**
     * Bungkus nama tabel/kolom dengan backtick sebagai pengaman identifier.
     *
     * @param  string  $name
     * @return string
     */
    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
};
