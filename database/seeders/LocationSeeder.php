<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Company;
use App\Models\Department;
use App\Models\SubArea;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        // Company
        $epe = Company::create(['code' => 'EPE', 'name' => 'Ecogreen Oleochemicals']);

        // Departments
        $prod = Department::create(['company_id' => $epe->id, 'code' => 'PROD', 'name' => 'Production']);
        $loge = Department::create(['company_id' => $epe->id, 'code' => 'LOGE', 'name' => 'Logistics']);
        $mtce = Department::create(['company_id' => $epe->id, 'code' => 'MTCE', 'name' => 'Maintenance']);
        $hrga = Department::create(['company_id' => $epe->id, 'code' => 'HRGA', 'name' => 'HR & General Affairs']);
        $qcrd = Department::create(['company_id' => $epe->id, 'code' => 'QCRD', 'name' => 'Quality Control & R&D']);

        // Areas PROD
        $tf01 = Area::create(['department_id' => $prod->id, 'code' => 'TF01', 'name' => 'Tank Farm Line 1']);
        $bd01 = Area::create(['department_id' => $prod->id, 'code' => 'BD01', 'name' => 'Biodiesel Line 1']);
        $bd02 = Area::create(['department_id' => $prod->id, 'code' => 'BD02', 'name' => 'Biodiesel Line 2']);
        $rg01 = Area::create(['department_id' => $prod->id, 'code' => 'RG01', 'name' => 'Refinery & Glycerine Line 1']);
        $rg02 = Area::create(['department_id' => $prod->id, 'code' => 'RG02', 'name' => 'Refinery & Glycerine Line 2']);
        $md01 = Area::create(['department_id' => $prod->id, 'code' => 'MD01', 'name' => 'Methylester Distillation']);
        $en01 = Area::create(['department_id' => $prod->id, 'code' => 'EN01', 'name' => 'Enzymatic Biodiesel']);
        $ut01 = Area::create(['department_id' => $prod->id, 'code' => 'UT01', 'name' => 'Utility']);

        // Areas LOGE
        $st01 = Area::create(['department_id' => $loge->id, 'code' => 'ST01', 'name' => 'Storage']);
        $ul01 = Area::create(['department_id' => $loge->id, 'code' => 'UL01', 'name' => 'Unloading']);
        $ul02 = Area::create(['department_id' => $loge->id, 'code' => 'UL02', 'name' => 'Loading Bay']);
        $wb01 = Area::create(['department_id' => $loge->id, 'code' => 'WB01', 'name' => 'Weighbridge']);
        $wh02 = Area::create(['department_id' => $loge->id, 'code' => 'WH02', 'name' => 'Warehouse']);

        // Areas MTCE
        Area::create(['department_id' => $mtce->id, 'code' => 'MT01', 'name' => 'Workshop']);
        Area::create(['department_id' => $mtce->id, 'code' => 'MT02', 'name' => 'M&E Office']);
        Area::create(['department_id' => $mtce->id, 'code' => 'MT03', 'name' => 'Utility Maintenance']);

        // Areas HRGA
        Area::create(['department_id' => $hrga->id, 'code' => 'GA01', 'name' => 'Office']);
        Area::create(['department_id' => $hrga->id, 'code' => 'GA02', 'name' => 'Canteen & Mosque']);
        Area::create(['department_id' => $hrga->id, 'code' => 'GA03', 'name' => 'Security Post']);

        // Areas QCRD
        Area::create(['department_id' => $qcrd->id, 'code' => 'QC01', 'name' => 'Lab']);

        // SubAreas for BD01
        SubArea::create(['area_id' => $tf01->id, 'code' => '6010', 'name' => 'Storage & Pump Area TF']);

        SubArea::create(['area_id' => $bd01->id, 'code' => '6153', 'name' => 'Oil Pre-treatment']);
        SubArea::create(['area_id' => $bd01->id, 'code' => '6160', 'name' => 'Methanol Section']);
        SubArea::create(['area_id' => $bd01->id, 'code' => '6163', 'name' => 'Reaction & Separation']);
        SubArea::create(['area_id' => $bd01->id, 'code' => '6166', 'name' => 'Glycerine Separation']);
        SubArea::create(['area_id' => $bd01->id, 'code' => '6600', 'name' => 'Water & Chemical Dosing']);

        SubArea::create(['area_id' => $bd02->id, 'code' => '6153', 'name' => 'Oil Pre-neutralization']);
        SubArea::create(['area_id' => $bd02->id, 'code' => '6160', 'name' => 'Methanol Feeding']);
        SubArea::create(['area_id' => $bd02->id, 'code' => '6163', 'name' => 'Reaction & Recirculation']);
        SubArea::create(['area_id' => $bd02->id, 'code' => '6166', 'name' => 'Glycerine Separation BD2']);
        SubArea::create(['area_id' => $bd02->id, 'code' => '6600', 'name' => 'Water & Citric Dosing BD2']);

        SubArea::create(['area_id' => $rg01->id, 'code' => '6050', 'name' => 'Filter Bag Area']);
        SubArea::create(['area_id' => $rg01->id, 'code' => '6400', 'name' => 'Filling Section']);
        SubArea::create(['area_id' => $rg01->id, 'code' => '6920', 'name' => 'Thermostatized Water System']);

        SubArea::create(['area_id' => $rg02->id, 'code' => '6400', 'name' => 'Filling & Refinery Section']);
        SubArea::create(['area_id' => $rg02->id, 'code' => '6920', 'name' => 'Thermostatized Water RG2']);

        SubArea::create(['area_id' => $md01->id, 'code' => '6540', 'name' => 'Distillation Section']);
        SubArea::create(['area_id' => $md01->id, 'code' => '9640', 'name' => 'Thermal Oil System']);

        SubArea::create(['area_id' => $en01->id, 'code' => '6170', 'name' => 'Enzymatic Reactor']);

        SubArea::create(['area_id' => $ut01->id, 'code' => '6020', 'name' => 'Cooling & Utility']);

        SubArea::create(['area_id' => $ul02->id, 'code' => '6040', 'name' => 'Loading Bay Flowmeter']);
    }
}
