<?php

namespace App\Http\Controllers\Produksi\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// --- IMPORT MODEL MASTER BARU ---
use App\Models\Produksi\Master\MsProductionLine;

// --- IMPORT MODEL TRANSAKSI BARU ---
use App\Models\Produksi\Transaksi\TrsInputHarian;

// --- IMPORT MODEL DETAIL (UDAH DIPINDAHIN KE RUMAH BARU) ---
use App\Models\Produksi\Detail\DetailReject;
use App\Models\Produksi\Detail\DetailRepair;
use App\Models\Produksi\Detail\DetailDowntime;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1. MEKANISME SESSION FILTER (Biar tidak hilang saat Back)
        if ($request->has('reset')) {
            session()->forget('dashboard_filters');
            return redirect('/dashboard');
        }

        if ($request->has('start_date') || $request->has('line_name')) {
            session(['dashboard_filters' => $request->all()]);
        }

        $filters = $request->all() ?: session('dashboard_filters', []);

        // Parameter Filter
        $startDate = $filters['start_date'] ?? date('Y-m-d');
        $endDate = $filters['end_date'] ?? date('Y-m-d');
        $lineName = $filters['line_name'] ?? null;
        $shift = $filters['shift'] ?? 'All Shift';
        
        // Master data line untuk dropdown
        $lines = MsProductionLine::where('Status', 1)->get();

        // 2. QUERY MASTER (Gembok Utama Filter)
        $query = TrsInputHarian::whereBetween('TanggalProduksi', [$startDate, $endDate]);
        
        $query->whereHas('productionLine', function($q) use ($lineName, $shift) {
            if ($lineName) {
                $q->where('NamaProductionLine', $lineName);
            }
            if ($shift && $shift !== 'All Shift') {
                $cleanShift = str_replace('Shift ', '', $shift);
                $q->where('Shift', 'LIKE', '%' . $cleanShift . '%');
            }
        });

        $filteredIds = $query->pluck('IdInputHarian');

        // 3. QUERY SUMMARY PER LINE (CARDS OEE ATAS)
        $targetLines = ['Line E', 'Line F', 'Line K'];
        $lineStats = collect();

        foreach ($targetLines as $name) {
            $data = TrsInputHarian::whereIn('IdInputHarian', $filteredIds)
                ->whereHas('productionLine', fn($q) => $q->where('NamaProductionLine', 'LIKE', '%' . $name . '%'))
                ->select(
                    DB::raw('AVG(OEE) as avg_oee'),
                    DB::raw('AVG(RejectRate) as avg_reject'),
                    DB::raw('AVG(RepairRate) as avg_repair')
                )->first();

            $lineStats->put($name, (object)[
                'avg_oee' => $data->avg_oee ?? 0,
                'avg_reject' => $data->avg_reject ?? 0,
                'avg_repair' => $data->avg_repair ?? 0
            ]);
        }

        // 4. QUERY PARETO (TOP 5)
        $paretoRejectItem = DetailReject::whereIn('prod_detailreject.IdInputHarian', $filteredIds)
            ->join('prod_trsinputharian', 'prod_detailreject.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msitemproduction', fn($j) => $j->on('prod_trsinputharian.IdItemProduksi', '=', DB::raw('prod_msitemproduction.IdItemProduksi COLLATE utf8mb4_unicode_ci')))
            ->select('prod_msitemproduction.NamaPart as label', DB::raw('SUM(prod_detailreject.Qty) as total'))
            ->groupBy('prod_msitemproduction.NamaPart')->orderBy('total', 'desc')->take(5)->get();

        $paretoReject = DetailReject::whereIn('IdInputHarian', $filteredIds)
            ->select('NamaKerusakan', DB::raw('SUM(Qty) as total'))
            ->groupBy('NamaKerusakan')->orderBy('total', 'desc')->take(5)->get();

        $paretoRepairItem = DetailRepair::whereIn('prod_detailrepair.IdInputHarian', $filteredIds)
            ->join('prod_trsinputharian', 'prod_detailrepair.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msitemproduction', fn($j) => $j->on('prod_trsinputharian.IdItemProduksi', '=', DB::raw('prod_msitemproduction.IdItemProduksi COLLATE utf8mb4_unicode_ci')))
            ->select('prod_msitemproduction.NamaPart as label', DB::raw('SUM(prod_detailrepair.Qty) as total'))
            ->groupBy('prod_msitemproduction.NamaPart')->orderBy('total', 'desc')->take(5)->get();

        $paretoRepair = DetailRepair::whereIn('IdInputHarian', $filteredIds)
            ->select('NamaKerusakan', DB::raw('SUM(Qty) as total'))
            ->groupBy('NamaKerusakan')->orderBy('total', 'desc')->take(5)->get();

        $paretoDtItem = DetailDowntime::whereIn('prod_detaildowntime.IdInputHarian', $filteredIds)
            ->join('prod_trsinputharian', 'prod_detaildowntime.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msitemproduction', fn($j) => $j->on('prod_trsinputharian.IdItemProduksi', '=', DB::raw('prod_msitemproduction.IdItemProduksi COLLATE utf8mb4_unicode_ci')))
            ->select('prod_msitemproduction.NamaPart as label', DB::raw('SUM(TIME_TO_SEC(prod_detaildowntime.Durasi))/60 as total'))
            ->groupBy('prod_msitemproduction.NamaPart')->orderBy('total', 'desc')->take(5)->get();

        $paretoDtProb = DetailDowntime::whereIn('IdInputHarian', $filteredIds)
            ->select('TipeDowntime as label', DB::raw('SUM(TIME_TO_SEC(Durasi))/60 as total'))
            ->groupBy('TipeDowntime')->orderBy('total', 'desc')->take(5)->get();

        $paretoDtDies = DetailDowntime::whereIn('prod_detaildowntime.IdInputHarian', $filteredIds)
            ->join('prod_trsinputharian', 'prod_detaildowntime.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msitemproduction', fn($j) => $j->on('prod_trsinputharian.IdItemProduksi', '=', DB::raw('prod_msitemproduction.IdItemProduksi COLLATE utf8mb4_unicode_ci')))
            ->select('prod_msitemproduction.NamaPart as label', DB::raw('SUM(TIME_TO_SEC(prod_detaildowntime.Durasi))/60 as total'))
            ->groupBy('prod_msitemproduction.NamaPart')
            ->orderBy('total', 'desc')
            ->take(5)
            ->get();

        $gsphData = TrsInputHarian::join('prod_msitemproduction', function($j) {
                $j->on('prod_trsinputharian.IdItemProduksi', '=', DB::raw('prod_msitemproduction.IdItemProduksi COLLATE utf8mb4_unicode_ci'));
            })
            ->whereIn('prod_trsinputharian.IdInputHarian', $filteredIds)
            ->select(
                'prod_msitemproduction.NamaPart as label', 
                'prod_trsinputharian.AktualGSPH as actual', 
                'prod_msitemproduction.BestGSPH as plan'
            )
            ->orderBy('actual', 'desc')
            ->take(5)
            ->get();

        $recentProduction = TrsInputHarian::with(['productionLine'])
            ->whereIn('IdInputHarian', $filteredIds)
            ->select(
                DB::raw('MAX(IdInputHarian) as IdInputHarian'), 
                'TanggalProduksi', 
                'IdProductionLine',
                DB::raw('SUM(GoodA) as GoodA'),
                DB::raw('SUM(GoodB) as GoodB'),
                DB::raw('SUM(RejectA) as RejectA'),
                DB::raw('SUM(RejectB) as RejectB'),
                DB::raw('SUM(RepairA) as RepairA'),
                DB::raw('SUM(RepairB) as RepairB'),
                DB::raw('AVG(AktualGSPH) as AktualGSPH')
            )
            ->groupBy('TanggalProduksi', 'IdProductionLine') 
            ->orderBy('TanggalProduksi', 'desc')
            ->paginate(5, ['*'], 'prod_page')
            ->appends($filters);

        $recentDowntime = DetailDowntime::join('prod_trsinputharian', 'prod_detaildowntime.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->join('prod_msitemproduction', fn($j) => $j->on('prod_trsinputharian.IdItemProduksi', '=', DB::raw('prod_msitemproduction.IdItemProduksi COLLATE utf8mb4_unicode_ci')))
            ->whereIn('prod_trsinputharian.IdInputHarian', $filteredIds)
            ->select('prod_trsinputharian.TanggalProduksi', 'prod_msproductionline.NamaProductionLine', 'prod_msitemproduction.NamaPart', 'prod_detaildowntime.*')
            ->orderBy('prod_trsinputharian.TanggalProduksi', 'desc')
            ->paginate(5, ['*'], 'dt_page')
            ->appends($filters); 
    
        return view('Produksi.dashboard.index', [
            'lineStats' => $lineStats,
            'recentProduction' => $recentProduction,
            'recentDowntime' => $recentDowntime,
            'paretoReject' => $paretoReject,
            'paretoRepair' => $paretoRepair,
            'paretoRejectItem' => $paretoRejectItem,
            'paretoRepairItem' => $paretoRepairItem,
            'paretoDtItem' => $paretoDtItem,
            'paretoDtProb' => $paretoDtProb,
            'paretoDtDies' => $paretoDtDies,
            'gsphData' => $gsphData,
            'lines' => $lines,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'shift' => $shift,
            'lineId' => $lineName
        ]);
    }

    public function detailHarian($id)
    {
        // Ambil data utama (Header)
        $data = TrsInputHarian::with(['productionLine', 'item', 'karyawan', 'planDetail', 'detailsDowntime', 'detailsIdleTime'])
            ->where('IdInputHarian', $id)
            ->firstOrFail();
            
        // Ambil semua item yang terkait (untuk tabel looping di bawah)
        $parts = explode('-', $id);
        $idPlan = $parts[1] ?? null;
        
        $details = TrsInputHarian::with(['item'])
            ->where('IdInputHarian', 'LIKE', '%-' . $idPlan . '-%')
            ->get();

        return view('Produksi.dashboard.detail_harian', compact('data', 'details'));
    }

    public function detailDowntime($id)
    {
        $header = TrsInputHarian::with(['productionLine', 'item'])
            ->where('IdInputHarian', $id)->firstOrFail();

        $details = DetailDowntime::where('IdInputHarian', $id)
            ->orderBy('created_at', 'asc')
            ->paginate(10); 

        return view('Produksi.dashboard.detail_downtime', compact('header', 'details'));
    }

    public function getParetoDetail(Request $request)
    {
        // Gunakan session untuk memastikan filter di modal sama dengan filter dashboard
        $filters = session('dashboard_filters', []);
        
        $type = $request->get('type'); 
        $startDate = $request->get('start_date', $filters['start_date'] ?? date('Y-m-d'));
        $endDate = $request->get('end_date', $filters['end_date'] ?? date('Y-m-d'));
        $lineName = $request->get('line_name', $filters['line_name'] ?? null);
        $shift = $request->get('shift', $filters['shift'] ?? 'All Shift');

        $query = TrsInputHarian::whereBetween('TanggalProduksi', [$startDate, $endDate]);

        if ($lineName) {
            $query->whereHas('productionLine', function($q) use ($lineName) {
                $q->where('NamaProductionLine', $lineName);
            });
        }

        if ($shift && $shift !== 'All Shift') {
            $query->whereHas('productionLine', function($q) use ($shift) {
                $cleanShift = str_replace('Shift ', '', $shift);
                $q->where('Shift', 'LIKE', '%' . $cleanShift . '%');
            });
        }

        $ids = $query->pluck('IdInputHarian');

        if ($type == 'reject') {
            $data = DetailReject::whereIn('IdInputHarian', $ids)->with(['inputHarian.productionLine', 'inputHarian.item'])->orderBy('created_at', 'desc')->get();
        } elseif ($type == 'repair') {
            $data = DetailRepair::whereIn('IdInputHarian', $ids)->with(['inputHarian.productionLine', 'inputHarian.item'])->orderBy('created_at', 'desc')->get();
        } elseif ($type == 'gsph') {
            $data = TrsInputHarian::whereIn('IdInputHarian', $ids)->with(['productionLine', 'item'])->orderBy('AktualGSPH', 'desc')->get();
        } else {
            $data = DetailDowntime::whereIn('IdInputHarian', $ids)->with(['inputHarian.productionLine', 'inputHarian.item'])->orderBy('Durasi', 'desc')->get();
        }

        return view('Produksi.dashboard.partials.modal_detail_table', compact('data', 'type'));
    }
}