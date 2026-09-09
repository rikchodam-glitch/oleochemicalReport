<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Jalankan migrasi: tabel subnet/jaringan IP (VLAN + CIDR). Network
     * dihitung dari VLAN mengikuti konvensi 192.168.[vlan].0.
     */
    public function up(): void
    {
        Schema::create('ip_networks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vlan');
            $table->unsignedTinyInteger('cidr');
            $table->string('network', 45);
            $table->string('netmask', 15);
            $table->string('broadcast', 45);
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['vlan', 'cidr']);
            $table->index('network');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_networks');
    }
};
