<?php

namespace App\Http\Controllers\Produksi\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// --- MODEL TRANSAKSI ---
use App\Models\Produksi\Transaksi\TrsInputHarian;

// --- MODEL DETAIL (✅ SUDAH DIPINDAHKAN KE SINI) ---
use App\Models\Produksi\Detail\DetailRepair;
use App\Models\Produksi\Detail\DetailReject; // Dulu TrsDetailReject
use App\Models\Produksi\Detail\DetailDowntime; // Dulu TrsDetailDowntime

// --- MODEL MASTER ---
use App\Models\Produksi\Master\MsAsakaiMain;
use App\Models\Produksi\Master\MsAsakaiDowntime;
use App\Models\Produksi\Master\MsAsakaiGsph;
use App\Models\Produksi\Master\MsAsakaiPencapaianProduksi;
use App\Models\Produksi\Master\MsAsakaiQuality;
use App\Models\Produksi\Master\MsAsakaiSafety;
use App\Models\Produksi\Master\MsAsakaiSpot;
use App\Models\Produksi\Master\MsProductionLine;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AsakaiReportController extends Controller
{
    public function index(Request $request)
    {
        $tanggal = $request->get('date', date('Y-m-d'));
        $idPayung = 'ASA-' . str_replace('-', '', $tanggal);

        $asakai = \App\Models\Produksi\Master\MsAsakaiMain::where('TanggalProduksi', $tanggal)->first();
        $reports = [];

        if ($asakai) {
            $summaryHarian = TrsInputHarian::whereDate('TanggalProduksi', $tanggal)
                ->selectRaw("
                    SUM(IFNULL(PlanQtyA, 0) + IFNULL(PlanQtyB, 0)) as total_plan,
                    SUM(IFNULL(GoodA, 0) + IFNULL(GoodB, 0)) as total_act,
                    SUM(IFNULL(RejectA, 0) + IFNULL(RejectB, 0)) as total_reject,
                    SUM(IFNULL(RepairA, 0) + IFNULL(RepairB, 0)) as total_repair,
                    AVG(AktualGSPH) as avg_gsph
                ")->first();

            // AMBIL INFO SAFETY (Cek apakah ada Accident/Inccident/Traffic)
            $safety = \App\Models\Produksi\Master\MsAsakaiSafety::where('IdAsakaiSafety', 'SAFE-'.$idPayung)->first();
            $isSafe = true;
            if($safety && ($safety->AccidentAct > 0 || $safety->InccidentAct > 0 || $safety->TrafficAct > 0)) {
                $isSafe = false;
            }

            // AMBIL TOP ISSUE DOWNTIME (Ambil 1 issue paling lama durasinya)
            $topIssue = \App\Models\Produksi\Master\MsAsakaiDowntime::where('IdAsakaiDowntime', 'DT-'.$idPayung)->first();
            // Looping sebentar buat cari issue yang nggak null (contoh simple)
            $majorIssue = "No Major Downtime";
            if($topIssue) {
                // Logic sederhana: ambil issue Line E atau F yang pertama ketemu
                $majorIssue = $topIssue->LineEIssueDT_S1 ?? $topIssue->LineFIssueDT_S1 ?? "Operational Normal";
            }

            $reports[] = (object)[
                'TanggalProduksi' => $tanggal,
                'LineName'        => 'SUMMARY ALL LINE',
                'first_id'        => $asakai->IdInputHarian,
                'total_plan'      => $summaryHarian->total_plan ?? 0,
                'total_act'       => $summaryHarian->total_act ?? 0,
                'total_reject'    => $summaryHarian->total_reject ?? 0,
                'total_repair'    => $summaryHarian->total_repair ?? 0,
                'avg_gsph'        => $summaryHarian->avg_gsph ?? 0,
                // Info Tambahan Baru
                'is_safe'         => $isSafe,
                'major_issue'     => $majorIssue
            ];
        }

        $linesAll = MsProductionLine::where('Status', 1)->get();
        return view('Produksi.report.asakai.index', compact('reports', 'tanggal', 'linesAll'));
    }


    public function create(Request $request, $id = null)
    {
        // 1. Ambil Single Date
        $tanggalPilihan = $request->input('date', date('Y-m-d'));
        $start = $tanggalPilihan;
        $end = $tanggalPilihan;
        $idPayung = 'ASA-' . str_replace('-', '', $tanggalPilihan);

        // --- LOGIC ACCUM (MTD) ---
        $startOfMonth = \Carbon\Carbon::parse($tanggalPilihan)->startOfMonth()->format('Y-m-d');
        $listIdHarianToday = TrsInputHarian::whereDate('TanggalProduksi', $tanggalPilihan)->pluck('IdInputHarian');
        $listIdHarianAccum = TrsInputHarian::whereBetween('TanggalProduksi', [$startOfMonth, $tanggalPilihan])->pluck('IdInputHarian');

        $defaultData = (object)[
            'totalLineE' => 0, 'accumLineE' => 0,
            'totalLineF' => 0, 'accumLineF' => 0,
            'totalLineK' => 0, 'accumLineK' => 0,
            'listIssue' => ''
        ];

        // Cek dan Ambil Data Harian Utama
        if ($id) {
            $harian = TrsInputHarian::with(['productionLine'])->findOrFail($id);
        } else {
            $harianRef = TrsInputHarian::whereIn('IdInputHarian', $listIdHarianToday)->first();
            $harian = new TrsInputHarian();
            $harian->IdInputHarian = $harianRef->IdInputHarian ?? null; 
            $harian->TanggalProduksi = $tanggalPilihan;
        }

        // Ikat instansiasi utama ke variabel $asakai
        $asakai = $harian;

        if ($listIdHarianToday->isNotEmpty()) {
            // --- 1. QUERY TOTAL PRODUKSI MTD (Hanya Kolom A) ---
            $totalProduksiMTD = TrsInputHarian::whereIn('prod_trsinputharian.IdInputHarian', $listIdHarianAccum)
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineE,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineF,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineK
                ")->first();

            // Query REPAIR
            $repairData = DetailRepair::whereIn('prod_detailrepair.IdInputHarian', $listIdHarianAccum)
                ->join('prod_trsinputharian', 'prod_detailrepair.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineE,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineE,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineF,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineF,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineK,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineK,
                    GROUP_CONCAT(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' THEN CONCAT(prod_msproductionline.NamaProductionLine, ': ', NamaKerusakan, ' (', CAST(prod_detailrepair.Qty AS UNSIGNED), ' PCS)') END SEPARATOR '\n') as listIssue
                ")->first();

            // Query REJECT
            $rejectData = \App\Models\Produksi\Detail\DetailReject::whereIn('prod_detailreject.IdInputHarian', $listIdHarianAccum)
                ->join('prod_trsinputharian', 'prod_detailreject.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineE,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineE,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineF,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineF,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineK,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineK,
                    GROUP_CONCAT(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' THEN CONCAT(prod_msproductionline.NamaProductionLine, ': ', NamaKerusakan, ' (', CAST(prod_detailreject.Qty AS UNSIGNED), ' PCS)') END SEPARATOR '\n') as listIssue
                ")->first();

            // --- 4. Query PRODUCTIVITY ---
            $productivityData = TrsInputHarian::whereIn('prod_trsinputharian.IdInputHarian', $listIdHarianToday)
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planE_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actE_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planF_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actF_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planK_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actK_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planE_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actE_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planF_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actF_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planK_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actK_S2
                ")->first();

            // --- 5. QUERY DOWNTIME RAW ---
            $rawDowntime = \App\Models\Produksi\Detail\DetailDowntime::join('prod_trsinputharian', 'prod_detaildowntime.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->whereBetween('prod_trsinputharian.TanggalProduksi', [$startOfMonth, $tanggalPilihan])
                ->select('prod_detaildowntime.*', 'prod_msproductionline.NamaProductionLine', 'prod_msproductionline.Shift', 'prod_trsinputharian.TanggalProduksi')
                ->get();

        } else {
            $totalProduksiMTD = (object)['prodLineE'=>0, 'prodLineF'=>0, 'prodLineK'=>0];
            $repairData = $defaultData;
            $rejectData = $defaultData;
            $productivityData = (object)[];
            $rawDowntime = collect();
        }

        // --- 6. MAPPING DOWNTIME UNTUK KEPERLUAN AUTO-SUMMARY GSPH ---
        $downtimeData = new \stdClass();
        $allLines = ['LineE', 'LineF', 'LineK', 'D52VT', 'D26', 'HW'];
        foreach ($allLines as $key) {
            $downtimeData->{'dt'.$key} = 0; $downtimeData->{'acc'.$key} = 0;
            $downtimeData->{'dt'.$key.'_S2'} = 0; $downtimeData->{'acc'.$key.'_S2'} = 0;
            $downtimeData->{"type".$key."S1"} = "M/C"; $downtimeData->{"type".$key."S2"} = "M/C";
            $downtimeData->{"issue".$key."S1"} = ""; $downtimeData->{"issue".$key."S2"} = "";
        }

        foreach ($rawDowntime as $row) {
            $shift = trim($row->Shift ?? ''); 
            $sfx = (str_contains(strtoupper($shift), '2')) ? "S2" : "S1";
            $parts = explode(':', $row->Durasi ?? '00:00:00');
            $menit = 0;
            if (count($parts) >= 2) {
                $menit = ($parts[0] * 60) + $parts[1] + (($parts[2] ?? 0) / 60);
            }
            
            $ln = strtoupper($row->NamaProductionLine);
            $k = null;
            if (str_contains($ln, 'LINE E')) $k = 'LineE';
            elseif (str_contains($ln, 'LINE F')) $k = 'LineF';
            elseif (str_contains($ln, 'LINE K')) $k = 'LineK';
            elseif (str_contains($ln, 'D52') || str_contains($ln, 'VT')) $k = 'D52VT';
            elseif (str_contains($ln, 'D26')) $k = 'D26';
            elseif (str_contains($ln, 'HW')) $k = 'HW';

            if ($k) {
                if ($sfx === 'S2') { $downtimeData->{'acc' . $k . "_S2"} += $menit; } 
                else { $downtimeData->{'acc' . $k} += $menit; }

                if ($row->TanggalProduksi == $tanggalPilihan) {
                    if ($sfx === 'S2') { $downtimeData->{'dt' . $k . "_S2"} += $menit; } 
                    else { $downtimeData->{'dt' . $k} += $menit; }
                    
                    $downtimeData->{"issue{$k}{$sfx}"} .= "- " . ($row->Masalah ?? '-') . " (" . round($menit, 1) . " min)\n";
                }
            }
        }

        // --- 7. QUERY GSPH AUTOMATION ---
        $gsphData = TrsInputHarian::whereIn('prod_trsinputharian.IdInputHarian', $listIdHarianToday)
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->selectRaw("
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanGSPH, 0) ELSE 0 END) as PlanGSPHE_S1,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(AktualGSPH, 0) ELSE 0 END) as AktualGSPHE_S1,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanGSPH, 0) ELSE 0 END) as PlanGSPHF_S1,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msProduprod_msproductionlinectionLine.Shift LIKE '%1%' THEN IFNULL(AktualGSPH, 0) ELSE 0 END) as AktualGSPHF_S1,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanGSPH, 0) ELSE 0 END) as PlanGSPHK_S1,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(AktualGSPH, 0) ELSE 0 END) as AktualGSPHK_S1,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanGSPH, 0) ELSE 0 END) as PlanGSPHE_S2,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanGSPH, 0) ELSE 0 END) as PlanGSPHF_S2,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanGSPH, 0) ELSE 0 END) as PlanGSPHK_S2,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(AktualGSPH, 0) ELSE 0 END) as AktualGSPHE_S2,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(AktualGSPH, 0) ELSE 0 END) as AktualGSPHF_S2,
                SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(AktualGSPH, 0) ELSE 0 END) as AktualGSPHK_S2
            ")->first();


        // 🛠️ SEPAKAT AMBIL DATA REKAMAN JIKA PERNAH ADA (OR CREATE NEW JIKA TOTAL BARU)
        $dbMain = \App\Models\Produksi\Master\MsAsakaiMain::where('IdInputHarian', $harian->IdInputHarian)->first() ?? new \App\Models\Produksi\Master\MsAsakaiMain();
        $dbSafety = \App\Models\Produksi\Master\MsAsakaiSafety::where('IdAsakaiSafety', 'SAFE-'.$idPayung)->first() ?? new \App\Models\Produksi\Master\MsAsakaiSafety();
        $dbQuality = \App\Models\Produksi\Master\MsAsakaiQuality::where('IdInputHarian', $harian->IdInputHarian)->first() ?? new \App\Models\Produksi\Master\MsAsakaiQuality();
        $dbPencapaian = \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi::where('IdInputHarian', $harian->IdInputHarian)->first() ?? new \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi();
        $dbDowntime = \App\Models\Produksi\Master\MsAsakaiDowntime::where('IdInputHarian', $harian->IdInputHarian)->first() ?? new \App\Models\Produksi\Master\MsAsakaiDowntime();
        $dbGsph = \App\Models\Produksi\Master\MsAsakaiGsph::where('IdInputHarian', $harian->IdInputHarian)->first() ?? new \App\Models\Produksi\Master\MsAsakaiGsph();
        $dbSpot = \App\Models\Produksi\Master\MsAsakaiSpot::where('IdAsakaiSpot', 'SPOT-'.$idPayung)->first() ?? new \App\Models\Produksi\Master\MsAsakaiSpot();

        // SINKRONISASI FORCE SET RELATION AGAR BLADE PARSIAL AMAN DARI NULL-POINTER
        $asakai->setRelation('asakaiMain', $dbMain);
        $asakai->setRelation('asakaiSafety', $dbSafety);
        $asakai->setRelation('asakaiQuality', $dbQuality);
        $asakai->setRelation('asakaiPencapaian', $dbPencapaian);
        $asakai->setRelation('asakaiDowntime', $dbDowntime);
        $asakai->setRelation('asakaiGsph', $dbGsph);
        $asakai->setRelation('asakaiSpot', $dbSpot);


        // --- 10. RETURN VIEW CREATE ---
        return view('Produksi.report.asakai.create', compact(
            'harian', 'start', 'end', 'repairData', 'rejectData', 
            'productivityData', 'downtimeData', 'gsphData', 'asakai', 'totalProduksiMTD'
        ));
    }

    /**
     * Simpan Data Laporan (Proses POST)
     */
    public function store(Request $request)
    {
        // 1. Ambil data dasar
        $idHarianAsli = $request->IdInputHarian; 
        $tanggal = $request->TanggalProduksi;
        
        if (!$idHarianAsli || !$tanggal) {
            return redirect()->back()->with('error', 'Oops! Something went wrong');
        }

        // 🔥 BLOK TAMBAHAN: CEK DATA GANDA
        $cekDataExist = \App\Models\Produksi\Master\MsAsakaiMain::where('TanggalProduksi', $tanggal)->exists();
        if ($cekDataExist) {
            return redirect()->back()->with('error', 'Asakai data for the date ' . $tanggal . ' has already been created.')->withInput();
        }

        // 2. Buat ID Payung
        $idPayung = 'ASA-' . str_replace('-', '', $tanggal);

        DB::beginTransaction();
        try {
            // --- 1. ASAKAI MAIN (Production Plan) ---
            $linesMain = [
                'LINEE' => 'LE', 'LINEF' => 'LF', 'LINEK' => 'LK', 
                'ASSYD52VT' => 'D52', 'ASSYD26' => 'D26', 'METALFINISH' => 'Metal'
            ];
            $mainData = [
                'IdAsakai' => 'MAIN-'.$idPayung, 
                'IdInputHarian' => $idHarianAsli, 
                'TanggalProduksi' => $tanggal
            ];
            foreach(['S1','S2'] as $s) {
                foreach($linesMain as $bladeKey => $dbPfx) {
                    $mainData["PlanGlc{$dbPfx}{$s}"] = $request->input("PlanGlc{$bladeKey}{$s}", 0);
                    $mainData["PlanTpt{$dbPfx}{$s}"] = $request->input("PlanTpt{$bladeKey}{$s}", 0);
                    $mainData["CapReg{$dbPfx}{$s}"]  = $request->input("CapReg{$bladeKey}{$s}", 0);
                    $mainData["Remarks{$dbPfx}{$s}"] = $request->input("Remarks{$bladeKey}{$s}");
                }
            }
            // 🔥 FIX: Pakai ID Payung!
            \App\Models\Produksi\Master\MsAsakaiMain::updateOrCreate(['IdAsakai' => 'MAIN-'.$idPayung], $mainData);

            // --- 2. ASAKAI SAFETY ---
            \App\Models\Produksi\Master\MsAsakaiSafety::updateOrCreate(
                ['IdAsakaiSafety' => 'SAFE-' . $idPayung], 
                [
                    'TanggalProduksi' => $tanggal,
                    
                    'AccidentTarget'  => $request->input('AccidentTarget', 0),
                    'AccidentAct'     => $request->input('AccidentAct', 0),
                    'AccidentAccum'   => $request->input('AccidentAccum', 0),
                    'AccidentIssue'   => $request->input('AccidentIssue'),
                    'AccidentPIC'     => $request->input('SafetyPIC'),
                    
                    'InccidentTarget' => $request->input('InccidentTarget', 0),
                    'InccidentAct'    => $request->input('InccidentAct', 0),
                    'InccidentAccum'  => $request->input('InccidentAccum', 0),
                    'InccidentIssue'  => $request->input('InccidentIssue'),
                    'InccidentPIC'    => $request->input('SafetyPIC'),
                    
                    'TrafficTarget'   => $request->input('TrafficTarget', 0),
                    'TrafficAct'      => $request->input('TrafficAct', 0),
                    'TrafficAccum'    => $request->input('TrafficAccum', 0),
                    'TrafficIssue'    => $request->input('TrafficIssue'),
                    'TrafficPIC'      => $request->input('SafetyPIC'),
                    
                    'SafetyPic'       => $request->input('SafetyPIC'), 
                ]
            );

            // --- 3. ASAKAI QUALITY ---
            $qualData = [
                'IdAsakaiQuality' => 'QUAL-' . $idPayung,
                'IdInputHarian'   => $idHarianAsli,
                'TanggalProduksi' => $tanggal,
                'CustomersTarget' => $request->input('CustomersTarget', 0),
                'CustomersAct'    => $request->input('CustomersAct', 0),
                'CustomersAcc'    => $request->input('CustomersAcc', 0),
                'CustomersIssue'  => $request->input('CustomersIssue'),
                
                'CustomersPIC'    => $request->input('CustomersPIC'),
                'InternalPIC'     => $request->input('CustomersPIC'), 
                'SupplierPIC'     => $request->input('CustomersPIC'), 
                
                'InternalTarget'  => $request->input('InternalTarget', 0),
                'InternalAct'     => $request->input('InternalAct', 0),
                'InternalAcc'     => $request->input('InternalAcc', 0),
                'InternalIssue'   => $request->input('InternalIssue'),
                
                'SupplierTarget'  => $request->input('SupplierTarget', 0),
                'SupplierAct'     => $request->input('SupplierAct', 0),
                'SupplierAcc'     => $request->input('SupplierAcc', 0),
                'SupplierIssue'   => $request->input('SupplierIssue'),

                'RepairPIC'       => $request->input('REPAIRPIC_Global'),
                'RejectPIC'       => $request->input('REJECTPIC_Global'),
            ];

            $linesQuality = ['LINEE' => 'LineE', 'LINEF' => 'LineF', 'LINEK' => 'LineK']; 

            foreach (['REPAIR', 'REJECT'] as $type) {
                foreach ($linesQuality as $bladeKey => $dbSuffix) {
                    $typeTitle = ucfirst(strtolower($type));
                    
                    $qualData["{$typeTitle}{$dbSuffix}Act"]   = floatval(str_replace('%', '', $request->input("{$type}{$bladeKey}Act", 0)));
                    $qualData["{$typeTitle}{$dbSuffix}Acc"]   = floatval(str_replace('%', '', $request->input("{$type}{$bladeKey}Acc", 0)));
                    $qualData["{$typeTitle}Issue{$dbSuffix}"] = $request->input("{$type}{$bladeKey}Issue");
                    
                    $qualData["{$typeTitle}{$dbSuffix}PIC"]   = $request->input("{$type}PIC_Global");
                }
            }
            // 🔥 FIX: Pakai ID Payung!
            \App\Models\Produksi\Master\MsAsakaiQuality::updateOrCreate(['IdAsakaiQuality' => 'QUAL-'.$idPayung], $qualData);

            // --- 4. ASAKAI PENCAPAIAN PRODUKSI ---
            $prodData = [
                'IdAsakaiPP'      => 'PROD-' . $idPayung, 
                'IdInputHarian'   => $idHarianAsli, 
                'TanggalProduksi' => $tanggal,
                'ProdPicS1'       => $request->input('ProdPicS1'), 
                'ProdPicS2'       => $request->input('ProdPicS2')
            ];

            $linesProd = ['LineE', 'LineF', 'LineK', 'D52Vt' => 'D52VT', 'D26', 'Handwork' => 'HW'];

            foreach(['S1','S2'] as $s) {
                foreach($linesProd as $db => $blade) {
                    $dbKey = is_numeric($db) ? $blade : $db;
                    $prodData["{$dbKey}Plan{$s}"] = $request->input("{$blade}Plan{$s}", 0);
                    $prodData["{$dbKey}Act{$s}"]  = $request->input("{$blade}Act{$s}", 0);
                    
                    $issueCol = ($dbKey == 'D26') ? "D26Issue_{$s}" : "{$dbKey}Issue{$s}";
                    $prodData[$issueCol] = $request->input("{$blade}Issue{$s}");
                }
            }
            // 🔥 FIX: Pakai ID Payung!
            \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi::updateOrCreate(['IdAsakaiPP' => 'PROD-'.$idPayung], $prodData);

            // --- 5. ASAKAI DOWNTIME ---
            $dtData = [
                'IdAsakaiDowntime' => 'DT-'.$idPayung, 
                'IdInputHarian'    => $idHarianAsli, 
                'TanggalProduksi'  => $tanggal,
                'DtPicS1'          => $request->input('DtPicS1'), 
                'DtPicS2'          => $request->input('DtPicS2'),
            ];

            $linesDT = ['LineE', 'LineF', 'LineK', 'D52Vt' => 'D52VT', 'D26', 'Handwork' => 'HW'];

            foreach(['S1','S2'] as $s) {
                foreach($linesDT as $db => $blade) {
                    $dbKey = is_numeric($db) ? $blade : $db;
                    $dtData["{$dbKey}TodayDT{$s}"] = $request->input("{$blade}Dt{$s}", 0);
                    $dtData["{$dbKey}AccDT{$s}"]   = $request->input("{$blade}DtAcc{$s}", 0);
                    $dtData["{$dbKey}TipeDT{$s}"]  = $request->input("{$blade}DtType{$s}", 'M/C');
                    $dtData["{$dbKey}IssueDT{$s}"] = $request->input("{$blade}Issue{$s}");
                }
            }
            // 🔥 FIX: Pakai ID Payung!
            \App\Models\Produksi\Master\MsAsakaiDowntime::updateOrCreate(['IdAsakaiDowntime' => 'DT-'.$idPayung], $dtData);
            
            /// --- 6. ASAKAI GSPH ---
            $gsphData = [
                'IdAsakaiGsph'    => 'GSPH-' . $idPayung, 
                'IdInputHarian'   => $idHarianAsli, 
                'TanggalProduksi' => $tanggal
            ];

            $linesGSPH = ['LineE' => 'LE', 'LineF' => 'LF', 'LineK' => 'LK'];

            foreach(['S1','S2'] as $s) {
                $picGlobal = $request->input("GsphPIC{$s}"); 

                foreach($linesGSPH as $bladeKey => $dbPfx) {
                    $gsphData["GsphTarget{$dbPfx}{$s}"] = ($dbPfx == 'LE') ? 500 : 550;
                    $gsphData["GsphPlan{$dbPfx}{$s}"]   = $request->input("{$bladeKey}PlanGsph{$s}", 0);
                    $gsphData["GsphAct{$dbPfx}{$s}"]    = $request->input("{$bladeKey}ActGsph{$s}", 0);
                    $gsphData["GsphIssue{$dbPfx}{$s}"]  = $request->input("GsphIssue{$bladeKey}{$s}");
                    
                    $gsphData["GsphPic{$dbPfx}{$s}"]    = $picGlobal;
                }
            }
            // 🔥 FIX: Pakai ID Payung!
            \App\Models\Produksi\Master\MsAsakaiGsph::updateOrCreate(['IdAsakaiGsph' => 'GSPH-'.$idPayung], $gsphData);


            // --- 7. ASAKAI SPOT ---
            $idSpot = 'SPOT-' . $idPayung; 

            $sDat = [
                'IdAsakaiSpot'    => $idSpot, 
                'TanggalProduksi' => $tanggal
            ];

            $picSpotGlobal = $request->input('SpotPIC_Global'); 
            $areasS = ['D52', 'Panel', 'Quarter', 'Front'];

            foreach($areasS as $idx => $a) {
                $sDat["Spot{$a}Target"] = $request->input("SpotTarget{$idx}", 0);
                $sDat["Spot{$a}Plan"]   = $request->input("SpotPlan{$idx}", 0);
                $sDat["Spot{$a}Act"]    = $request->input("SpotAct{$idx}", 0);
                $sDat["Spot{$a}Accum"]  = $request->input("SpotAcc{$idx}", 0);
                $sDat["Spot{$a}Issue"]  = $request->input("SpotIssue{$idx}");
                $sDat["Spot{$a}Pic"]    = $picSpotGlobal; 
            }

            \App\Models\Produksi\Master\MsAsakaiSpot::updateOrCreate(
                ['IdAsakaiSpot' => $idSpot], 
                $sDat
            );

            DB::commit();
            return redirect()->route('report.asakai.index')->with('success', 'Data Asakai Berhasil Diperbarui');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Oops! Something went wrong: ' . $e->getMessage())->withInput();
        }
    }

    public function edit($id)
    {
        $harian = TrsInputHarian::with(['productionLine'])->findOrFail($id);
        
        // 🔥 FIX 1: Paksa format jadi Y-m-d biar jam "00:00:00" gak ikut kebawa!
        $tanggalPilihan = \Carbon\Carbon::parse($harian->TanggalProduksi)->format('Y-m-d');
        
        $start = $tanggalPilihan;
        $end = $tanggalPilihan;
        $idPayung = 'ASA-' . str_replace('-', '', $tanggalPilihan);

        // Jadikan $harian sebagai instansiasi utama objek $asakai untuk dikirim ke view
        $asakai = $harian;

        // --- QUERY DIRECT KE MASING-MASING MODEL MODUL (ANTI-MACET RELASI) ---
        $asakaiMain       = \App\Models\Produksi\Master\MsAsakaiMain::where('IdInputHarian', $harian->IdInputHarian)->first();
        $asakaiSafety     = \App\Models\Produksi\Master\MsAsakaiSafety::where('IdAsakaiSafety', 'SAFE-'.$idPayung)->first();
        $asakaiQuality    = \App\Models\Produksi\Master\MsAsakaiQuality::where('IdInputHarian', $harian->IdInputHarian)->first();
        $asakaiPencapaian = \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi::where('IdInputHarian', $harian->IdInputHarian)->first();
        $asakaiDowntime   = \App\Models\Produksi\Master\MsAsakaiDowntime::where('IdInputHarian', $harian->IdInputHarian)->first();
        $asakaiGsph       = \App\Models\Produksi\Master\MsAsakaiGsph::where('IdInputHarian', $harian->IdInputHarian)->first();
        $asakaiSpot       = \App\Models\Produksi\Master\MsAsakaiSpot::where('IdAsakaiSpot', 'SPOT-'.$idPayung)->first();

        // --- FORCE SET RELATION KE INSTANCE OBJECT ---
        $asakai->setRelation('asakaiMain', $asakaiMain ?? new \App\Models\Produksi\Master\MsAsakaiMain());
        $asakai->setRelation('asakaiSafety', $asakaiSafety ?? new \App\Models\Produksi\Master\MsAsakaiSafety());
        $asakai->setRelation('asakaiQuality', $asakaiQuality ?? new \App\Models\Produksi\Master\MsAsakaiQuality());
        $asakai->setRelation('asakaiPencapaian', $asakaiPencapaian ?? new \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi());
        $asakai->setRelation('asakaiDowntime', $asakaiDowntime ?? new \App\Models\Produksi\Master\MsAsakaiDowntime());
        $asakai->setRelation('asakaiGsph', $asakaiGsph ?? new \App\Models\Produksi\Master\MsAsakaiGsph());
        $asakai->setRelation('asakaiSpot', $asakaiSpot ?? new \App\Models\Produksi\Master\MsAsakaiSpot());

        // --- 2. LOGIKAL RANGE DATA ACCUMULATIVE (MTD) MURNI ---
        $listIdHarianToday = TrsInputHarian::whereDate('TanggalProduksi', $tanggalPilihan)->pluck('IdInputHarian');
        $startOfMonth = \Carbon\Carbon::parse($tanggalPilihan)->startOfMonth()->format('Y-m-d');
        $listIdHarianAccum = TrsInputHarian::whereBetween('TanggalProduksi', [$startOfMonth, $tanggalPilihan])->pluck('IdInputHarian');

        $defaultData = (object)[
            'totalLineE' => 0, 'accumLineE' => 0,
            'totalLineF' => 0, 'accumLineF' => 0,
            'totalLineK' => 0, 'accumLineK' => 0,
            'listIssue' => ''
        ];

        // --- BINDING DATA QUALITY (REPAIR & REJECT AUTO-CALCULATION KETIKA EMPTY) ---
        if ($asakai->asakaiQuality && $asakai->asakaiQuality->exists) {
            $repairData = (object)[
                'totalLineE' => $asakai->asakaiQuality->RepairLineEAct ?? 0, 'accumLineE' => $asakai->asakaiQuality->RepairLineEAcc ?? 0,
                'totalLineF' => $asakai->asakaiQuality->RepairLineFAct ?? 0, 'accumLineF' => $asakai->asakaiQuality->RepairLineFAcc ?? 0,
                'totalLineK' => $asakai->asakaiQuality->RepairLineKAct ?? 0, 'accumLineK' => $asakai->asakaiQuality->RepairLineKAcc ?? 0,
                'listIssue'  => ($asakai->asakaiQuality->RepairIssueLineE ?? '') . "\n" . ($asakai->asakaiQuality->RepairIssueLineF ?? '') . "\n" . ($asakai->asakaiQuality->RepairIssueLineK ?? '')
            ];

            $rejectData = (object)[
                'totalLineE' => $asakai->asakaiQuality->RejectLineEAct ?? 0, 'accumLineE' => $asakai->asakaiQuality->RejectLineEAcc ?? 0,
                'totalLineF' => $asakai->asakaiQuality->RejectLineFAct ?? 0, 'accumLineF' => $asakai->asakaiQuality->RejectLineFAcc ?? 0,
                'totalLineK' => $asakai->asakaiQuality->RejectLineKAct ?? 0, 'accumLineK' => $asakai->asakaiQuality->RejectLineKAcc ?? 0,
                'listIssue'  => ($asakai->asakaiQuality->RejectIssueLineE ?? '') . "\n" . ($asakai->asakaiQuality->RejectIssueLineF ?? '') . "\n" . ($asakai->asakaiQuality->RejectIssueLineK ?? '')
            ];
        } else {
            $repairData = DetailRepair::whereIn('prod_detailrepair.IdInputHarian', $listIdHarianAccum)
                ->join('prod_trsinputharian', 'prod_detailrepair.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineE,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineE,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineF,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineF,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineK,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineK,
                    GROUP_CONCAT(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' THEN CONCAT(prod_msproductionline.NamaProductionLine, ': ', NamaKerusakan, ' (', CAST(prod_detailrepair.Qty AS UNSIGNED), ' PCS)') END SEPARATOR '\n') as listIssue
                ")->first() ?? $defaultData;

            $rejectData = DetailReject::whereIn('prod_detailreject.IdInputHarian', $listIdHarianAccum)
                ->join('prod_trsinputharian', 'prod_detailreject.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineE,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineE,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineF,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineF,
                    SUM(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' AND prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineK,
                    SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineK,
                    GROUP_CONCAT(CASE WHEN DATE(prod_trsinputharian.TanggalProduksi) = '$tanggalPilihan' THEN CONCAT(prod_msproductionline.NamaProductionLine, ': ', NamaKerusakan, ' (', CAST(prod_detailreject.Qty AS UNSIGNED), ' PCS)') END SEPARATOR '\n') as listIssue
                ")->first() ?? $defaultData;
        }

        // --- BINDING DATA PRODUCTIVITY ---
        if ($asakai->asakaiPencapaian && $asakai->asakaiPencapaian->exists) {
            $productivityData = (object)[
                'planE_S1' => $asakai->asakaiPencapaian->LineEPlanS1 ?? 0, 'actE_S1' => $asakai->asakaiPencapaian->LineEActS1 ?? 0,
                'planF_S1' => $asakai->asakaiPencapaian->LineFPlanS1 ?? 0, 'actF_S1' => $asakai->asakaiPencapaian->LineFActS1 ?? 0,
                'planK_S1' => $asakai->asakaiPencapaian->LineKPlanS1 ?? 0, 'actK_S1' => $asakai->asakaiPencapaian->LineKActS1 ?? 0,
                'planE_S2' => $asakai->asakaiPencapaian->LineEPlanS2 ?? 0, 'actE_S2' => $asakai->asakaiPencapaian->LineEActS2 ?? 0,
                'planF_S2' => $asakai->asakaiPencapaian->LineFPlanS2 ?? 0, 'actF_S2' => $asakai->asakaiPencapaian->LineFActS2 ?? 0,
                'planK_S2' => $asakai->asakaiPencapaian->LineKPlanS2 ?? 0, 'actK_S2' => $asakai->asakaiPencapaian->LineKActS2 ?? 0,
            ];
        } else {
            $productivityData = TrsInputHarian::whereIn('prod_trsinputharian.IdInputHarian', $listIdHarianToday)
                ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
                ->selectRaw("
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planE_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actE_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planF_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actF_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planK_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%1%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actK_S1,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planE_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE E%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actE_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planF_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE F%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actF_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(PlanQtyA, 0) ELSE 0 END) as planK_S2,
                    SUM(CASE WHEN UPPER(prod_msproductionline.NamaProductionLine) LIKE '%LINE K%' AND prod_msproductionline.Shift LIKE '%2%' THEN IFNULL(GoodA, 0) ELSE 0 END) as actK_S2
                ")->first();
        }

        // --- C. BINDING DATA DOWNTIME ---
        $downtimeData = new \stdClass();
        $allLines = ['LineE', 'LineF', 'LineK', 'D52VT', 'D26', 'HW'];
        if ($asakai->asakaiDowntime && $asakai->asakaiDowntime->exists) {
            foreach ($allLines as $key) {
                $downtimeData->{'dt'.$key} = $asakai->asakaiDowntime->{"{$key}TodayDTS1"} ?? 0;
                $downtimeData->{'acc'.$key} = $asakai->asakaiDowntime->{"{$key}AccDTS1"} ?? 0;
                $downtimeData->{'dt'.$key.'_S2'} = $asakai->asakaiDowntime->{"{$key}TodayDTS2"} ?? 0;
                $downtimeData->{'acc'.$key.'_S2'} = $asakai->asakaiDowntime->{"{$key}AccDTS2"} ?? 0;
                $downtimeData->{"type".$key."S1"} = $asakai->asakaiDowntime->{"{$key}TipeDTS1"} ?? "M/C";
                $downtimeData->{"type".$key."S2"} = $asakai->asakaiDowntime->{"{$key}TipeDTS2"} ?? "M/C";
                $downtimeData->{"issue".$key."S1"} = $asakai->asakaiDowntime->{"{$key}IssueDTS1"} ?? "";
                $downtimeData->{"issue".$key."S2"} = $asakai->asakaiDowntime->{"{$key}IssueDTS2"} ?? "";
            }
        } else {
            foreach ($allLines as $key) {
                $downtimeData->{'dt'.$key} = 0; $downtimeData->{'acc'.$key} = 0;
                $downtimeData->{'dt'.$key.'_S2'} = 0; $downtimeData->{'acc'.$key.'_S2'} = 0;
                $downtimeData->{"type".$key."S1"} = "M/C"; $downtimeData->{"type".$key."S2"} = "M/C";
                $downtimeData->{"issue".$key."S1"} = ""; $downtimeData->{"issue".$key."S2"} = "";
            }
        }

        // --- D. BINDING DATA GSPH ---
        if ($asakai->asakaiGsph && $asakai->asakaiGsph->exists) {
            $gsphData = (object)[
                'PlanGSPHE_S1' => $asakai->asakaiGsph->GsphPlanLES1 ?? 0, 'AktualGSPHE_S1' => $asakai->asakaiGsph->GsphActLES1 ?? 0,
                'PlanGSPHF_S1' => $asakai->asakaiGsph->GsphPlanLFS1 ?? 0, 'AktualGSPHF_S1' => $asakai->asakaiGsph->GsphActLFS1 ?? 0,
                'PlanGSPHK_S1' => $asakai->asakaiGsph->GsphPlanLKS1 ?? 0, 'AktualGSPHK_S1' => $asakai->asakaiGsph->GsphActLKS1 ?? 0,
                'PlanGSPHE_S2' => $asakai->asakaiGsph->GsphPlanLES2 ?? 0, 'AktualGSPHE_S2' => $asakai->asakaiGsph->GsphActLES2 ?? 0,
                'PlanGSPHF_S2' => $asakai->asakaiGsph->GsphPlanLFS2 ?? 0, 'AktualGSPHF_S2' => $asakai->asakaiGsph->GsphActLFS2 ?? 0,
                'PlanGSPHK_S2' => $asakai->asakaiGsph->GsphPlanLKS2 ?? 0, 'AktualGSPHK_S2' => $asakai->asakaiGsph->GsphActLKS2 ?? 0,
            ];
        } else {
            $gsphData = (object)[];
        }

        // --- TOTAL PRODUKSI MTD ---
        $totalProduksiMTD = TrsInputHarian::whereIn('prod_trsinputharian.IdInputHarian', $listIdHarianAccum)
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->selectRaw("
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineE,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineF,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineK
            ")->first();

        return view('Produksi.report.asakai.edit', compact(
            'harian', 'start', 'end', 'repairData', 'rejectData', 
            'productivityData', 'downtimeData', 'gsphData', 'asakai', 'totalProduksiMTD'
        ));
    }

    public function update(Request $request, $id)
    {
        $idHarianAsli = $id; 
        $tanggal = $request->TanggalProduksi;
        $idPayung = 'ASA-' . str_replace('-', '', $tanggal);

        DB::beginTransaction();
        try {
            // --- 1. ASAKAI MAIN ---
            $linesM = ['LINEE'=>'LE', 'LINEF'=>'LF', 'LINEK'=>'LK', 'ASSYD52VT'=>'D52', 'ASSYD26'=>'D26', 'METALFINISH'=>'Metal'];
            $mDat = ['IdAsakai'=>'MAIN-'.$idPayung, 'IdInputHarian'=>$idHarianAsli, 'TanggalProduksi'=>$tanggal];
            foreach(['S1','S2'] as $s) {
                foreach($linesM as $bl => $db) {
                    $mDat["PlanGlc{$db}{$s}"] = $request->input("PlanGlc{$bl}{$s}", 0);
                    $mDat["PlanTpt{$db}{$s}"] = $request->input("PlanTpt{$bl}{$s}", 0);
                    $mDat["CapReg{$db}{$s}"] = $request->input("CapReg{$bl}{$s}", 0);
                    $mDat["Remarks{$db}{$s}"] = $request->input("Remarks{$bl}{$s}");
                }
            }
            // 🔥 UBAH KUNCIAN JADI ID PAYUNG
            \App\Models\Produksi\Master\MsAsakaiMain::updateOrCreate(['IdAsakai' => 'MAIN-'.$idPayung], $mDat);

            // --- 2. ASAKAI SAFETY ---
            $safetyDataArray = [
                'TanggalProduksi' => $tanggal,
                
                'AccidentTarget'  => $request->input('AccidentTarget', 0), 
                'AccidentAct'     => $request->input('AccidentAct', 0), 
                'AccidentAccum'   => $request->input('AccidentAccum', 0), 
                'AccidentIssue'   => $request->input('AccidentIssue'), 
                'AccidentPIC'     => $request->input('SafetyPIC'),
                
                'InccidentTarget' => $request->input('InccidentTarget', 0), 
                'InccidentAct'    => $request->input('InccidentAct', 0), 
                'InccidentAccum'  => $request->input('InccidentAccum', 0), 
                'InccidentIssue'  => $request->input('InccidentIssue'), 
                'InccidentPIC'    => $request->input('SafetyPIC'),
                
                'TrafficTarget'   => $request->input('TrafficTarget', 0), 
                'TrafficAct'      => $request->input('TrafficAct', 0), 
                'TrafficAccum'    => $request->input('TrafficAccum', 0), 
                'TrafficIssue'    => $request->input('TrafficIssue'), 
                'TrafficPIC'      => $request->input('SafetyPIC'),
                
                'SafetyPic'       => $request->input('SafetyPIC')
            ];

            \App\Models\Produksi\Master\MsAsakaiSafety::updateOrCreate(
                ['IdAsakaiSafety' => 'SAFE-' . $idPayung], 
                $safetyDataArray
            );

            // --- 3. ASAKAI QUALITY ---
            $qDat = [
                'IdAsakaiQuality' => 'QUAL-'.$idPayung, 
                'IdInputHarian'   => $idHarianAsli, 
                'TanggalProduksi' => $tanggal, 
                
                'CustomersTarget' => $request->input('CustomersTarget', 0), 
                'CustomersAct'    => $request->input('CustomersAct', 0), 
                'CustomersAcc'    => $request->input('CustomersAcc', 0), 
                'CustomersIssue'  => $request->input('CustomersIssue'), 
                'CustomersPIC'    => $request->input('CustomersPIC'),
                
                'InternalTarget'  => $request->input('InternalTarget', 0), 
                'InternalAct'     => $request->input('InternalAct', 0), 
                'InternalAcc'     => $request->input('InternalAcc', 0), 
                'InternalIssue'   => $request->input('InternalIssue'), 
                'InternalPIC'     => $request->input('CustomersPIC'), 
                
                'SupplierTarget'  => $request->input('SupplierTarget', 0), 
                'SupplierAct'     => $request->input('SupplierAct', 0), 
                'SupplierAcc'     => $request->input('SupplierAcc', 0), 
                'SupplierIssue'   => $request->input('SupplierIssue'), 
                'SupplierPIC'     => $request->input('CustomersPIC'), 
                
                'RepairPIC'       => $request->input('REPAIRPIC_Global'), 
                'RejectPIC'       => $request->input('REJECTPIC_Global')
            ];

            $linesQ = ['LINEE'=>'LineE', 'LINEF'=>'LineF', 'LINEK'=>'LineK'];
            foreach(['REPAIR','REJECT'] as $t) {
                foreach($linesQ as $bl => $db) {
                    $tt = ucfirst(strtolower($t));
                    $qDat["{$tt}{$db}Act"]   = floatval(str_replace('%', '', $request->input("{$t}{$bl}Act", 0)));
                    $qDat["{$tt}{$db}Acc"]   = floatval(str_replace('%', '', $request->input("{$t}{$bl}Acc", 0)));
                    $qDat["{$tt}Issue{$db}"] = $request->input("{$t}{$bl}Issue");
                    $qDat["{$tt}{$db}PIC"]   = $request->input("{$t}PIC_Global"); // Pastikan ini juga disave
                }
            }
            
            // 🔥 UBAH KUNCIAN JADI ID PAYUNG
            \App\Models\Produksi\Master\MsAsakaiQuality::updateOrCreate(['IdAsakaiQuality' => 'QUAL-'.$idPayung], $qDat);

            // --- 4. ASAKAI PENCAPAIAN PRODUKSI ---
            $prodData = [
                'IdAsakaiPP'      => 'PROD-' . $idPayung, 
                'IdInputHarian'   => $idHarianAsli, 
                'TanggalProduksi' => $tanggal,
                'ProdPicS1'       => $request->input('ProdPicS1'), 
                'ProdPicS2'       => $request->input('ProdPicS2')
            ];
            $linesProd = ['LineE', 'LineF', 'LineK', 'D52Vt' => 'D52VT', 'D26', 'Handwork' => 'HW'];
            foreach(['S1','S2'] as $s) {
                foreach($linesProd as $db => $blade) {
                    $dbKey = is_numeric($db) ? $blade : $db;
                    $prodData["{$dbKey}Plan{$s}"] = $request->input("{$blade}Plan{$s}", 0);
                    $prodData["{$dbKey}Act{$s}"]  = $request->input("{$blade}Act{$s}", 0);
                    $issueCol = ($dbKey == 'D26') ? "D26Issue_{$s}" : "{$dbKey}Issue{$s}";
                    $prodData[$issueCol] = $request->input("{$blade}Issue{$s}");
                }
            }
            // 🔥 UBAH KUNCIAN JADI ID PAYUNG
            \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi::updateOrCreate(['IdAsakaiPP' => 'PROD-'.$idPayung], $prodData);

            // --- 5. ASAKAI DOWNTIME ---
            $dtDat = [
                'IdAsakaiDowntime' => 'DT-'.$idPayung, 
                'IdInputHarian'    => $idHarianAsli, 
                'TanggalProduksi'  => $tanggal,
                'DtPicS1'          => $request->input('DtPicS1'), 
                'DtPicS2'          => $request->input('DtPicS2'),
            ];
            $linesP = ['LineE', 'LineF', 'LineK', 'D52Vt'=>'D52VT', 'D26', 'Handwork'=>'HW'];
            foreach(['S1','S2'] as $s) {
                foreach($linesP as $db => $bl) {
                    $dbK = is_numeric($db) ? $bl : $db;
                    $dtDat["{$dbK}TodayDT{$s}"] = $request->input("{$bl}Dt{$s}", 0);
                    $dtDat["{$dbK}AccDT{$s}"]   = $request->input("{$bl}DtAcc{$s}", 0);
                    $dtDat["{$dbK}TipeDT{$s}"]  = $request->input("{$bl}DtType{$s}", 'M/C');
                    $dtDat["{$dbK}IssueDT{$s}"] = $request->input("{$bl}Issue{$s}");
                }
            }
            // 🔥 UBAH KUNCIAN JADI ID PAYUNG
            \App\Models\Produksi\Master\MsAsakaiDowntime::updateOrCreate(['IdAsakaiDowntime' => 'DT-'.$idPayung], $dtDat);

            // --- 6. ASAKAI GSPH ---
            $gDat = [
                'IdAsakaiGsph'    => 'GSPH-'.$idPayung, 
                'IdInputHarian'   => $idHarianAsli, 
                'TanggalProduksi' => $tanggal
            ];
            $linesG = ['LineE' => 'LE', 'LineF' => 'LF', 'LineK' => 'LK'];
            foreach(['S1','S2'] as $s) {
                $picGlobal = $request->input("GsphPIC{$s}"); 
                foreach($linesG as $bl => $db) {
                    $gDat["GsphTarget{$db}{$s}"] = ($db == 'LE' ? 500 : 550);
                    $gDat["GsphPlan{$db}{$s}"]   = $request->input("{$bl}PlanGsph{$s}", 0);
                    $gDat["GsphAct{$db}{$s}"]    = $request->input("{$bl}ActGsph{$s}", 0);
                    $gDat["GsphIssue{$db}{$s}"]  = $request->input("GsphIssue{$bl}{$s}");
                    $gDat["GsphPic{$db}{$s}"]    = $picGlobal; 
                }
            }
            // 🔥 UBAH KUNCIAN JADI ID PAYUNG
            \App\Models\Produksi\Master\MsAsakaiGsph::updateOrCreate(['IdAsakaiGsph' => 'GSPH-'.$idPayung], $gDat);

            // --- 7. ASAKAI SPOT (🛠️ CLEAN DUAL-CASING ARRAY TO PREVENT SILENT REJECTION) ---
            $idSpot = 'SPOT-' . $idPayung; 
            $sDat = [
                'IdAsakaiSpot'    => $idSpot, 
                'IdInputHarian'   => $idHarianAsli, // Jaga-jaga buat relasi tambahan
                'TanggalProduksi' => $tanggal
            ];
            $picSpotGlobal = $request->input('SpotPIC_Global'); 
            $areasS = ['D52', 'Panel', 'Quarter', 'Front'];

            foreach($areasS as $idx => $a) {
                $sDat["Spot{$a}Target"] = $request->input("SpotTarget{$idx}", 0);
                $sDat["Spot{$a}Plan"]   = $request->input("SpotPlan{$idx}", 0);
                $sDat["Spot{$a}Act"]    = $request->input("SpotAct{$idx}", 0);
                $sDat["Spot{$a}Accum"]  = $request->input("SpotAcc{$idx}", 0); 
                $sDat["Spot{$a}Issue"]  = $request->input("SpotIssue{$idx}");
                $sDat["Spot{$a}Pic"]    = $picSpotGlobal; // Cukup gunakan satu nama field murni
            }

            \App\Models\Produksi\Master\MsAsakaiSpot::updateOrCreate(
                ['IdAsakaiSpot' => $idSpot], 
                $sDat
            );

            DB::commit();
            return redirect()->route('report.asakai.index')->with('success', 'Data Asakai Berhasil Diperbarui');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Oops! Something went wrong: '.$e->getMessage());
        }
    }

    public function show($id)
    {
        // 1. Ambil data Input Harian beserta SEMUA modul asakai yang terkait
        $harian = TrsInputHarian::with([
            'productionLine',
            'asakaiMain',
            'asakaiSafety',
            'asakaiQuality',
            'asakaiPencapaian',
            'asakaiDowntime',
            'asakaiGsph',
            'asakaiSpot'
        ])->findOrFail($id);

        // 2. Kirim ke view show
        return view('Produksi.report.asakai.show', compact('harian'));
    }

    public function destroy($id)
    {
        // 1. Cari data harian buat dapet tanggalnya
        $harian = TrsInputHarian::findOrFail($id);
        $tanggal = $harian->TanggalProduksi;
        $idPayung = 'ASA-' . str_replace('-', '', $tanggal);

        DB::beginTransaction();
        try {
            // 2. Hapus semua modul Asakai terkait tanggal tersebut
            \App\Models\Produksi\Master\MsAsakaiMain::where('TanggalProduksi', $tanggal)->delete();
            \App\Models\Produksi\Master\MsAsakaiQuality::where('IdAsakaiQuality', 'QUAL-' . $idPayung)->delete();
            \App\Models\Produksi\Master\MsAsakaiPencapaianProduksi::where('IdAsakaiPP', 'PROD-' . $idPayung)->delete();
            \App\Models\Produksi\Master\MsAsakaiDowntime::where('IdAsakaiDowntime', 'DT-' . $idPayung)->delete();
            \App\Models\Produksi\Master\MsAsakaiGsph::where('IdAsakaiGsph', 'GSPH-' . $idPayung)->delete();
            
            // 🔥 FIX PRESISI: Tambahkan prefix 'SAFE-' dan 'SPOT-' agar pas dengan primary key di database
            \App\Models\Produksi\Master\MsAsakaiSafety::where('IdAsakaiSafety', 'SAFE-' . $idPayung)->delete();
            \App\Models\Produksi\Master\MsAsakaiSpot::where('IdAsakaiSpot', 'SPOT-' . $idPayung)->delete();

            DB::commit();
            return response()->json([
                'success' => true, 
                'message' => 'Data Asakai ' . date('d/m/Y', strtotime($tanggal)) . ' Berhasil Diperbarui'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportExcel(Request $request)
    {
        $tanggal = $request->date ?? date('Y-m-d');
        $startOfMonth = \Carbon\Carbon::parse($tanggal)->startOfMonth()->format('Y-m-d');
        
        // --- 1. AMBIL LIST ID UNTUK FILTER ---
        $listIdHarianToday = TrsInputHarian::whereDate('TanggalProduksi', $tanggal)->pluck('IdInputHarian');
        $listIdHarianAccum = TrsInputHarian::whereBetween('TanggalProduksi', [$startOfMonth, $tanggal])->pluck('IdInputHarian');

        // --- 2. QUERY TOTAL PRODUKSI MTD (Hanya Kolom A) ---
        $totalProduksiMTD = TrsInputHarian::whereIn('prod_trsinputharian.IdInputHarian', $listIdHarianAccum)
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->selectRaw("
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineE,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineF,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN (IFNULL(GoodA,0) + IFNULL(RepairA,0) + IFNULL(RejectA,0)) ELSE 0 END) as prodLineK
            ")->first();

        // --- 3. QUERY REPAIR & REJECT DATA (Untuk Quality) ---
        $repairData = DetailRepair::whereIn('prod_detailrepair.IdInputHarian', $listIdHarianAccum)
            ->join('prod_trsinputharian', 'prod_detailrepair.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->selectRaw("
                SUM(CASE WHEN prod_trsinputharian.TanggalProduksi = '$tanggal' AND prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineE,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineE,
                SUM(CASE WHEN prod_trsinputharian.TanggalProduksi = '$tanggal' AND prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineF,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineF,
                SUM(CASE WHEN prod_trsinputharian.TanggalProduksi = '$tanggal' AND prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailrepair.Qty ELSE 0 END) as totalLineK,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailrepair.Qty ELSE 0 END) as accumLineK
            ")->first();

        $rejectData = \App\Models\Produksi\Detail\DetailReject::whereIn('prod_detailreject.IdInputHarian', $listIdHarianAccum)
            ->join('prod_trsinputharian', 'prod_detailreject.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->selectRaw("
                SUM(CASE WHEN prod_trsinputharian.TanggalProduksi = '$tanggal' AND prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineE,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE E%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineE,
                SUM(CASE WHEN prod_trsinputharian.TanggalProduksi = '$tanggal' AND prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineF,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE F%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineF,
                SUM(CASE WHEN prod_trsinputharian.TanggalProduksi = '$tanggal' AND prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailreject.Qty ELSE 0 END) as totalLineK,
                SUM(CASE WHEN prod_msproductionline.NamaProductionLine LIKE '%LINE K%' THEN prod_detailreject.Qty ELSE 0 END) as accumLineK
            ")->first();

        // --- 4. QUERY DOWNTIME RAW ---
        $rawDowntime = \App\Models\Produksi\Detail\DetailDowntime::join('prod_trsinputharian', 'prod_detaildowntime.IdInputHarian', '=', 'prod_trsinputharian.IdInputHarian')
            ->join('prod_msproductionline', 'prod_trsinputharian.IdProductionLine', '=', 'prod_msproductionline.IdProductionLine')
            ->whereBetween('prod_trsinputharian.TanggalProduksi', [$startOfMonth, $tanggal])
            ->select('prod_detaildowntime.*', 'prod_msproductionline.NamaProductionLine', 'prod_msproductionline.Shift', 'prod_trsinputharian.TanggalProduksi')
            ->get();

        // --- 5. MAPPING DOWNTIME (Logic yang udah kita benerin) ---
        $downtimeData = new \stdClass();
        $allLines = ['LineE', 'LineF', 'LineK', 'D52VT', 'D26', 'HW'];
        foreach ($allLines as $key) {
            $downtimeData->{'dt'.$key} = 0; $downtimeData->{'acc'.$key} = 0;
            $downtimeData->{'dt'.$key.'_S2'} = 0; $downtimeData->{'acc'.$key.'_S2'} = 0;
            $downtimeData->{"type".$key."S1"} = "M/C"; $downtimeData->{"type".$key."S2"} = "M/C";
            $downtimeData->{"issue".$key."S1"} = ""; $downtimeData->{"issue".$key."S2"} = "";
        }

        foreach ($rawDowntime as $row) {
            $shift = trim($row->Shift ?? ''); 
            $sfx = (str_contains(strtoupper($shift), '2')) ? "S2" : "S1";
            $parts = explode(':', $row->Durasi ?? '00:00:00');
            $menit = (count($parts) >= 2) ? ($parts[0] * 60) + $parts[1] + (($parts[2] ?? 0) / 60) : 0;
            
            $ln = strtoupper($row->NamaProductionLine);
            $k = null;
            if (str_contains($ln, 'LINE E')) $k = 'LineE';
            elseif (str_contains($ln, 'LINE F')) $k = 'LineF';
            elseif (str_contains($ln, 'LINE K')) $k = 'LineK';
            elseif (str_contains($ln, 'D52') || str_contains($ln, 'VT')) $k = 'D52VT';
            elseif (str_contains($ln, 'D26')) $k = 'D26';
            elseif (str_contains($ln, 'HW')) $k = 'HW';

            if ($k) {
                // Accum
                if ($sfx === 'S2') { $downtimeData->{'acc' . $k . "_S2"} += $menit; } 
                else { $downtimeData->{'acc' . $k} += $menit; }
                // Today
                if ($row->TanggalProduksi == $tanggal) {
                    if ($sfx === 'S2') { $downtimeData->{'dt' . $k . "_S2"} += $menit; } 
                    else { $downtimeData->{'dt' . $k} += $menit; }
                    $downtimeData->{"issue{$k}{$sfx}"} .= "- ".($row->Masalah ?? '-')." (".round($menit,1)." min)\n";
                }
            }
        }

        // --- 6. AMBIL DATA ASAKAI UTAMA ---
        $asakai = TrsInputHarian::with(['asakaiMain', 'asakaiQuality', 'asakaiPencapaian', 'asakaiDowntime', 'asakaiSafety', 'asakaiGsph', 'asakaiSpot'])
                ->where('TanggalProduksi', $tanggal)->first();

        // --- 7. PACKING & DOWNLOAD ---
        $data = [
            'tanggal' => $tanggal,
            'asakai' => $asakai,
            'totalProduksiMTD' => $totalProduksiMTD,
            'repairData' => $repairData,
            'rejectData' => $rejectData,
            'downtimeData' => $downtimeData,
        ];

        if (ob_get_contents()) ob_end_clean();

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AsakaiReportExport($data), 
            'Asakai-Report-'.$tanggal.'.xlsx'
        );
    }
}