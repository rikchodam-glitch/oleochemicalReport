<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\Asset;
use App\Models\BotRegistration;
use App\Models\BotUnknownAsset;
use App\Models\Report;
use App\Models\Technician;

class DashboardController extends Controller
{
    public function index()
    {
        $totalAssets = Asset::count();
        $activeAssets = Asset::where('status', 'active')->count();
        $activeTechnicians = Technician::where('status', 'active')->count();
        $pendingTechnicians = Technician::where('status', 'pending')->count();

        $today = now()->toDateString();
        $reportsToday = Report::whereDate('report_date', $today)->count();
        $reportsPendingReview = Report::whereDate('report_date', $today)->where('status', 'needs_review')->count();
        $reportsCompletedToday = Report::whereDate('report_date', $today)->where('status', 'completed')->count();

        $recentReports = Report::with(['technician', 'area'])
            ->latest()
            ->take(10)
            ->get();

        $healthyProviders = AiProvider::where('status', 'healthy')->count();
        $totalProviders = AiProvider::count();

        $unknownAssets = BotUnknownAsset::count();
        $pendingRegistrations = BotRegistration::where('status', 'pending')->count();

        return view('dashboard.index', compact(
            'totalAssets',
            'activeAssets',
            'activeTechnicians',
            'pendingTechnicians',
            'reportsToday',
            'reportsPendingReview',
            'reportsCompletedToday',
            'recentReports',
            'healthyProviders',
            'totalProviders',
            'unknownAssets',
            'pendingRegistrations'
        ));
    }
}
