<?php

namespace App\Http\Controllers\Produksi\Transaksi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// --- MODEL TRANSAKSI ---
use App\Models\Produksi\Transaksi\TrsInputHarian;

// --- MODEL DETAIL (✅ SUDAH DIPINDAHKAN KE FOLDER DETAIL) ---
use App\Models\Produksi\Detail\DetailReject;
use App\Models\Produksi\Detail\DetailIdleTime;
use App\Models\Produksi\Detail\DetailDowntime;
use App\Models\Produksi\Detail\DetailRepair;
use App\Models\Produksi\Detail\DetailPlanScheduleProduksi;

// --- MODEL MASTER ---
use App\Models\Produksi\Master\MsReject;
use App\Models\Produksi\Master\MsIdleTime;
use App\Models\Produksi\Master\MsDowntime;
use App\Models\Produksi\Master\MsProductionLine; 
use App\Models\Produksi\Master\MsItemProduction; 
use App\Models\Produksi\Master\MsKaryawan;       

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InputHarianController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $jabatan = strtolower(trim($user->Jabatan ?? '')); 

        // --- LOGIKA SESSION STICKY FILTER ---
        // 1. Reset filter jika ada parameter reset=1
        if ($request->has('reset')) {
            session()->forget(['filter_date', 'filter_line', 'filter_search']);
            return redirect()->route('inputharian.index');
        }

        // 2. Simpan filter ke session jika ada input dari URL
        if ($request->has('date')) session(['filter_date' => $request->date]);
        if ($request->has('line')) session(['filter_line' => $request->line]);
        if ($request->has('search')) session(['filter_search' => $request->search]);

        // 3. Gunakan filter dari URL (request), jika tidak ada ambil dari session, jika session kosong pakai default
        $tanggal = $request->query('date') ?? session('filter_date', date('Y-m-d'));
        $lineId  = $request->query('line') ?? session('filter_line');
        $search  = $request->query('search') ?? session('filter_search');
        
        // 1. Query Dasar
        $query = TrsInputHarian::with(['productionLine', 'item'])
                    ->whereDate('TanggalProduksi', $tanggal);

        // 2. Filter Jabatan
        $whitelist = ['admin', 'foreman', 'supervisor', 'ppc', 'quality'];
        if (!in_array($jabatan, $whitelist)) {
            $grup = strtoupper(substr($jabatan, -1)); 
            $query->whereHas('productionLine', function($q) use ($grup) {
                $q->where('NamaProductionLine', 'LIKE', '% ' . $grup);
            });
        }

        // 3. Filter Line
        if ($lineId) { $query->where('IdProductionLine', $lineId); }

        // 4. FILTER SEARCH
        if ($search) {
            $query->whereHas('item', function($q) use ($search) {
                $q->where('JobNumber', 'LIKE', '%' . $search . '%')
                ->orWhere('NamaPart', 'LIKE', '%' . $search . '%');
            });
        }

        // 5. Urutan
        $allNextIds = TrsInputHarian::whereDate('TanggalProduksi', $tanggal)
            ->whereNotNull('NextItemId')->pluck('NextItemId')->toArray();
        $nextIdsStr = count($allNextIds) > 0 ? "'" . implode("','", $allNextIds) . "'" : "'0'";

        $query->orderByRaw("CASE 
                WHEN (StatusProses = 'Running' OR StatusProses = 'Stopped') THEN 1 
                WHEN IdInputHarian IN ($nextIdsStr) THEN 2 
                WHEN (StatusProses IS NULL OR StatusProses = '' OR StatusProses = 'Ready') THEN 3 
                WHEN (StatusProses = 'Finished') THEN 4 
                ELSE 5 END ASC")
            ->orderByRaw("CASE WHEN AktualStart IS NULL OR AktualStart = '00:00:00' THEN 1 ELSE 0 END ASC")
            ->orderBy('AktualStart', 'ASC') 
            ->orderBy('updated_at', 'DESC');

        $inputs = $query->get();

        // 6. Transform Data
        $inputs->transform(function($item) {
            $parts = explode('-', $item->IdInputHarian);
            $isManual = (isset($parts[1]) && $parts[1] == 'MAN');
            $idPlan = (isset($parts[1]) && !$isManual) ? trim($parts[1]) : null;
            $idItem = trim($item->IdItemProduksi);

            $planData = null;
            if ($idPlan) {
                $planData = \DB::table('prod_detailplanscheduleproduksi')
                            ->whereRaw("TRIM(IdPlanSchedule) = ?", [$idPlan])
                            ->whereRaw("TRIM(IdItemProduksi) = ?", [$idItem])
                            ->first();
            }

            // ✅ LOGIKA SINKRONISASI: 
            // Jika PlanQtyB di header (database) 0, ambil dari planData (Excel)
            $item->plan_qty_b = ($item->PlanQtyB > 0) ? (float)$item->PlanQtyB : ($planData ? (float)$planData->PlanQtyB : 0);
            
            // Pastikan PlanQtyA juga sinkron
            $item->plan_qty_a = ($item->PlanQtyA > 0) ? (float)$item->PlanQtyA : ($planData ? (float)$planData->PlanQtyA : 0);
            $item->standard_tpt_plan = $planData ? (float)$planData->TPT : 0;
            $item->standard_plan_start = $planData ? $planData->PlanStart : null;
            $item->standard_plan_finish = $planData ? $planData->PlanFinish : null;
            $item->target_loss = $planData ? (float)($planData->UBP ?? 0) + (float)($planData->DTR ?? 0) : 0;
            $item->actual_tpt_val = (float)($item->TPT ?? 0);
            $item->actual_downtime = $item->standard_tpt_plan > 0 ? max(0, $item->actual_tpt_val - $item->standard_tpt_plan) : 0;

            return $item;
        });

        // 🔥 FIX 1: Tambahkan ->values() agar key array keriset dari 0 lagi
        $inputs = $inputs->sortBy(function($item) {
            $lineName = $item->productionLine->NamaProductionLine ?? 'ZZZ';
            $shift = $item->productionLine->Shift ?? '0';
            return $lineName . $shift;
        })->values(); 
        
        $groupedSchedules = $inputs->groupBy('display_id_plan');
        
        // 7. Data Dropdown
        $lineQuery = \App\Models\Produksi\Master\MsProductionLine::where('Status', 1);
        if (!in_array($jabatan, $whitelist)) {
            $grup = strtoupper(substr($jabatan, -1));
            $lineQuery->where('NamaProductionLine', 'LIKE', '% ' . $grup);
        }
        
        $lines = $lineQuery
            ->orderBy('NamaProductionLine', 'ASC')
            ->orderByRaw("CAST(SUBSTRING_INDEX(Shift, ' ', -1) AS UNSIGNED) ASC")
            ->get();
        $selectedLine = $lineId ? \App\Models\Produksi\Master\MsProductionLine::find($lineId) : null;
        $isQC = in_array($jabatan, ['quality', 'qc']);
        $itemOptions = \App\Models\Produksi\Master\MsItemProduction::where('Status', 1)->orderBy('JobNumber', 'asc')->get();

        return view('Produksi.inputharian.index', [
            'inputs'       => $inputs,
            'lines'        => $lines,
            'tanggal'      => $tanggal,
            'selectedLine' => $selectedLine,
            'isQC'         => $isQC,
            'item'         => $itemOptions,
            'search'       => $search // 🔥 FIX 2: Lempar $search ke Blade biar gak lost
        ]);
    }

    private function authorizeLeader(TrsInputHarian $input)
    {
        $user = auth()->user();
        $jabatan = strtolower(trim($user->Jabatan));
        $whitelist = ['admin', 'supervisor', 'ppc', 'quality', 'foreman'];

        if (!in_array($jabatan, $whitelist)) {
            $grup = strtoupper(substr($jabatan, -1)); 
            $line = $input->productionLine; 
            $namaLine = strtoupper($line->NamaProductionLine);

            if (!$line || !\Illuminate\Support\Str::endsWith($namaLine, ' ' . $grup)) {
                abort(403, 'Maaf, Anda hanya diizinkan untuk mengelola data untuk Grup ' . $grup);
            }
        }
    }


    public function storeExtra(Request $request)
    {
        $request->validate([
            // Wajib diisi dan ID-nya HARUS beneran ada di tabel master yang bersangkutan
            'IdProductionLine' => 'required|exists:prod_msproductionline,IdProductionLine',
            // 🔥 Ganti 'id' menjadi 'IdItemProduksi' (sesuaikan dengan nama kolom di database lu)
            'IdItemProduksi'   => 'required|exists:prod_msitemproduction,IdItemProduksi', 
            'TanggalProduksi'  => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            $today = date('Ymd');
            $prefix = "IH-MAN-" . $today;
            $count = TrsInputHarian::where('IdInputHarian', 'LIKE', $prefix . '%')->count() + 1;
            $newId = $prefix . "-" . str_pad($count, 3, '0', STR_PAD_LEFT);

            TrsInputHarian::create([
                'IdInputHarian'    => $newId,
                'IdPlanSchedule'   => null,
                'IdProductionLine' => $request->IdProductionLine,
                'IdItemProduksi'   => $request->IdItemProduksi,
                'TanggalProduksi'  => $request->TanggalProduksi ?? date('Y-m-d'),
                'PlanQtyA'         => 0,
                'PlanQtyB'         => 0,
                'PlanGSPH'         => 0,
                'StatusProses'     => 'Ready',
                'create_by'        => auth()->user()->NamaKaryawan ?? 'Leader',
            ]);

            DB::commit();
            return back()->with('success', 'Additional Plan Saved Successfully');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->withErrors(['error' => 'Terjadi Kesalahan' . $e->getMessage()]);
        }
    }

    // ==========================================
    // BAGIAN UPDATE (TOMBOL SAVE - RUMUS LENGKAP)
    // ==========================================
    public function update(Request $request, $id)
    {
        try {
            $input = TrsInputHarian::with('detailPlan')->findOrFail($id);

            // 🔥 2. KUNCI AKSES (Mencegah operator grup sebelah ngacak-ngacak data)
            $this->authorizeLeader($input);
            
            $tglProduksi = $input->TanggalProduksi;

            $toDateTime = function($jam) use ($tglProduksi) {
                if (!$jam || $jam == '00:00:00') return null;
                if (strlen($jam) > 8) return $jam; 
                return $tglProduksi . ' ' . $jam;
            };

            // 1. Ambil Total DT & Idle dari tabel detail
            $totalDowntimeDetail = DB::table('prod_detaildowntime')->where('IdInputHarian', $id)->sum(DB::raw('TIME_TO_SEC(Durasi)')) / 60;
            $durasiIdle = DB::table('prod_detailidletime')->where('IdInputHarian', $id)->sum(DB::raw('TIME_TO_SEC(Durasi)')) / 60;

            $timeBreakTime = $request->TimeBreakTime ?? 0;
            $typeBreakTime = ($timeBreakTime == 15) ? 'Break' : (($timeBreakTime == 40 || $timeBreakTime == 45) ? 'Istirahat' : null);

            // 2. Ambil Nilai Repair & Reject Eksisting dari Database (Agar tidak ter-reset)
            $currentRepairA = (float)($input->RepairA ?? 0);
            $currentRepairB = (float)($input->RepairB ?? 0);
            $currentRejectA = (float)($input->RejectA ?? 0);
            $currentRejectB = (float)($input->RejectB ?? 0);

            // 3. Kalkulasi Qty Aktual (Good + Repair + Reject)
            $aktualQtyA = ($request->GoodA ?? 0) + $currentRepairA + $currentRejectA;
            $aktualQtyB = ($request->GoodB ?? 0) + $currentRepairB + $currentRejectB;
            $totalAktualQty = $aktualQtyA + $aktualQtyB;

            $startFinal = $toDateTime($request->AktualStart);
            $finishFinal = $toDateTime($request->AktualFinish);
            $durasiMenit = 0; $aktualWorkTime = 0;

            if ($startFinal && $finishFinal) {
                $start = \Carbon\Carbon::parse($startFinal);
                $finish = \Carbon\Carbon::parse($finishFinal);
                if ($finish->lessThan($start)) {
                    return response()->json(['success' => false, 'message' => 'Waktu selesai tidak boleh kurang dari waktu mulai!'], 422);
                }
                $durasiMenit = abs($start->diffInMinutes($finish)); 
                $aktualWorkTime = max(0, $durasiMenit - $timeBreakTime);
            }

            // 4. Kalkulasi TPT & Downtime
            $tptAktual = max(0, $aktualWorkTime - $durasiIdle);
            
            // Tarik nilai TPT, UBP, dan DTR dari Plan
            $tptPlan = (float)($input->detailPlan->TPT ?? 0);
            $ubpPlan = (float)($input->detailPlan->UBP ?? 0);
            $dtrPlanTarget = (float)($input->detailPlan->DTR ?? 0);

            // Fallback jika relasi detailPlan gagal terload
            if ($tptPlan <= 0) {
                $parts = explode('-', $id);
                $idPlan = $parts[1] ?? null;
                $planDb = DB::table('prod_detailplanscheduleproduksi')
                            ->where('IdPlanSchedule', $idPlan)
                            ->where('IdItemProduksi', $input->IdItemProduksi)
                            ->first();
                if ($planDb) {
                    $tptPlan = (float)$planDb->TPT;
                    $ubpPlan = (float)$planDb->UBP;
                    $dtrPlanTarget = (float)$planDb->DTR;
                }
            }

            // 🔥 FIX RUMUS: Downtime Murni = TPT Actual - TPT Plan - UBP - DTR
            $totalDowntimeFinal = max(0, $tptAktual - $tptPlan - $ubpPlan - $dtrPlanTarget); 
            
            $diesChange = (float)($input->DiesChange ?? 0);
            $earlyCheck = (float)($input->EarlyCheck ?? 0);
            $totalUchi = $diesChange + $earlyCheck;
            $pressTime = max(0, $tptAktual - $totalDowntimeFinal - $totalUchi);

            // 5. Kalkulasi Budget DTR
            $remainingDTR = $dtrPlanTarget - $totalDowntimeFinal;

            // 6. Monitoring & Rates
            $lineMonitoring = ($totalAktualQty > 0) ? ($pressTime * 60) / $totalAktualQty : 0;
            $planQtyTotal = (float)($input->PlanQtyA + $input->PlanQtyB);
            $lkhCalculation = ($planQtyTotal > 0) ? ($aktualWorkTime * 60) / $planQtyTotal : 0;

            $goodTotal = (float)($request->GoodA ?? 0) + (float)($request->GoodB ?? 0);
            $passRate = ($totalAktualQty > 0) ? ($goodTotal / $totalAktualQty) * 100 : 0;
            $repairRate = ($totalAktualQty > 0) ? (($currentRepairA + $currentRepairB) / $totalAktualQty) * 100 : 0;
            $rejectRate = ($totalAktualQty > 0) ? (($currentRejectA + $currentRejectB) / $totalAktualQty) * 100 : 0;

            // 7. OEE Calculation
            $availability = ($tptAktual > 0) ? ($pressTime / $tptAktual) * 100 : 0;
            $performance = ($planQtyTotal > 0) ? ($totalAktualQty / $planQtyTotal) * 100 : 0;
            $qualityRate = $passRate; 
            $oee = ($availability / 100) * ($performance / 100) * ($qualityRate / 100) * 100;
            $aktualGSPH = ($aktualWorkTime > 0) ? ($totalAktualQty / ($aktualWorkTime / 60)) : 0;

            // 8. EXECUTE UPDATE
            $input->update([
                'GoodA' => $request->GoodA ?? 0, 
                'GoodB' => $request->GoodB ?? 0,
                'RepairA' => $currentRepairA, 
                'RepairB' => $currentRepairB,
                'RejectA' => $currentRejectA,
                'RejectB' => $currentRejectB,
                'AktualQtyA' => round($aktualQtyA, 2), 
                'AktualQtyB' => round($aktualQtyB, 2),
                'AktualStart' => $startFinal, 
                'AktualFinish' => $finishFinal,
                'TotalProses' => round($durasiMenit, 2), 
                'AktualWorkTime' => round($aktualWorkTime, 2),
                'TPT' => round($tptAktual, 2), 
                'DTR' => round($remainingDTR, 2),
                'TotalUchi' => round($totalUchi, 2), 
                'TotalDowntime' => round($totalDowntimeFinal, 2),
                'PressTime' => round($pressTime, 2), 
                'LineMonitoring' => round($lineMonitoring, 2),
                'LKHCalculation' => round($lkhCalculation, 2), 
                'PassRate' => round($passRate, 2),
                'RepairRate' => round($repairRate, 2), 
                'RejectRate' => round($rejectRate, 2),
                'Availability' => round($availability, 2), 
                'Performance' => round($performance, 2),
                'QualityRate' => round($qualityRate, 2), 
                'OEE' => round($oee, 2),
                'AktualGSPH' => round($aktualGSPH), 
                'TimeBreakTime' => $timeBreakTime,
                'TypeBreakTime' => $typeBreakTime, 
                'update_by' => auth()->user()->NamaKaryawan ?? 'System',
            ]);

            return response()->json(['success' => true]);

        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi Kesalahan ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // BAGIAN UPDATE STATUS (KHUSUS UJI COBA 17:50 - 2 SHIFT)
    // ==========================================
    public function updateStatus(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $input = DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->first();
            
            if (!$input) {
                return response()->json(['success' => false, 'message' => 'Tidak ditemukan.'], 404);
            }

            $parts = explode('-', $input->IdInputHarian);
            $idPlanAsli = isset($parts[1]) ? $parts[1] : str_replace('IH-', '', $input->IdInputHarian);

            // KUNCI UTAMA: Ambil Tanggal Asli Produksi dari Baris Database
            $tgl = $input->TanggalProduksi; 
            $action = $request->action;
            $autoOper = $request->auto_oper;

            // Ambil jam dari request (HH:mm:ss), jika kosong baru fallback ke jam sistem saat ini
            $requestTime = $request->time; 
            if (!$requestTime) {
                $requestTime = now()->format('H:i:s');
            }

            // 🔥 PENGUNCIAN BACKDATE SAKTI: Gabungkan TANGGAL PRODUKSI asli dengan JAM AKTUAL
            $fullDateTime = $tgl . ' ' . $requestTime;

            // ==========================================
            // 1. VALIDASI KESEIMBANGAN DOWNTIME
            // ==========================================
            if ($action === 'finish') {

                $currentGoodA = (float)($request->good_a ?? $input->GoodA);

                // Hitung ulang Repair A langsung dari tabel detail
                $dbRepairA = DB::table('prod_detailrepair')
                                ->where('IdInputHarian', $id)
                                ->sum('RepairA');

                // Hitung ulang Reject A langsung dari tabel detail
                $dbRejectA = DB::table('prod_detailreject')
                                ->where('IdInputHarian', $id)
                                ->sum('RejectA');

                $totalHasilA = $currentGoodA + $dbRepairA + $dbRejectA;
                $totalPlan = (float)($input->PlanQtyA + $input->PlanQtyB);

                if (round($totalHasilA, 2) != round($totalPlan, 2)) {
                    return response()->json([
                        'success' => false, 
                        'message' => "Qty Belum Seimbang! Plan: $totalPlan, Realisasi Sisi A: $totalHasilA. (Lengkapi Detail Repair/Reject)"
                    ], 422);
                }

                // --- VALIDASI DOWNTIME AMAN BACKDATE ---
                $startString = (str_contains($input->AktualStart, ' ')) ? $input->AktualStart : $tgl . ' ' . $input->AktualStart;
                $startTime = \Carbon\Carbon::parse($startString);
                $finishTime = \Carbon\Carbon::parse($fullDateTime);
                
                $durasiTotalMenit = abs($startTime->diffInSeconds($finishTime)) / 60;
                $timeBreak = (float)($request->time_break ?? $input->TimeBreakTime ?? 0);
                $tptActual = max(0, $durasiTotalMenit - $timeBreak);
                
                // 🔥 FIX: Tarik TPT, UBP, dan DTR sekaligus
                $planDb = DB::table('prod_detailplanscheduleproduksi')
                            ->where('IdPlanSchedule', $idPlanAsli)
                            ->where('IdItemProduksi', $input->IdItemProduksi)
                            ->first();

                $tptPlan = $planDb ? (float)$planDb->TPT : 0;
                $ubpPlan = $planDb ? (float)$planDb->UBP : 0;
                $dtrPlan = $planDb ? (float)$planDb->DTR : 0;

                // 🔥 FIX RUMUS BARU: Kurangin juga sama UBP dan DTR
                $gap = max(0, $tptActual - $tptPlan - $ubpPlan - $dtrPlan);

                $totalDetailDT = DB::table('prod_detaildowntime')
                                    ->where('IdInputHarian', $id)
                                    ->sum(DB::raw('TIME_TO_SEC(Durasi)')) / 60;

                if ($gap > 0.5 && (abs($totalDetailDT - $gap) > 0.5)) {
                    return response()->json([
                        'success' => false, 
                        'message' => "Downtime masih belum seimbang! Actual TPT: " . round($tptActual, 1) . " mnt, TPT Plan: " . round($tptPlan, 1) . " mnt, UBP: " . round($ubpPlan, 1) . " mnt, DTR: " . round($dtrPlan, 1) . " mnt. Lose Time Murni: " . round($gap, 1) . " mnt. Input DT: " . round($totalDetailDT, 1) . " mnt."
                    ], 422);
                }
            }

            // ==========================================
            // 2. EKSEKUSI UPDATE STATUS
            // ==========================================
            if ($action === 'start') {
                // Jika kosong/00:00:00 gunakan fullDateTime (Tanggal Produksi + Jam Sekarang)
                $finalStart = (empty($input->AktualStart) || $input->AktualStart == '00:00:00' || $input->AktualStart == '00:00') ? $fullDateTime : $input->AktualStart;
                
                DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->update([
                    'StatusProses' => 'Running',
                    'AktualStart' => $finalStart,
                    'updated_at' => now()
                ]);
            } 
            elseif ($action === 'stop' || $action === 'finish') {
                $currentGoodA = (float)($request->good_a ?? $input->GoodA);
                $currentGoodB = (float)($request->good_b ?? $input->GoodB);
                
                $startString = (str_contains($input->AktualStart, ' ')) ? $input->AktualStart : $tgl . ' ' . $input->AktualStart;
                $startTime = \Carbon\Carbon::parse($startString);
                $finishTime = \Carbon\Carbon::parse($fullDateTime);
                $durasiMenit = abs($startTime->diffInSeconds($finishTime)) / 60;
                
                $timeBreak = (float)($request->time_break ?? $input->TimeBreakTime ?? 0);
                $aktualWorkTime = max(0, $durasiMenit - $timeBreak);

                $progA = $currentGoodA + (float)($input->RepairA ?? 0) + (float)($input->RejectA ?? 0);
                $progB = $currentGoodB + (float)($input->RepairB ?? 0) + (float)($input->RejectB ?? 0);
                $totalAktual = $progA + $progB;

                $newPassRate = ($totalAktual > 0) ? (($currentGoodA + $currentGoodB) / $totalAktual) * 100 : 0;
                $newRepairRate = ($totalAktual > 0) ? (((float)$input->RepairA + (float)$input->RepairB) / $totalAktual) * 100 : 0;
                $newRejectRate = ($totalAktual > 0) ? (((float)$input->RejectA + (float)$input->RejectB) / $totalAktual) * 100 : 0;

                if ($action === 'stop' && $autoOper == 1) {
                    $line = DB::table('prod_msproductionline')->where('IdProductionLine', $input->IdProductionLine)->first();
                    preg_match('!\d+!', $line->Shift, $matches);
                    $nextShiftNum = (isset($matches[0]) && $matches[0] == 1) ? 2 : 1;
                    $nextLine = DB::table('prod_msproductionline')
                        ->where('NamaProductionLine', 'LIKE', '%' . trim($line->NamaProductionLine) . '%')
                        ->where('Shift', 'LIKE', '%Shift ' . $nextShiftNum . '%')
                        ->first();

                    if ($nextLine) {
                        if ($totalAktual == 0) {
                            DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->update([
                                'IdProductionLine' => $nextLine->IdProductionLine,
                                'updated_at' => now()
                            ]);
                        } else {
                            $newIdPlan = $idPlanAsli . "-S" . $nextShiftNum . "-" . rand(10,99);
                            $newIdHarian = "IH-" . $newIdPlan . "-0";

                            DB::table('prod_trsplanscheduleproduction')->insert([
                                'IdPlanSchedule'   => $newIdPlan,
                                'IdProductionLine' => $nextLine->IdProductionLine,
                                'IdKaryawan'       => $input->NPK ?? null, // Fallback karena NamaPIC diubah jd IdKaryawan
                                'TanggalProduksi'  => $input->TanggalProduksi,
                                'create_by'        => Auth::user()->NamaKaryawan ?? 'System Auto',
                                'created_at'       => now()
                            ]);

                            $oldDetail = DB::table('prod_detailplanscheduleproduksi')->where('IdPlanSchedule', $idPlanAsli)->where('IdItemProduksi', $input->IdItemProduksi)->first();
                            if ($oldDetail) {
                                $dataDetail = (array)$oldDetail;
                                $dataDetail['IdPlanSchedule'] = $newIdPlan;
                                $dataDetail['PlanQtyA'] = max(0, (float)$input->PlanQtyA - $progA);
                                $dataDetail['PlanQtyB'] = max(0, (float)$input->PlanQtyB - $progB);
                                $dataDetail['created_at'] = now();
                                DB::table('prod_detailplanscheduleproduksi')->insert($dataDetail);
                            }

                            DB::table('prod_trsinputharian')->insert([
                                'IdInputHarian'   => $newIdHarian,
                                'IdProductionLine'=> $nextLine->IdProductionLine,
                                'IdItemProduksi'  => $input->IdItemProduksi,
                                'TanggalProduksi' => $input->TanggalProduksi,
                                'PlanQtyA'        => max(0, (float)$input->PlanQtyA - $progA),
                                'PlanQtyB'        => max(0, (float)$input->PlanQtyB - $progB),
                                'StatusProses'    => 'Ready',
                                'create_by'       => Auth::user()->NamaKaryawan ?? 'Operator Auto',
                                'created_at'      => now()
                            ]);
                            
                            DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->update([
                                'PlanQtyA' => $progA, 'PlanQtyB' => $progB,
                                'StatusProses' => 'Stopped',
                                'AktualFinish' => $fullDateTime,
                                'TotalProses' => round($durasiMenit, 2),
                                'AktualWorkTime' => round($aktualWorkTime, 2),
                                'TPT' => round($aktualWorkTime, 2), 
                                'GoodA' => $currentGoodA, 'GoodB' => $currentGoodB,
                                'AktualQtyA' => $progA, 'AktualQtyB' => $progB,
                                'PassRate' => round($newPassRate, 2),
                                'RepairRate' => round($newRepairRate, 2),
                                'RejectRate' => round($newRejectRate, 2),
                                'updated_at' => now()
                            ]);
                        }
                    }
                } else {
                    DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->update([
                        'StatusProses' => ($action === 'finish') ? 'Finished' : 'Stopped',
                        'AktualFinish' => $fullDateTime,
                        'TotalProses' => round($durasiMenit, 2),
                        'AktualWorkTime' => round($aktualWorkTime, 2),
                        'TPT' => round($aktualWorkTime, 2),
                        'GoodA' => $currentGoodA, 'GoodB' => $currentGoodB,
                        'AktualQtyA' => $progA, 'AktualQtyB' => $progB,
                        'PassRate' => round($newPassRate, 2),
                        'RepairRate' => round($newRepairRate, 2),
                        'RejectRate' => round($newRejectRate, 2),
                        'updated_at' => now()
                    ]);
                }
            }

            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi Kesalahan' . $e->getMessage()], 500);
        }
    }

    public function operMassalOtomatis(Request $request) 
    {
        DB::beginTransaction();
        try {
            $idLine = $request->id_line;
            $tgl = $request->tanggal;

            $line = DB::table('prod_msproductionline')->where('IdProductionLine', $idLine)->first();
            preg_match('!\d+!', $line->Shift, $matches);
            $nextShiftNum = (isset($matches[0]) && $matches[0] == 1) ? 2 : 1;
            
            $nextLine = DB::table('prod_msproductionline')
                ->where('NamaProductionLine', 'LIKE', '%' . trim($line->NamaProductionLine) . '%')
                ->where('Shift', 'LIKE', '%Shift ' . $nextShiftNum . '%')
                ->first();

            if (!$nextLine) throw new \Exception("Target Shift not found.");

            $itemsToMove = DB::table('prod_trsinputharian')
                ->where('IdProductionLine', $idLine)
                ->whereDate('TanggalProduksi', $tgl)
                ->where('StatusProses', '!=', 'Finished')
                ->get();

            if ($itemsToMove->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Tidak ada data produksi yang perlu dipindah.']);
            }

            foreach ($itemsToMove as $index => $item) {
                $progA = (float)($item->GoodA ?? 0) + (float)($item->RepairA ?? 0) + (float)($item->RejectA ?? 0);
                $progB = (float)($item->GoodB ?? 0) + (float)($item->RepairB ?? 0) + (float)($item->RejectB ?? 0);
                $totalProgress = $progA + $progB;
                $totalPlan = (float)$item->PlanQtyA + (float)$item->PlanQtyB;

                if ($totalProgress >= $totalPlan && $totalPlan > 0) continue;

                // Ambil ID Plan dari string IH-PSXXX-X
                $parts = explode('-', $item->IdInputHarian);
                $idPlanAsli = isset($parts[1]) ? $parts[1] : str_replace('IH-', '', $item->IdInputHarian);

                // --- 1. LOGIC REVISI (Update Header Schedule Asli) ---
                $header = DB::table('prod_trsplanscheduleproduction')->where('IdPlanSchedule', $idPlanAsli)->first();
                if ($header) {
                    preg_match('/\d+/', $header->Status, $matchesStatus);
                    $nextRev = (isset($matchesStatus[0]) ? (int)$matchesStatus[0] : 0) + 1;
                    
                    DB::table('prod_trsplanscheduleproduction')->where('IdPlanSchedule', $idPlanAsli)->update([
                        'IdProductionLine' => $nextLine->IdProductionLine, // Update Line-nya
                        'Status' => "Revisi " . $nextRev,
                        'update_by' => Auth::user()->NamaKaryawan ?? 'System Oper'
                    ]);
                }

                if ($totalProgress > 0) {
                    // --- 2. KASUS DUPLICATE DI INPUT HARIAN ---
                    $newIdHarian = "IH-" . $idPlanAsli . "-S" . $nextShiftNum . "-" . $index;
                    
                    DB::table('prod_trsinputharian')->insert([
                        'IdInputHarian'    => $newIdHarian,
                        'IdProductionLine' => $nextLine->IdProductionLine,
                        'IdItemProduksi'   => $item->IdItemProduksi,
                        'TanggalProduksi'  => $tgl,
                        'PlanQtyA'         => max(0, (float)$item->PlanQtyA - $progA),
                        'PlanQtyB'         => max(0, (float)$item->PlanQtyB - $progB),
                        'StatusProses'     => 'Ready',
                        'create_by'        => Auth::user()->NamaKaryawan ?? 'System Massal',
                        'created_at'       => now()
                    ]);

                    // Stop yang lama
                    DB::table('prod_trsinputharian')->where('IdInputHarian', $item->IdInputHarian)->update([
                        'PlanQtyA' => $progA, 'PlanQtyB' => $progB,
                        'StatusProses' => 'Stopped', 'AktualFinish' => now()
                    ]);
                } else {
                    // --- 3. KASUS PINDAH TOTAL ---
                    DB::table('prod_trsinputharian')->where('IdInputHarian', $item->IdInputHarian)->update([
                        'IdProductionLine' => $nextLine->IdProductionLine
                    ]);
                }
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Pergantian shift massal berhasil dilakukan.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi Kesalahan' . $e->getMessage()]);
        }
    }

    public function operManual(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $input = TrsInputHarian::findOrFail($id);
            $progA = (float)$input->GoodA + (float)$input->RepairA + (float)$input->RejectA;
            $progB = (float)$input->GoodB + (float)$input->RepairB + (float)$input->RejectB;
            $totalProgress = $progA + $progB;
            $totalPlan = (float)$input->PlanQtyA + (float)$input->PlanQtyB;

            $line = DB::table('prod_msproductionline')->where('IdProductionLine', $input->IdProductionLine)->first();
            preg_match('!\d+!', $line->Shift, $matches);
            $nextShiftNum = (isset($matches[0]) && $matches[0] == 1) ? 2 : 1;
            
            $nextLine = DB::table('prod_msproductionline')
                ->where('NamaProductionLine', 'LIKE', '%' . trim($line->NamaProductionLine) . '%')
                ->where('Shift', 'LIKE', '%Shift ' . $nextShiftNum . '%')->first();

            if (!$nextLine) throw new \Exception("Jalur Produksi Tidak Ditemukan");

            // Ambil ID Plan
            $idPlanAsli = isset(explode('-', $input->IdInputHarian)[1]) ? explode('-', $input->IdInputHarian)[1] : $input->IdPlanSchedule;

            // --- 1. UPDATE REVISI DI SCHEDULE ---
            $header = DB::table('prod_trsplanscheduleproduction')->where('IdPlanSchedule', $idPlanAsli)->first();
            if ($header) {
                preg_match('/\d+/', $header->Status, $matchesStatus);
                $nextRev = (isset($matchesStatus[0]) ? (int)$matchesStatus[0] : 0) + 1;

                DB::table('prod_trsplanscheduleproduction')->where('IdPlanSchedule', $idPlanAsli)->update([
                    'IdProductionLine' => $nextLine->IdProductionLine,
                    'Status' => "Revisi " . $nextRev,
                    'update_by' => Auth::user()->NamaKaryawan ?? 'System'
                ]);
            }

            if ($totalProgress > 0) {
                // --- 2. DUPLICATE INPUT HARIAN ---
                $newIdHarian = "IH-" . $idPlanAsli . "-S" . $nextShiftNum . "-" . rand(10,99);
                TrsInputHarian::create([
                    'IdInputHarian' => $newIdHarian,
                    'IdProductionLine' => $nextLine->IdProductionLine,
                    'IdItemProduksi' => $input->IdItemProduksi,
                    'TanggalProduksi' => $input->TanggalProduksi,
                    'PlanQtyA' => max(0, (float)$input->PlanQtyA - $progA),
                    'PlanQtyB' => max(0, (float)$input->PlanQtyB - $progB),
                    'StatusProses' => 'Ready',
                    'create_by' => Auth::user()->NamaKaryawan ?? 'Operator'
                ]);

                $input->update(['PlanQtyA' => $progA, 'PlanQtyB' => $progB, 'StatusProses' => 'Stopped', 'AktualFinish' => now()]);
            } else {
                // --- 3. PINDAH TOTAL ---
                $input->update(['IdProductionLine' => $nextLine->IdProductionLine]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Oper Manual Berhasil']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function setNext(Request $request)
    {
        try {
            $item = TrsInputHarian::findOrFail($request->currentId);
            $item->update(['NextItemId' => $request->nextId]);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function detailReject($id)
    {
        $input = TrsInputHarian::with('item')->findOrFail($id);
        
        // 1. Tentukan status Quality
        $jabatan = strtolower(auth()->user()->Jabatan);
        $isQuality = str_contains($jabatan, 'quality') || str_contains($jabatan, 'qc');

        $masterReject = \App\Models\Produksi\Master\MsReject::where('Status', 1)->get();
        $details = DetailReject::where('IdInputHarian', $id)->get();

        // 2. Kirim $isQuality ke compact
        return view('Produksi.inputharian.detail_reject', compact('input', 'masterReject', 'details', 'isQuality'));
    }

    public function storeDetailReject(Request $request, $id)
    {
        \DB::beginTransaction();
        try {
            $userKaryawan = \Auth::user()->NamaKaryawan ?? 'System';

            // 1. Bersihkan data lama
            \DB::table('prod_detailreject')->where('IdInputHarian', $id)->delete();

            $totalRejectA = 0; 
            $totalRejectB = 0;
            $dataInsert = [];

            if ($request->has('IdReject')) {
                foreach ($request->IdReject as $key => $valRejectDropdown) {
                    if (empty($valRejectDropdown)) continue;

                    $qtyA = (float)($request->QtyRejectA[$key] ?? 0);
                    $qtyB = (float)($request->QtyRejectB[$key] ?? 0);

                    if ($qtyA > 0 || $qtyB > 0) {
                        
                        // Logika Tipe Reject
                        if ($valRejectDropdown === 'Lain-lain') {
                            $finalIdReject = 'RPJ-LAIN';
                            $finalTipeReject = $request->JenisLain[$key] ?? 'Lain-lain'; 
                        } else {
                            $finalIdReject = $valRejectDropdown;
                            $m = \DB::table('prod_msreject')->where('IdReject', $valRejectDropdown)->first();
                            $finalTipeReject = $m ? $m->TipeReject : $valRejectDropdown;
                        }

                        // Logika Nama Kerusakan
                        $valNama = $request->NamaKerusakan[$key] ?? null;
                        $finalNama = ($valNama === 'Lain-lain') ? ($request->NamaLain[$key] ?? 'Lain-lain') : ($valNama ?? '-');

                        // 2. GENERATE ID MANUAL & AMBIL IdItemProduksi
                        $inputHarianData = \DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->first();
                        $idItem = $inputHarianData->IdItemProduksi; 

                        $uniqueId = $id . '-' . $finalIdReject . '-' . bin2hex(random_bytes(2));

                        $dataInsert[] = [
                            'id'            => $uniqueId, // PK 1
                            'IdInputHarian' => $id,       // PK 2
                            'IdItemProduksi'=> $idItem,   // PK 3
                            'IdReject'      => $finalIdReject, // PK 4
                            'TipeReject'    => $finalTipeReject,
                            'NamaKerusakan' => $finalNama,
                            'Qty'           => $qtyA + $qtyB,
                            'RejectA'       => $qtyA,
                            'RejectB'       => $qtyB,
                            'NoMasalah'     => $request->NoMasalah[$key] ?? null,
                            'Penyebab'      => $request->Penyebab[$key] ?? null,
                            'CounterMeasure'=> $request->CounterMeasure[$key] ?? null,
                            'AreaProblem'   => $request->AreaProblem[$key] ?? null,
                            'create_by'     => $userKaryawan,
                            'update_by'     => $userKaryawan,
                            'created_at'    => now(),
                            'updated_at'    => now()
                        ];
                        
                        $totalRejectA += $qtyA;
                        $totalRejectB += $qtyB;
                    }
                }
            }

            // 3. Insert Massal ke Detail
            if (!empty($dataInsert)) {
                \DB::table('prod_detailreject')->insert($dataInsert);
            }

            // 4. Update Header OEE secara paksa (Bypass Fillable)
            $inputHarian = \DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->first();
            
            if ($inputHarian) {
                $aktualQtyA = (float)($inputHarian->GoodA ?? 0) + (float)($inputHarian->RepairA ?? 0) + $totalRejectA;
                $aktualQtyB = (float)($inputHarian->GoodB ?? 0) + (float)($inputHarian->RepairB ?? 0) + $totalRejectB;
                $totalAktualQty = $aktualQtyA + $aktualQtyB;
                $planQtyTotal = (float)($inputHarian->PlanQtyA ?? 0) + (float)($inputHarian->PlanQtyB ?? 0);
                
                $passRate = ($totalAktualQty > 0) ? (((float)$inputHarian->GoodA + (float)$inputHarian->GoodB) / $totalAktualQty) * 100 : 0;
                $rejectRate = ($totalAktualQty > 0) ? (($totalRejectA + $totalRejectB) / $totalAktualQty) * 100 : 0;
                
                $availability = ($inputHarian->TPT > 0) ? ($inputHarian->PressTime / $inputHarian->TPT) * 100 : 0;
                $performance = ($planQtyTotal > 0) ? ($totalAktualQty / $planQtyTotal) * 100 : 0;
                $oee = ($availability / 100) * ($performance / 100) * ($passRate / 100) * 100;

                \DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->update([
                    'RejectA'     => $totalRejectA,
                    'RejectB'     => $totalRejectB,
                    'AktualQtyA'  => round($aktualQtyA, 2),
                    'AktualQtyB'  => round($aktualQtyB, 2),
                    'PassRate'    => round($passRate, 2),
                    'RejectRate'  => round($rejectRate, 2),
                    'Performance' => round($performance, 2),
                    'QualityRate' => round($passRate, 2),
                    'OEE'         => round($oee, 2),
                    'update_by'   => $userKaryawan,
                    'updated_at'  => now()
                ]);
            }

            \DB::commit();
            // ✅ BAHASA INDONESIA + PARAMETER FILTER URL
            return redirect()->route('inputharian.index', [
                'date'   => $request->date,
                'line'   => $request->line,
                'search' => $request->search
            ])->with('success', 'Data Detail Reject Berhasil Diperbarui');
            
        } catch (\Exception $e) {
            \DB::rollback();
            // ✅ BAHASA INDONESIA
            return redirect()->back()->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        }
    }

    public function detailRepair($id)
    {
        $input = TrsInputHarian::with('item')->findOrFail($id);
        
        $jabatan = strtolower(auth()->user()->Jabatan);
        $isQuality = str_contains($jabatan, 'quality') || str_contains($jabatan, 'qc');

        $masterRepair = \App\Models\Produksi\Master\MsRepair::where('Status', 1)->get();
        
        // AMBIL DATA DETAIL TANPA ORDERBY YANG BIKIN EROR
        $details = DetailRepair::where('IdInputHarian', $id)->get();

        return view('Produksi.inputharian.detail_repair', compact('input', 'masterRepair', 'details', 'isQuality'));
    }

    public function storeDetailRepair(Request $request, $id)
    {
        \DB::beginTransaction();
        try {
            $userKaryawan = \Auth::user()->NamaKaryawan ?? 'System';

            // 1. Hapus data lama agar tidak duplikat saat edit
            \DB::table('prod_detailrepair')->where('IdInputHarian', $id)->delete();

            $totalRepairA = 0;
            $totalRepairB = 0;
            $dataInsert = [];

            if ($request->has('IdRepair')) {
                foreach ($request->IdRepair as $key => $valIdRepair) {
                    if (empty($valIdRepair)) continue;

                    $qtyA = (float)($request->QtyRepairA[$key] ?? 0);
                    $qtyB = (float)($request->QtyRepairB[$key] ?? 0);

                    // Logika Nama Kerusakan
                    $valNamaDrop = $request->NamaKerusakan[$key] ?? null;
                    $namaFinal = ($valNamaDrop === 'Lain-lain') ? ($request->NamaLain[$key] ?? 'Lain-lain') : ($valNamaDrop ?? '-');

                    // Logika Tipe Repair
                    if ($valIdRepair === 'RP-LAIN') {
                        $tipeFinal = $request->RepairLain[$key] ?? 'Lain-lain';
                    } else {
                        $master = \DB::table('prod_msrepair')->where('IdRepair', $valIdRepair)->first();
                        $tipeFinal = $master ? $master->TipeRepair : $valIdRepair;
                    }

                    // 2. GENERATE ID MANUAL
                    $uniqueId = $id . '-' . $valIdRepair . '-' . bin2hex(random_bytes(2));

                    $dataInsert[] = [
                        'id'            => $uniqueId,
                        'IdInputHarian' => $id,
                        'IdRepair'      => $valIdRepair,
                        'TipeRepair'    => $tipeFinal,
                        'NamaKerusakan' => $namaFinal,
                        'RepairA'       => $qtyA,
                        'RepairB'       => $qtyB,
                        'Qty'           => $qtyA + $qtyB,
                        'NoMasalah'     => $request->NoMasalah[$key] ?? null,
                        'AreaProblem'   => $request->AreaProblem[$key] ?? null,
                        'Penyebab'      => $request->PenyebabRepair[$key] ?? null,
                        'Countermeasure'=> $request->CountermeasureRepair[$key] ?? null,
                        'create_by'     => $userKaryawan,
                        'update_by'     => $userKaryawan,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                    
                    $totalRepairA += $qtyA;
                    $totalRepairB += $qtyB;
                }
            }

            // 3. Eksekusi Insert Massal ke Detail
            if (!empty($dataInsert)) {
                \DB::table('prod_detailrepair')->insert($dataInsert);
            }

            // 4. UPDATE HEADER OEE
            $inputHarian = \DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->first();

            if ($inputHarian) {
                $aktualQtyA = (float)($inputHarian->GoodA ?? 0) + $totalRepairA + (float)($inputHarian->RejectA ?? 0);
                $aktualQtyB = (float)($inputHarian->GoodB ?? 0) + $totalRepairB + (float)($inputHarian->RejectB ?? 0);
                
                $totalAktual = $aktualQtyA + $aktualQtyB;
                $repairRate = ($totalAktual > 0) ? (($totalRepairA + $totalRepairB) / $totalAktual) * 100 : 0;

                \DB::table('prod_trsinputharian')->where('IdInputHarian', $id)->update([
                    'RepairA'    => $totalRepairA,
                    'RepairB'    => $totalRepairB,
                    'AktualQtyA' => round($aktualQtyA, 2),
                    'AktualQtyB' => round($aktualQtyB, 2),
                    'RepairRate' => round($repairRate, 2),
                    'update_by'  => $userKaryawan,
                    'updated_at' => now()
                ]);
            }

            \DB::commit();
            return redirect()->route('inputharian.index', [
                'date'   => $request->date,
                'line'   => $request->line,
                'search' => $request->search
            ])->with('success', 'Data Detail Repair Berhasil Diperbarui'); // Ubah bahasa Notif
            
        } catch (\Exception $e) {
            \DB::rollback();
            return redirect()->back()->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage()); // Ubah bahasa Error
        }
    }
    
    public function detailIdleTime($id)
    {
        // Mengambil header produksi beserta itemnya
        $input = TrsInputHarian::with('item')->findOrFail($id);
        
        // Mengambil master idle time yang aktif
        $masterIdle = MsIdleTime::where('Status', 1)->get();
        
        // Mengambil detail idle yang sudah tersimpan untuk ID ini
        $details = DetailIdleTime::where('IdInputHarian', $id)->get();

        return view('Produksi.inputharian.detail_idletime', compact('input', 'masterIdle', 'details'));
    }

    /**
     * Menyimpan data detail Idle Time
     */
    public function storeDetailIdleTime(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // 1. Bersihkan data lama
            \DB::table('prod_detailidletime')->where('IdInputHarian', $id)->delete();

            if ($request->has('IdIdleTime')) {
                foreach ($request->IdIdleTime as $key => $valIdIdle) {
                    // Ambil input angka menit dari operator (misal: 15)
                    $rawMenit = (float)($request->Durasi[$key] ?? 0);

                    if ($rawMenit > 0 && !empty($valIdIdle)) {
                        // KONVERSI: Angka menit murni ke format TIME HH:mm:ss untuk database
                        $formattedDurasi = sprintf('%02d:%02d:00', floor($rawMenit / 60), ($rawMenit % 60));

                        \DB::table('prod_detailidletime')->insert([
                            'IdInputHarian' => $id,
                            'IdIdleTime'    => $valIdIdle,
                            'Durasi'        => $formattedDurasi, // SEKARANG TERSIMPAN SEBAGAI MENIT DI DB
                            'Alasan'        => $request->Alasan[$key] ?? '-', 
                            'create_by'     => Auth::user()->NamaKaryawan ?? 'System',
                            'created_at'    => now()
                        ]);
                    }
                }
            }

            // --- LOGIC HITUNG ULANG TPT & OEE ---
            $inputHarian = TrsInputHarian::findOrFail($id);

            // A. Ambil Total Durasi Idle dalam Menit (Konsisten konversi dari format TIME)
            $totalIdleMenit = DetailIdleTime::where('IdInputHarian', $id)
                ->get()
                ->sum(function($item) {
                    if (strpos($item->Durasi, ':') !== false) {
                        $parts = explode(':', $item->Durasi);
                        $parts[1] = $parts[1] ?? 0;
                        return ($parts[0] * 60) + $parts[1];
                    }
                    return (float)$item->Durasi;
                });

            // B. Ambil Total Downtime (Gue samain logikanya biar akurat)
            $totalDowntime = DB::table('prod_detaildowntime')->where('IdInputHarian', $id)
                ->get()->sum(function($item) {
                    if (strpos($item->Durasi, ':') !== false) {
                        $parts = explode(':', $item->Durasi);
                        $parts[1] = $parts[1] ?? 0;
                        return ($parts[0] * 60) + $parts[1];
                    }
                    return (float)$item->Durasi;
                });

            // C. Jalankan Rumus Otomatis
            $workTime = (float)$inputHarian->AktualWorkTime;
            $tpt = max(0, $workTime - $totalIdleMenit); 
            $totalUchi = (float)($inputHarian->DiesChange + $inputHarian->EarlyCheck);
            $pressTime = max(0, $tpt - $totalDowntime - $totalUchi); 

            // D. Hitung Parameter OEE
            $totalAktualQty = ($inputHarian->GoodA + $inputHarian->GoodB) + 
                            ($inputHarian->RepairA + $inputHarian->RepairB) + 
                            ($inputHarian->RejectA + $inputHarian->RejectB);
            $planQtyTotal = ($inputHarian->PlanQtyA + $inputHarian->PlanQtyB);
            
            $availability = ($tpt > 0) ? ($pressTime / $tpt) * 100 : 0;
            $performance = ($planQtyTotal > 0) ? ($totalAktualQty / $planQtyTotal) * 100 : 0;
            $passRate = ($totalAktualQty > 0) ? (($inputHarian->GoodA + $inputHarian->GoodB) / $totalAktualQty) * 100 : 0;
            $oee = ($availability / 100) * ($performance / 100) * ($passRate / 100) * 100;

            // 2. Update ke Tabel Utama
            $inputHarian->update([
                'IdleTime'     => round($totalIdleMenit, 2), // Update juga kolom IdleTime di header
                'TPT'          => round($tpt, 2),
                'PressTime'    => round($pressTime, 2),
                'Availability' => round($availability, 2),
                'Performance'  => round($performance, 2),
                'OEE'          => round($oee, 2),
                'update_by'    => Auth::user()->NamaKaryawan ?? 'System'
            ]);

            DB::commit();
            // Ganti baris return terakhir di storeDetailRepair
            return redirect()->route('inputharian.index', [
                'date'   => $request->date,
                'line'   => $request->line,
                'search' => $request->search
            ])->with('success', 'Data Idle Time Berhasil Diperbarui');
            
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function detailDowntime($id)
    {
        $input = TrsInputHarian::with(['productionLine', 'item'])->findOrFail($id);
        $jabatan = strtolower(auth()->user()->Jabatan);
        $isQuality = str_contains($jabatan, 'quality') || str_contains($jabatan, 'qc');

        $parts = explode('-', $id);
        $idPlan = isset($parts[1]) ? trim($parts[1]) : null;
        $idItem = trim($input->IdItemProduksi);

        $planData = DB::table('prod_detailplanscheduleproduksi')
                    ->whereRaw("TRIM(IdPlanSchedule) = ?", [$idPlan])
                    ->whereRaw("TRIM(IdItemProduksi) = ?", [$idItem])
                    ->first();

        $start = \Carbon\Carbon::parse($input->AktualStart);
        $finish = \Carbon\Carbon::parse($input->AktualFinish);
        $durasiTotalMenit = ($input->AktualStart && $input->AktualFinish) ? abs($start->diffInMinutes($finish)) : 0;
        
        $breakTime = (float)($input->TimeBreakTime ?? 0);
        $idleTime = (float)($input->IdleTime ?? 0); 
        $tptActual = max(0, $durasiTotalMenit - $breakTime - $idleTime);

        $tptPlan = $planData ? (float)$planData->TPT : 0;
        $ubp = $planData ? (float)$planData->UBP : 0;
        $dtr = $planData ? (float)$planData->DTR : 0;

        // 🔥 FIX RUMUS: Downtime Murni (TPT Actual - TPT Plan - UBP - DTR)
        $downtimeRaw = max(0, $tptActual - $tptPlan - $ubp - $dtr);

        $totalInputtedDT = DB::table('prod_detaildowntime')
            ->where('IdInputHarian', $id)
            ->get()
            ->sum(function($row) {
                if (str_contains($row->Durasi, ':')) {
                    $p = explode(':', $row->Durasi);
                    $p[1] = $p[1] ?? 0;
                    $p[2] = $p[2] ?? 0;
                    return ($p[0] * 60) + $p[1] + ($p[2] / 60);
                }
                return (float)$row->Durasi;
            });

        $sisaDowntime = round(max(0, $downtimeRaw - $totalInputtedDT), 1);
        $totalLoseTime = round($ubp + $dtr + $downtimeRaw, 1);

        $masterDowntime = DB::table('prod_msdowntime')->where('Status', 1)->get();
        
        $details = DB::table('prod_detaildowntime as dt')
                    ->leftJoin('prod_msdowntime as ms', 'dt.Keterangan', '=', 'ms.IdDowntime')
                    ->where('dt.IdInputHarian', $id)
                    ->select('dt.*', 'ms.TipeDowntime as NamaDowntime')
                    ->get();

        return view('Produksi.inputharian.detail_downtime', compact(
            'input', 'masterDowntime', 'details', 'isQuality', 
            'ubp', 'dtr', 'downtimeRaw', 'sisaDowntime', 'totalLoseTime', 'tptActual', 'tptPlan'
        ));
    }

    public function storeDetailDowntime(Request $request, $id)
    {
        \DB::beginTransaction();
        try {
            $userKaryawan = \Auth::user()->NamaKaryawan ?? 'System';

            // ✅ SUDAH BENAR: prod_detaildowntime
            \DB::table('prod_detaildowntime')->where('IdInputHarian', $id)->delete();

            $totalMinutesUsed = 0;
            $dataInsert = [];

            if ($request->has('IdDowntime')) {
                foreach ($request->IdDowntime as $key => $valIdDowntimeDariForm) {
                    if (empty($valIdDowntimeDariForm)) continue;

                    $inputMenit = (float)($request->Durasi[$key] ?? 0);
                    $totalMinutesUsed += $inputMenit;

                    $totalSeconds = round($inputMenit * 60);
                    $formattedDurasi = sprintf('%02d:%02d:%02d', floor($totalSeconds/3600), floor(($totalSeconds/60)%60), $totalSeconds%60);

                    $idDowntimeUnik = "DT-" . str_pad($key + 1, 3, '0', STR_PAD_LEFT);

                    $dataInsert[] = [
                        'IdInputHarian' => $id,
                        'IdDowntime'    => $idDowntimeUnik, 
                        'Keterangan'    => $valIdDowntimeDariForm, 
                        'Durasi'        => $formattedDurasi,
                        'TipeDowntime'  => $request->TipeDowntime[$key] ?? null,
                        'AreaProblem'   => $request->AreaProblem[$key] ?? null,
                        'Masalah'       => $request->Masalah[$key] ?? null,
                        'AkarPenyebab'  => $request->AkarPenyebab[$key] ?? null,
                        'FaktaLapangan' => $request->FaktaLapangan[$key] ?? null,
                        'TipeMasalah'   => $request->TipeMasalah[$key] ?? null,
                        'Stroke'        => $request->Stroke[$key] ?? 0,
                        'update_by'     => $userKaryawan,
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($dataInsert)) {
                \DB::table('prod_detaildowntime')->insert($dataInsert);
            }

            // ✅ FIX: Gunakan Model yang sudah mengarah ke prod_trsinputharian
            \App\Models\Produksi\Transaksi\TrsInputHarian::where('IdInputHarian', $id)->update([
                'TotalDowntime' => round($totalMinutesUsed, 2),
                'update_by'     => $userKaryawan
            ]);

            \DB::commit();
            return redirect()->route('inputharian.index', [
                'date'   => $request->date,
                'line'   => $request->line,
                'search' => $request->search
            ])->with('success', "Data Lose Time Berhasil Diperbarui");

        } catch (\Exception $e) {
            \DB::rollBack();
            return redirect()->back()->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        }
    }

    public function getPlanDetails(Request $request)
    {
        $tanggal = $request->date ?? date('Y-m-d');
        $lineId  = $request->line_id;

        $plans = \App\Models\Produksi\Detail\DetailPlanScheduleProduksi::with(['item', 'header'])
            ->whereHas('header', function($q) use ($tanggal, $lineId) {
                $q->whereDate('TanggalProduksi', $tanggal);
                if ($lineId) { $q->where('IdProductionLine', $lineId); }
            })->get();

        if ($plans->count() > 0) {
            $formattedData = $plans->map(function($p) {
                return [
                    'job_number'      => $p->item->JobNumber ?? '-',
                    'part_name'       => $p->PartName,
                    'po_number'       => $p->PoNumber ?? '-',
                    'plan_qty'        => $p->PlanQtyA . ' / ' . $p->PlanQtyB,
                    'schedule'        => date('H:i', strtotime($p->PlanStart)) . ' - ' . date('H:i', strtotime($p->PlanFinish)),
                    'tpt'             => (float)$p->TPT,
                    'loss_display'    => (float)$p->UBP . ' / ' . (float)$p->DTR,
                    'gsph'            => $p->PlanGSPH,
                    'ct'              => $p->CT,
                    'plan_work_time'  => $p->PlanWorkTime,
                    'stroke'          => $p->Stroke,
                    'die_change_high' => $p->DieChangeHigh,
                    'jml_pallet'      => $p->JmlPallet,
                    'jml_material'    => $p->JmlMaterial,
                    'note'            => $p->Note ?? '-',
                    'mesin'           => [$p->QtyMesin1, $p->QtyMesin2, $p->QtyMesin3, $p->QtyMesin4, $p->QtyMesin5]
                ];
            });
            return response()->json(['success' => true, 'data' => $formattedData]);
        }
        return response()->json(['success' => false, 'message' => 'Tidak Ada Jadwal Produksi.']);
    }
}