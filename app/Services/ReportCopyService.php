<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;

/**
 * ReportCopyService
 *
 * Membuat baris salinan laporan untuk kolaborasi teknisi (`collaborator_of`)
 * dan pekerjaan multi alat/lokasi (`multi_of`) dari laporan INDUK, sekaligus
 * menyelaraskan kembali isinya setiap kali laporan INDUK disunting.
 *
 * Aturan penyalinan disamakan dengan jalur wizard Telegram
 * (WizardReportSaverTrait::saveReport() dan buatSalinanMultiEquipment()):
 *   - Salinan kolaborasi memakai SELURUH detail pekerjaan induk dan hanya
 *     berbeda pada teknisi pemilik, kode laporan, dan kolom collaborator_of.
 *   - Salinan multi alat memakai detail pekerjaan yang sama, tetapi identitas
 *     alat/lokasi diganti sesuai item tambahan dan area-nya dihitung ulang.
 *   - Baris salinan SELALU memakai kode laporan baru dan tidak pernah menyalin
 *     kolom penaut lain, supaya tidak terbentuk rantai salinan.
 *
 * ATURAN PENGELOLAAN (diputuskan 14/10/2026): baris salinan TIDAK boleh diedit
 * maupun dihapus sendiri-sendiri. Seluruh perubahan pekerjaan dikerjakan pada
 * laporan INDUK, lalu disebarkan ke SEMUA baris salinannya lewat
 * sinkronkanSalinan() supaya isinya tidak pernah berbeda dari induk.
 */
class ReportCopyService
{
    /**
     * Bidang PEKERJAAN yang selalu sama pada laporan induk dan seluruh baris
     * salinannya. Identitas teknisi, kode laporan, dan kolom penaut salinan
     * TIDAK termasuk karena berbeda per baris.
     *
     * Dipakai DUA jalur sekaligus - pembuatan salinan baru (dataSalinan()) dan
     * penyelarasan salinan lama (sinkronkanSalinan()) - supaya keduanya tidak
     * pernah berbeda aturan.
     *
     * @param  Report  $induk  Laporan sumber
     * @return array           Data siap dipakai Report::create()/update()
     */
    public function bidangPekerjaan(Report $induk): array
    {
        return [
            'report_date'             => $induk->report_date,
            'work_description'        => $induk->work_description,
            'work_classification'     => $induk->work_classification,
            'jenis_pekerjaan'         => $induk->jenis_pekerjaan,
            // Bidang CM ikut disalin supaya laporan salinan tidak kehilangan
            // keterkaitan dengan temuan CM maupun tanggal kejadiannya.
            'equipment_tag'           => $induk->equipment_tag,
            'sesuai_rencana'          => $induk->sesuai_rencana,
            'tanggal_kejadian'        => $induk->tanggal_kejadian,
            'linked_finding_id'       => $induk->linked_finding_id,
            'ai_analyzed'             => (bool) $induk->ai_analyzed,
            'ai_confidence'           => $induk->ai_confidence,
            'work_duration_minutes'   => $induk->work_duration_minutes,
            'start_time'              => $induk->start_time,
            'end_time'                => $induk->end_time,
            'root_cause'              => $induk->root_cause,
            'finding'                 => $induk->finding,
            // Foto memakai path yang SAMA dengan induk sehingga tidak ada file
            // foto ganda di penyimpanan; pemilik berkas tetap laporan induk.
            'photo_documentation'     => $induk->photo_documentation ?? [],
            'photo_hygiene_clearance' => $induk->photo_hygiene_clearance ?? [],
            'status'                  => $induk->status,
            'continued_from_id'       => $induk->continued_from_id,
            'completed_at'            => $induk->completed_at,
            'wizard_started_at'       => $induk->wizard_started_at,
            'submitted_at'            => $induk->submitted_at,
            // Perubahan dari halaman web memang tercatat sebagai suntingan manual.
            'is_manually_edited'      => true,
        ];
    }

    /**
     * Bidang IDENTITAS LOKASI/ALAT laporan induk.
     *
     * Dipakai salinan KOLABORASI (pekerjaannya sama persis, termasuk lokasi dan
     * alatnya). Salinan multi alat/lokasi TIDAK memakai bidang ini karena
     * lokasi/alatnya justru milik item masing-masing.
     *
     * @param  Report  $induk  Laporan sumber
     * @return array           Data siap dipakai Report::create()/update()
     */
    public function bidangLokasi(Report $induk): array
    {
        return [
            'asset_id'    => $induk->asset_id,
            'area_id'     => $induk->area_id,
            'funcloc_id'  => $induk->funcloc_id,
            'report_type' => $induk->report_type,
        ];
    }

    /**
     * Salin data pekerjaan laporan induk (tanpa kode laporan & kolom penaut).
     *
     * @param  Report  $induk  Laporan sumber
     * @return array           Data siap dipakai Report::create()
     */
    public function dataSalinan(Report $induk): array
    {
        return array_merge($this->bidangPekerjaan($induk), $this->bidangLokasi($induk));
    }

    /**
     * Buat baris salinan kolaborasi untuk satu teknisi.
     *
     * creator_id diisi teknisi pembuat laporan induk (bukan kolaborator),
     * sama seperti jalur wizard: yang menginput pekerjaan tetap tercatat
     * sebagai pembuat, sedangkan kolaborator menjadi pemilik baris salinan.
     *
     * @param  Report      $induk        Laporan induk (collaborator_of NULL)
     * @param  Technician  $kolaborator  Teknisi yang ikut andil
     * @return Report                    Baris salinan yang tersimpan
     */
    public function buatSalinanKolaborasi(Report $induk, Technician $kolaborator): Report
    {
        return Report::create(array_merge($this->dataSalinan($induk), [
            'report_code'     => Report::generateReportCode(),
            'technician_id'   => $kolaborator->id,
            'creator_id'      => $induk->creator_id ?? $induk->technician_id,
            'collaborator_of' => $induk->id,
        ]));
    }

    /**
     * Buat baris salinan untuk satu alat/lokasi tambahan pekerjaan multi item.
     *
     * @param  Report                   $induk          Laporan induk (multi_of NULL)
     * @param  Asset|null               $aset           Alat tambahan (bila item berupa alat)
     * @param  FunctionalLocation|null  $fungsiLokasi   Node lokasi tambahan (bila item berupa area)
     * @return Report                                   Baris salinan yang tersimpan
     */
    public function buatSalinanMulti(Report $induk, ?Asset $aset, ?FunctionalLocation $fungsiLokasi): Report
    {
        $asetId = $aset?->id;

        return Report::create(array_merge($this->dataSalinan($induk), [
            'report_code'   => Report::generateReportCode(),
            'technician_id' => $induk->technician_id,
            'creator_id'    => $induk->creator_id ?? $induk->technician_id,
            'asset_id'      => $asetId,
            'equipment_tag' => $aset?->tech_ident_no,
            'funcloc_id'    => $fungsiLokasi?->id ?? $aset?->funcloc_id,
            // Area dihitung dari alat/lokasi tambahan; bila tidak diketahui,
            // pakai area laporan induk supaya laporan tidak tampil tanpa area.
            'area_id'       => $this->areaUntukItem($aset, $fungsiLokasi) ?? $induk->area_id,
            'report_type'   => $asetId !== null ? 'equipment_repair' : 'area_work',
            'multi_of'      => $induk->id,
        ]));
    }

    /**
     * Tentukan Area sebuah item tambahan.
     *
     * Urutan pencarian: area alat -> area node lokasi -> area FuncLoc milik
     * alat (kolom assets.funcloc_id) -> area node induk lokasi tersebut.
     *
     * @param  Asset|null               $aset          Alat item tambahan
     * @param  FunctionalLocation|null  $fungsiLokasi  Node lokasi item tambahan
     * @return int|null                                ID Area, null bila tidak diketahui
     */
    public function areaUntukItem(?Asset $aset, ?FunctionalLocation $fungsiLokasi): ?int
    {
        if ($aset?->area_id !== null) {
            return (int) $aset->area_id;
        }

        if ($fungsiLokasi?->area_id !== null) {
            return (int) $fungsiLokasi->area_id;
        }

        if ($aset?->funcloc_id !== null) {
            $nodeAset = FunctionalLocation::find($aset->funcloc_id);

            if ($nodeAset?->area_id !== null) {
                return (int) $nodeAset->area_id;
            }
        }

        return $fungsiLokasi?->parent?->area_id !== null
            ? (int) $fungsiLokasi->parent->area_id
            : null;
    }

    /**
     * Selaraskan SELURUH baris salinan laporan INDUK dengan keadaan induknya.
     *
     * Dipanggil setiap kali laporan induk disunting (ReportController::update()
     * dan updateStatus()) supaya perubahan deskripsi, temuan, root cause, jam
     * kerja, status, foto, dan bidang CM otomatis terpasang pada baris salinan
     * kolaborasi maupun multi alat.
     *
     * Identitas tiap baris salinan TETAP dipertahankan:
     *   - salinan kolaborasi: technician_id, creator_id, collaborator_of, kode;
     *   - salinan multi alat: identitas lokasi/alatnya (asset_id, funcloc_id,
     *     area_id, report_type) dan kode barisnya.
     * Laporan yang BUKAN induk tidak menyebarkan apa pun (dijaga di sini supaya
     * pemanggil tidak perlu memeriksa ulang).
     *
     * @param  Report  $induk  Laporan induk yang baru saja disimpan
     * @return int             Jumlah baris salinan yang ikut diperbarui
     */
    public function sinkronkanSalinan(Report $induk): int
    {
        if ($induk->collaborator_of !== null || $induk->multi_of !== null) {
            return 0;
        }

        $pekerjaan = $this->bidangPekerjaan($induk);
        $lokasi    = $this->bidangLokasi($induk);
        $jumlah    = 0;

        // Salinan kolaborasi: identitas teknisi/kode berbeda, sisanya sama.
        foreach ($induk->collaboratorReports()->get() as $salinan) {
            $salinan->update($pekerjaan + $lokasi);
            $jumlah++;
        }

        // Salinan multi alat/lokasi: lokasi & tipe laporan MILIK ITEM itu,
        // jadi bidang lokasi tidak ikut ditimpa.
        foreach ($induk->multiItems()->get() as $salinan) {
            $salinan->update($pekerjaan);
            $jumlah++;
        }

        return $jumlah;
    }
}
