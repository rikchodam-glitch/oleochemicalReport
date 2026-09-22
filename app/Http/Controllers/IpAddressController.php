<?php

namespace App\Http\Controllers;

use App\Models\IpAddress;
use App\Models\IpNetwork;
use App\Models\ItAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IpAddressController extends Controller
{
    /**
     * Daftar subnet/jaringan IP beserta jumlah IP per status.
     *
     * @return View
     */
    public function index(): View
    {
        $networks = IpNetwork::query()
            ->withCount([
                'addresses as assigned_count' => fn ($query) => $query->where('status', IpAddress::STATUS_ASSIGNED),
                'addresses as available_count' => fn ($query) => $query->where('status', IpAddress::STATUS_AVAILABLE),
                'addresses as reserved_count' => fn ($query) => $query->where('status', IpAddress::STATUS_RESERVED),
            ])
            ->orderBy('vlan')
            ->get();

        return view('it-assets.ip-addresses.index', [
            'networks' => $networks,
        ]);
    }

    /**
     * Pencarian perangkat untuk popup Daftar IP (autocomplete). Dipakai saat
     * menambahkan atau mengganti perangkat yang memakai sebuah IP.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function assetSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $assets = ItAsset::query()
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(function ($inner) use ($term): void {
                    $inner->where('tag_name', 'like', '%' . $term . '%')
                        ->orWhere('name', 'like', '%' . $term . '%')
                        ->orWhere('ip_address', 'like', '%' . $term . '%');
                });
            })
            ->orderBy('tag_name')
            ->limit(20)
            ->get();

        return response()->json([
            'assets' => $assets->map(static fn (ItAsset $asset): array => [
                'id'          => $asset->id,
                'tag_name'    => $asset->displayName(),
                'name'        => $asset->name,
                'device_type' => $asset->deviceLabel(),
                'ip_address'  => $asset->ip_address,
                'plant'       => $asset->plant,
                'location'    => $asset->location,
            ])->all(),
        ]);
    }

    /**
     * Buat subnet baru dari VLAN + CIDR lalu generate seluruh host usable
     * otomatis (kecuali network address dan broadcast, sesuai standar Netbox).
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'vlan'        => ['required', 'integer', 'min:' . IpNetwork::VLAN_MIN, 'max:' . IpNetwork::VLAN_MAX],
            'cidr'        => ['required', 'integer', 'min:' . IpNetwork::CIDR_MIN, 'max:' . IpNetwork::CIDR_MAX],
            'name'        => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $exists = IpNetwork::query()
            ->where('vlan', $validated['vlan'])
            ->where('cidr', $validated['cidr'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'vlan' => 'Subnet untuk VLAN ' . $validated['vlan'] . ' /' . $validated['cidr'] . ' sudah ada.',
            ]);
        }

        $subnet = $this->computeSubnet((int) $validated['vlan'], (int) $validated['cidr']);

        if ($subnet['hostCount'] > IpNetwork::MAX_HOSTS) {
            throw ValidationException::withMessages([
                'cidr' => 'Subnet terlalu besar (' . number_format($subnet['hostCount'], 0, ',', '.')
                    . ' host). Gunakan /24 atau lebih kecil.',
            ]);
        }

        $network = IpNetwork::create([
            'vlan'        => $validated['vlan'],
            'cidr'        => $validated['cidr'],
            'network'     => $subnet['network'],
            'netmask'     => $subnet['netmask'],
            'broadcast'   => $subnet['broadcast'],
            'name'        => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        $this->generateHosts($network->id, $subnet['first'], $subnet['last']);

        return redirect()
            ->route('it-assets.ip-addresses.index')
            ->with('success', 'Subnet ' . $network->prefix() . ' berhasil dibuat ('
                . number_format($subnet['hostCount'], 0, ',', '.') . ' host usable).');
    }

    /**
     * Hapus subnet beserta semua IP-nya. Ditolak bila masih ada IP yang
     * sedang dipakai perangkat.
     *
     * @param  IpNetwork  $network
     * @return RedirectResponse
     */
    public function destroy(IpNetwork $network): RedirectResponse
    {
        if ($network->addresses()->where('status', IpAddress::STATUS_ASSIGNED)->exists()) {
            return redirect()
                ->route('it-assets.ip-addresses.index')
                ->with('error', 'Subnet ' . $network->prefix() . ' masih memiliki IP yang dipakai perangkat.');
        }

        $network->delete();

        return redirect()
            ->route('it-assets.ip-addresses.index')
            ->with('success', 'Subnet ' . $network->prefix() . ' beserta IP-nya dihapus.');
    }

    /**
     * Data JSON daftar IP milik satu subnet (dipakai panel Lihat IP).
     *
     * @param  IpNetwork  $network
     * @return JsonResponse
     */
    public function show(IpNetwork $network): JsonResponse
    {
        $ips = $network->addresses()
            ->with('asset')
            ->orderByRaw('INET_ATON(address)')
            ->get()
            ->map(function (IpAddress $ip) {
                $asset = $ip->asset;

                return [
                    'id'      => $ip->id,
                    'address' => $ip->address,
                    'status'  => $ip->status,
                    'asset'   => $asset ? [
                        'tag_name'          => $asset->displayName(),
                        'name'              => $asset->name,
                        'device_type'       => $asset->device_type,
                        'device_type_label' => $asset->deviceLabel(),
                        'plant'             => $asset->plant,
                        'department'        => $asset->department,
                        'location'          => $asset->location,
                        'ip_address'        => $asset->ip_address,
                        'last_ping_status'  => $asset->last_ping_status,
                        'last_ping_latency' => $asset->last_ping_latency,
                        'url'               => route('it-assets.show', $asset),
                    ] : null,
                ];
            });

        return response()->json([
            'network' => $network->label(),
            'ips'     => $ips,
        ]);
    }

    /**
     * Tetapkan, ganti, atau lepaskan perangkat yang memakai sebuah IP.
     *
     * Aturan yang dipakai sama dengan sisi perangkat (lihat
     * ItAssetController::syncIpAssignment): satu perangkat hanya memakai satu
     * IP, sehingga IP lain milik perangkat tersebut otomatis dilepas.
     * it_asset_id null berarti IP dikembalikan ke status tersedia.
     *
     * @param  Request    $request
     * @param  IpAddress  $ipAddress
     * @return JsonResponse
     */
    public function assign(Request $request, IpAddress $ipAddress): JsonResponse
    {
        // Validasi manual supaya balasannya selalu JSON (endpoint ini dipanggil
        // lewat fetch dari popup, sehingga balasan redirect tidak berguna).
        $validator = Validator::make($request->all(), [
            'it_asset_id' => ['nullable', 'integer', 'exists:it_assets,id'],
        ], [
            'it_asset_id.exists'  => 'Perangkat yang dipilih tidak ditemukan.',
            'it_asset_id.integer' => 'Perangkat yang dipilih tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => (string) $validator->errors()->first('it_asset_id'),
            ], 422);
        }

        $validated = $validator->validated();

        // Kunci boleh tidak dikirim sama sekali (artinya melepas IP), sehingga
        // dibaca dengan aman tanpa asumsi kunci selalu ada.
        $assetId = $validated['it_asset_id'] ?? null;

        $asset = $assetId !== null
            ? ItAsset::query()->find((int) $assetId)
            : null;

        if ($assetId !== null && $asset === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Perangkat tidak ditemukan.',
            ], 422);
        }

        $previousAsset = $ipAddress->asset;
        $address = (string) $ipAddress->address;

        DB::transaction(function () use ($ipAddress, $asset, $previousAsset, $address): void {
            // Perangkat lama dilepas dari IP ini (saat diganti atau dikosongkan).
            if ($previousAsset !== null && ($asset === null || $previousAsset->id !== $asset->id)) {
                if ($previousAsset->ip_address === $address) {
                    $previousAsset->update(['ip_address' => null]);
                }
            }

            if ($asset === null) {
                $ipAddress->update([
                    'it_asset_id' => null,
                    'status'      => IpAddress::STATUS_AVAILABLE,
                ]);

                return;
            }

            // Satu perangkat satu IP: baris lain milik perangkat ini dilepas.
            IpAddress::query()
                ->where('it_asset_id', $asset->id)
                ->whereKeyNot($ipAddress->getKey())
                ->update([
                    'it_asset_id' => null,
                    'status'      => IpAddress::STATUS_AVAILABLE,
                ]);

            $ipAddress->update([
                'it_asset_id' => $asset->id,
                'status'      => IpAddress::STATUS_ASSIGNED,
            ]);

            $asset->update(['ip_address' => $address]);
        });

        return response()->json([
            'ok'      => true,
            'status'  => $asset === null ? IpAddress::STATUS_AVAILABLE : IpAddress::STATUS_ASSIGNED,
            'message' => $asset === null
                ? 'IP ' . $address . ' dikembalikan ke status tersedia.'
                : 'IP ' . $address . ' sekarang dipakai ' . $asset->displayName() . '.',
        ]);
    }

    /**
     * Hitung network, netmask, broadcast, dan rentang host usable dari VLAN
     * (konvensi 192.168.[vlan].0) + CIDR, memakai operasi bitwise IPv4.
     *
     * @param  int  $vlan
     * @param  int  $cidr
     * @return array{network: string, netmask: string, broadcast: string, first: int, last: int, hostCount: int}
     */
    private function computeSubnet(int $vlan, int $cidr): array
    {
        $base = $this->ipv4ToLong('192.168.' . $vlan . '.0');
        $mask = $cidr === 0 ? 0 : (0xFFFFFFFF << (32 - $cidr)) & 0xFFFFFFFF;
        $network = $base & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        $first = $network + 1;
        $last = $broadcast - 1;

        return [
            'network'   => long2ip($network),
            'netmask'   => long2ip($mask),
            'broadcast' => long2ip($broadcast),
            'first'     => $first,
            'last'      => $last,
            'hostCount' => $last - $first + 1,
        ];
    }

    /**
     * Konversi string IPv4 ke integer 32-bit.
     *
     * @param  string  $ip
     * @return int
     */
    private function ipv4ToLong(string $ip): int
    {
        return unpack('N', inet_pton($ip))[1];
    }

    /**
     * Generate seluruh alamat IP antara first..last (integer) sebagai baris
     * status available, disisipkan secara chunk agar tidak membebani query.
     * Baris dengan alamat yang sudah ada dilewati oleh insertOrIgnore.
     *
     * @param  int  $networkId
     * @param  int  $first
     * @param  int  $last
     * @return void
     */
    private function generateHosts(int $networkId, int $first, int $last): void
    {
        DB::transaction(function () use ($networkId, $first, $last): void {
            $rows = [];
            for ($ip = $first; $ip <= $last; $ip++) {
                $rows[] = [
                    'ip_network_id' => $networkId,
                    'address'       => long2ip($ip),
                    'status'        => IpAddress::STATUS_AVAILABLE,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];

                if (count($rows) === 500) {
                    IpAddress::query()->insertOrIgnore($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                IpAddress::query()->insertOrIgnore($rows);
            }
        });
    }
}