<?php

namespace App\Services\Telegram\Traits;

use App\Models\Area;
use App\Models\Asset;
use App\Models\SubArea;

/**
 * ClarificationMessageBuilderTrait
 *
 * Membangun pesan dan navigasi level saat ini dalam sesi klarifikasi:
 *   - buildCurrentMessage() : Router utama — dispatch ke builder keyboard
 *                             yang sesuai berdasarkan session['level']
 *   - buildDoneMessage()    : Pesan konfirmasi setelah equipment atau
 *                             area dipilih (level = 'done')
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai
 * (disediakan oleh ClarificationKeyboardBuilderTrait):
 *   - buildCompanySelection(): array
 *   - buildDepartmentSelection(?int $companyId): array
 *   - buildAreaSelection(?int $departmentId, ?int $companyId): array
 *   - buildSubAreaSelection(int $areaId): array
 *   - buildSectionSelection(?int $areaId, ?int $subAreaId): array
 *   - buildTypeSelection(?int $areaId, ?int $subAreaId, ?string $sectionCode): array
 *   - buildEquipmentSelection(?int $areaId, ?int $subAreaId, ...): array
 */
trait ClarificationMessageBuilderTrait
{
    /**
     * Router utama: bangun pesan dan keyboard untuk level hierarki saat ini.
     * Dipanggil dari ReportWizardService setiap kali perlu menampilkan
     * keyboard ke teknisi, baik saat pertama kali maupun setelah tiap pilihan.
     *
     * @param  array $session Sesi klarifikasi saat ini
     * @return array Respons dengan message, keyboard, dan flag opsional
     *               (done, auto_select, skip, is_area_work, dsb)
     */
    public function buildCurrentMessage(array $session): array
    {
        switch ($session['level']) {
            case 'company_selection':
                return $this->buildCompanySelection();

            case 'department_selection':
                return $this->buildDepartmentSelection($session['selected_company_id']);

            case 'area_selection':
                return $this->buildAreaSelection(
                    $session['selected_department_id'],
                    $session['selected_company_id']
                );

            case 'subarea_selection':
                return $this->buildSubAreaSelection($session['selected_area_id']);

            case 'section_selection':
                return $this->buildSectionSelection(
                    $session['selected_area_id'],
                    $session['selected_sub_area_id']
                );

            case 'type_selection':
                return $this->buildTypeSelection(
                    $session['selected_area_id'],
                    $session['selected_sub_area_id'],
                    $session['selected_section_code']
                );

            case 'equipment_selection':
                return $this->buildEquipmentSelection(
                    $session['selected_area_id'],
                    $session['selected_sub_area_id'],
                    $session['selected_section_code'],
                    $session['selected_type_code']
                );

            case 'done':
                return $this->buildDoneMessage($session);

            default:
                return [
                    'message'  => 'Pilih area kerja:',
                    'keyboard' => [],
                ];
        }
    }

    /**
     * Bangun pesan konfirmasi setelah equipment dipilih atau dilewati (pekerjaan area).
     * Menyertakan informasi lokasi (area, sub area) dari relasi model.
     * Flag 'done' => true dipakai ReportWizardService untuk mendeteksi bahwa
     * hierarki selesai dan bisa melanjutkan ke Step 4.
     *
     * @param  array $session Sesi klarifikasi yang sudah selesai
     * @return array Respons dengan message, flag done, dan data pilihan
     */
    private function buildDoneMessage(array $session): array
    {
        $isAreaWork = !empty($session['is_area_work']);
        $area       = $session['selected_area_id'] ? Area::find($session['selected_area_id']) : null;
        $subArea    = $session['selected_sub_area_id'] ? SubArea::find($session['selected_sub_area_id']) : null;

        if ($isAreaWork || !$session['selected_asset_id']) {
            $areaCode = $session['selected_area_code'] ?? ($area ? $area->code : '');

            $msg  = "Pekerjaan Area dipilih!\n\n";
            $msg .= "{$areaCode}";
            if ($subArea) {
                $code = $subArea->code ? "[{$subArea->code}] " : '';
                $msg .= " - {$code}{$subArea->name}";
            }
            $msg .= "\nIni akan dicatat sebagai pekerjaan area.\n";
            $msg .= "Silakan kirim deskripsi detail pekerjaan.";

            return [
                'message'          => $msg,
                'keyboard'         => [],
                'done'             => true,
                'is_area_work'     => true,
                'selected_area_id' => $session['selected_area_id'],
            ];
        }

        $asset = Asset::with(['area', 'subArea'])->find($session['selected_asset_id']);

        $msg = "Equipment dipilih!\n\n";
        if ($asset) {
            $msg .= "{$asset->tech_ident_no}";
            if ($asset->functional_loc) {
                $msg .= " ({$asset->functional_loc})";
            }
            $msg .= "\n";
            if ($asset->area) {
                $msg .= "Area: {$asset->area->code} - {$asset->area->name}\n";
            }
            if ($asset->subArea) {
                $code = $asset->subArea->code ? "[{$asset->subArea->code}] " : '';
                $msg .= "Sub Area: {$code}{$asset->subArea->name}\n";
            }
        }

        return [
            'message'           => $msg,
            'keyboard'          => [],
            'done'              => true,
            'selected_asset_id' => $session['selected_asset_id'],
        ];
    }
}
