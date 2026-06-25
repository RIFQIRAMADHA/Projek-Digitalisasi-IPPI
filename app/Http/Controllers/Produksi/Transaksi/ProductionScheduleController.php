<?php

namespace App\Http\Controllers\Produksi\Transaksi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

use App\Models\Produksi\Master\MsProductionLine;
use App\Models\Produksi\Master\MsKaryawan;
use App\Models\Produksi\Master\MsItemProduction;

use App\Models\Produksi\Transaksi\TrsPlanScheduleProduction;
use App\Models\Produksi\Transaksi\TrsInputHarian; 

// ✅ INI YANG DIUBAH: Mengarah ke folder Detail yang baru
use App\Models\Produksi\Detail\DetailPlanScheduleProduksi;

use App\Imports\ProductionScheduleImport; // ✅ Import Class yang tadi
use Maatwebsite\Excel\Facades\Excel;       // ✅ Library Excel

class ProductionScheduleController extends Controller
{
    public function index(Request $request)
    {
        $lines = MsProductionLine::where('Status', 1)
                ->orderBy('NamaProductionLine', 'ASC')
                ->orderBy('Shift', 'ASC')
                ->get();

        $tanggal = $request->get('date', date('Y-m-d'));
        $lineId = $request->get('line');

        // 1. Query Utama - Tambahin filter NOT LIKE 'IH-MAN%' biar item luar jadwal GAK MUNCUL
        $query = TrsInputHarian::with(['productionLine', 'item'])
                    ->whereDate('TanggalProduksi', $tanggal)
                    ->where('IdInputHarian', 'NOT LIKE', 'IH-MAN%'); // ✅ BUANG ITEM MANUAL

        if ($lineId) {
            $query->where('IdProductionLine', $lineId);
        }

        $inputs = $query->get();

        // 2. Transform data (Hanya untuk yang punya IdPlanSchedule asli)
        $inputs->transform(function($item) {
            $parts = explode('-', $item->IdInputHarian);
            $cleanIdPlan = isset($parts[1]) ? trim($parts[1]) : null;
            
            $item->display_id_plan = $cleanIdPlan;

            if ($cleanIdPlan) {
                $headerAsli = DB::table('prod_trsplanscheduleproduction as ts')
                                ->leftJoin('prod_mskaryawan as mk', 'ts.IdKaryawan', '=', 'mk.IdKaryawan')
                                ->select('ts.*', 'mk.NamaKaryawan')
                                ->where('ts.IdPlanSchedule', $cleanIdPlan)
                                ->first();
                
                if ($headerAsli) {
                    $item->pic_display = $headerAsli->NamaKaryawan ?? 'PIC Tidak Ditemukan';
                    $item->status_label = $headerAsli->Status;

                    $masterLine = DB::table('prod_msproductionline')
                                    ->where('IdProductionLine', $headerAsli->IdProductionLine)
                                    ->first();

                    $item->fixed_line_name = $masterLine->NamaProductionLine ?? '-';
                    $item->fixed_shift = $masterLine->Shift ?? '-';
                }
            }
            return $item;
        });

        $inputs = $inputs->sortBy(function($item) {
            return ($item->productionLine->NamaProductionLine ?? '') . ($item->productionLine->Shift ?? '');
        });

        // 3. Grouping (Sekarang isinya cuma Plan resmi, 'MAN' udah gak ada)
        $groupedSchedules = $inputs->groupBy('display_id_plan');

        $selectedLine = $lineId ? MsProductionLine::find($lineId) : null;

        return view('Produksi.productionschedule.index', compact('lines', 'groupedSchedules', 'tanggal', 'selectedLine'));
    }

    /**
     * Halaman Create Jadwal
     */
    public function create()
    {
        // Mengurutkan koleksi berdasarkan NamaProductionLine, lalu Shift
        $lines = MsProductionLine::where('Status', 1)
            ->get()
            ->sortBy(function($line) {
                return $line->NamaProductionLine . '-' . $line->Shift;
            });

        $item = MsItemProduction::where('Status', 1)->get();
        $karyawan = MsKaryawan::where('Status', 1)
                                ->where('Jabatan', 'LIKE', '%leader%')
                                ->get();
        
        return view('Produksi.productionschedule.create', compact('lines', 'karyawan', 'item'));
    }

    /**
     * Simpan Jadwal Baru
     */
    public function store(Request $request)
    {
        // 1. Validasi Bawaan dengan Custom Message Bahasa Indonesia
        $request->validate([
            'IdProductionLine' => 'required',
            'IdKaryawan'       => 'required', 
            'TanggalProduksi'  => 'required|date|after_or_equal:today',
            'details'          => 'required|array|min:1',
            'details.*.IdItemProduksi' => 'required',
            'details.*.StartProduksi'  => 'required',
            'details.*.FinishProduksi' => 'required',
        ], [
            'IdProductionLine.required' => 'Production Line wajib dipilih.',
            'IdKaryawan.required'       => 'PIC / Leader wajib dipilih.',
            'TanggalProduksi.required'  => 'Tanggal Produksi wajib diisi.',
            'TanggalProduksi.after_or_equal' => 'Tanggal Produksi tidak boleh kurang dari hari ini.',
            'details.required'          => 'Minimal harus ada 1 baris jadwal produksi.',
            'details.*.IdItemProduksi.required' => 'Item Produksi wajib dipilih pada detail.',
            'details.*.StartProduksi.required'  => 'Waktu Mulai wajib diisi.',
            'details.*.FinishProduksi.required' => 'Waktu Selesai wajib diisi.',
        ]);

        // 2. Cek Duplikat di Form (Detail)
        $items = collect($request->details)->pluck('IdItemProduksi');
        if ($items->duplicates()->isNotEmpty()) {
            return back()->withErrors(['duplicate' => 'Terdapat Item Produksi yang sama pada jadwal produksi yang identik.'])->withInput();
        }

        // 3. Cek Duplikat di Database (Line + Tanggal)
        $exists = TrsPlanScheduleProduction::where('IdProductionLine', $request->IdProductionLine)
                    ->whereDate('TanggalProduksi', $request->TanggalProduksi)
                    ->exists();

        if ($exists) {
            $line = \App\Models\Produksi\Master\MsProductionLine::find($request->IdProductionLine);
            $namaLine = $line ? $line->NamaProductionLine . " - " . $line->Shift : "Line tersebut";
            return back()->withErrors(['duplicate' => "Jadwal untuk $namaLine pada tanggal tersebut sudah terdaftar!"])->withInput();
        }
        
        DB::beginTransaction();
        try {
            // Generate ID Plan: PS + 3 digit increment
            $last = TrsPlanScheduleProduction::orderBy('IdPlanSchedule', 'desc')->first();
            $number = $last ? (int) substr($last->IdPlanSchedule, 2) + 1 : 1;
            $IdPlanSchedule = 'PS' . str_pad($number, 3, '0', STR_PAD_LEFT); 

            // Create Header
            $header = TrsPlanScheduleProduction::create([
                'IdPlanSchedule'   => $IdPlanSchedule,
                'IdProductionLine' => $request->IdProductionLine,
                'IdKaryawan'       => $request->IdKaryawan,
                'TanggalProduksi'  => $request->TanggalProduksi,
                'Status'           => null,
                'create_by'        => Auth::user()->NamaKaryawan ?? 'System',
            ]);

            foreach ($request->details as $index => $detail) {
                $masterItem = MsItemProduction::find($detail['IdItemProduksi']);

                $line = MsProductionLine::find($request->IdProductionLine);
                $namaLine = strtoupper($line->NamaProductionLine ?? '');
                
                // Ambil nilai mesin dari form, jika kosong atau 0 untuk E/F, paksa jadi 1
                $m1 = $detail['QtyMesin1'] ?? 0;
                $m2 = $detail['QtyMesin2'] ?? 0;
                $m3 = $detail['QtyMesin3'] ?? 0;
                $m4 = $detail['QtyMesin4'] ?? 0;
                $m5 = $detail['QtyMesin5'] ?? 0;

                if (strpos($namaLine, 'LINE E') !== false || strpos($namaLine, 'LINE F') !== false) {
                    $m1 = $m2 = $m3 = $m4 = 1; // Paksa aktifkan 4 mesin untuk E/F
                    $m5 = 0; 
                }

                // Create Detail
                DetailPlanScheduleProduksi::create([
                    'IdPlanSchedule' => $header->IdPlanSchedule,
                    'IdItemProduksi' => $detail['IdItemProduksi'],
                    'PartName'       => $masterItem->NamaPart ?? '-',
                    'PlanQtyA'       => $detail['PlanQty1'] ?? 0,
                    'PlanQtyB'       => $detail['PlanQty2'] ?? 0, 
                    'PlanStart'      => $detail['StartProduksi'],
                    'PlanFinish'     => $detail['FinishProduksi'],
                    'BqSht'          => $detail['BqSht'] ?? 0,
                    'PressTime'      => $detail['PressTime'] ?? 0,
                    'DiesChangeUchi' => $detail['Uchi'] ?? 0,
                    'DiesChangeSoto' => $detail['Soto'] ?? 0,
                    'FirstQCheck'    => $detail['FirstQCheck'] ?? 0,
                    'TPT'            => $detail['TPT'] ?? 0,
                    'UBP'            => $detail['UBP'] ?? 0,
                    'DTR'            => $detail['DTR'] ?? 0,
                    'PlanWorkTime'   => $detail['WorkTime'] ?? 0,
                    'PlanGSPH'       => $detail['GSPH'] ?? 0,
                    'Stroke'         => $detail['Stroke'] ?? 0,
                    'Note'           => $detail['Note'] ?? null,
                    'QtyMesin1'      => $m1,
                    'QtyMesin2'      => $m2,
                    'QtyMesin3'      => $m3,
                    'QtyMesin4'      => $m4,
                    'QtyMesin5'      => $m5,
                    'TotalMesin'     => ($m1 + $m2 + $m3 + $m4 + $m5),
                    'DieChangeHigh'  => $detail['DieChangeHigh'] ?? 0,
                    'PoNumber'       => $detail['PoNumber'] ?? null,
                    'JmlPallet'      => $detail['JmlPallet'] ?? 0,
                    'CT'             => $detail['CT'] ?? 0,
                    'JmlMaterial'    => $detail['JmlMaterial'] ?? 0,
                    'create_by'      => Auth::user()->NamaKaryawan ?? 'System',
                ]);

                // Create TrsInputHarian
                TrsInputHarian::create([
                    'IdInputHarian'    => 'IH-' . $header->IdPlanSchedule . '-' . $index,
                    'IdProductionLine' => $header->IdProductionLine,
                    'IdItemProduksi'   => $detail['IdItemProduksi'],
                    'TanggalProduksi'  => $header->TanggalProduksi,
                    'PlanQtyA'         => $detail['PlanQty1'] ?? 0,
                    'PlanQtyB'         => $detail['PlanQty2'] ?? 0,
                    'PlanGSPH'         => $detail['GSPH'] ?? 0,
                    'QtyMesin1'        => $m1,
                    'QtyMesin2'        => $m2,
                    'QtyMesin3'        => $m3,
                    'QtyMesin4'        => $m4,
                    'QtyMesin5'        => $m5,
                    'StatusProses'     => 'Ready',
                    'create_by'        => Auth::user()->NamaKaryawan ?? 'System',
                ]);
            }

            DB::commit();
            return redirect()->route('productionschedule.index')->with('success', 'Data Jadwal Produksi Berhasil Disimpan');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->withErrors(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()])->withInput();
        }
    }

    public function checkDuplicate(Request $request)
    {
        $exists = \App\Models\Produksi\Transaksi\TrsPlanScheduleProduction::where('IdProductionLine', $request->line)
                    ->whereDate('TanggalProduksi', $request->date)
                    ->first();

        if ($exists) {
            return response()->json([
                'exists' => true,
                'line_name' => $exists->productionLine->NamaProductionLine . ' - ' . $exists->productionLine->Shift
            ]);
        }
        return response()->json(['exists' => false]);
    }

    /**
     * Menampilkan Detail Jadwal
     */
    public function show($id)
    {
        $id = trim($id);
        
        // 1. Ambil ID Plan yang bersih
        $inputHarian = TrsInputHarian::where('IdInputHarian', $id)->first();
        $idPlan = $inputHarian ? (explode('-', $inputHarian->IdInputHarian)[1] ?? $id) : str_replace('IH-', '', $id);
        
        // 2. Ambil data dengan memastikan relasi terbaru
        $schedule = TrsPlanScheduleProduction::with(['productionLine', 'details.item', 'pic'])
            ->where('IdPlanSchedule', $idPlan)
            ->first();

        if (!$schedule) {
            return redirect()->route('productionschedule.index')->with('error', 'Data tidak ditemukan');
        }

        // 🔥 TAMBAHAN: Pastikan kita ngambil data dari tabel yang fresh
        // Kalau lu ngerasa "Revisi" nya gak muncul, cek apakah status di database memang sudah berubah
        // Lu bisa melakukan refresh manual jika perlu:
        $schedule->refresh(); 

        return view('Produksi.productionschedule.show', compact('schedule'));
    }

    public function edit($id)
    {
        $id = trim($id);
        $idPlan = str_contains($id, 'IH-') ? (explode('-', $id)[1] ?? $id) : str_replace('IH-', '', $id);

        $schedule = TrsPlanScheduleProduction::with(['details.item', 'productionLine'])
                    ->where('IdPlanSchedule', $idPlan)
                    ->first();

        if (!$schedule) {
            return redirect()->route('productionschedule.index')->with('error', 'Terjadi Kesalahan');
        }

        // 🔥 KUNCI UTAMA: Loop setiap detail untuk cek statusnya di transaksi harian
        foreach ($schedule->details as $detail) {
            // Cari data transaksi harian yang sinkron dengan item schedule ini
            $transaksiHarian = DB::table('prod_trsinputharian')
                ->where('IdProductionLine', $schedule->IdProductionLine)
                ->where('IdItemProduksi', $detail->IdItemProduksi)
                ->whereDate('TanggalProduksi', $schedule->TanggalProduksi)
                ->first();

            // Jika transaksi harian ditemukan, dan operator SUDAH pencet START (AktualStart terisi) atau SELESAI
            if ($transaksiHarian && (!empty($transaksiHarian->AktualStart) && $transaksiHarian->AktualStart !== '00:00:00')) {
                $detail->is_locked_by_production = true; // Kasih tanda pengunci
            } else {
                $detail->is_locked_by_production = false;
            }
        }

        $lines = MsProductionLine::where('Status', 1)->get();
        $item = MsItemProduction::where('Status', 1)->get();
        
        $karyawan = MsKaryawan::where('Status', 1)
        ->where(function($q) {
            $q->where('Jabatan', 'LIKE', '%leader%')
              ->orWhere('Jabatan', 'LIKE', '%foreman%');
        })
        ->orderBy('NamaKaryawan', 'ASC')
        ->get();
        
        return view('Produksi.productionschedule.edit', compact('schedule', 'lines', 'karyawan', 'item'));
    }

    public function update(Request $request, $id)
    {
        $id = trim($id);
        
        // 1. VALIDASI DATA
        $request->validate([
            'IdProductionLine' => 'required',
            'IdKaryawan'       => 'required',
            'TanggalProduksi'  => 'required|date',
            'details'          => 'required|array|min:1',
            'details.*.IdItemProduksi' => 'required', // Mengunci agar item detail wajib ada
        ]);

        DB::beginTransaction();
        try {
            // 2. CARI DATA HEADER
            $header = TrsPlanScheduleProduction::where('IdPlanSchedule', $id)->firstOrFail();
            
            // 3. LOGIKA NOMOR REVISI OTOMATIS
            preg_match('/\d+/', $header->Status, $matches);
            $nextRev = (isset($matches[0]) ? (int)$matches[0] : 0) + 1;
            $newStatus = "Revisi " . $nextRev;

            // 4. UPDATE DATA HEADER
            $header->update([
                'IdProductionLine' => $request->IdProductionLine,
                'IdKaryawan'       => $request->IdKaryawan,
                'TanggalProduksi'  => $request->TanggalProduksi,
                'Status'           => $newStatus,
                'update_by'        => auth()->user()->NamaKaryawan ?? 'System'
            ]);

            // 5. HAPUS DETAIL LAMA (Biar data tidak menumpuk berlipat ganda)
            DB::table('prod_detailplanscheduleproduksi')->where('IdPlanSchedule', $id)->delete();
            
            // 6. LOOPING INSERT DETAIL BARU
            foreach ($request->details as $index => $detail) {
                // Proteksi jika baris kosong/tidak memilih item produksi
                if (!isset($detail['IdItemProduksi'])) {
                    continue; 
                }

                $idItem = $detail['IdItemProduksi'];
                $masterItem = MsItemProduction::where('IdItemProduksi', $idItem)->first();

                // --- LOGIKA AUTO-MESIN (SAMA SEPERTI DI STORE) ---
                $line = MsProductionLine::find($request->IdProductionLine);
                $namaLine = strtoupper($line->NamaProductionLine ?? '');

                $m1 = $detail['QtyMesin1'] ?? 0;
                $m2 = $detail['QtyMesin2'] ?? 0;
                $m3 = $detail['QtyMesin3'] ?? 0;
                $m4 = $detail['QtyMesin4'] ?? 0;
                $m5 = $detail['QtyMesin5'] ?? 0;

                // Paksa isi 1 jika Line E/F agar muncul di Daily Input
                if (strpos($namaLine, 'LINE E') !== false || strpos($namaLine, 'LINE F') !== false) {
                    $m1 = $m2 = $m3 = $m4 = 1;
                    $m5 = 0;
                }

                // Insert ulang ke tabel detail plan schedule
                DB::table('prod_detailplanscheduleproduksi')->insert([
                    'IdPlanSchedule' => $id,
                    'IdItemProduksi' => $idItem,
                    'PartName'       => $masterItem->NamaPart ?? '-',
                    'PlanQtyA'       => $detail['PlanQty1'] ?? 0,
                    'PlanQtyB'       => $detail['PlanQty2'] ?? 0,
                    'PlanStart'      => $detail['StartProduksi'],  
                    'PlanFinish'     => $detail['FinishProduksi'], 
                    'BqSht'          => $detail['BqSht'] ?? 0,
                    'PressTime'      => $detail['PressTime'] ?? 0,
                    'DiesChangeUchi' => $detail['Uchi'] ?? 0,
                    'DiesChangeSoto' => $detail['Soto'] ?? 0,
                    'FirstQCheck'    => $detail['FirstQCheck'] ?? 0,
                    'TPT'            => $detail['TPT'] ?? 0,
                    'UBP'            => $detail['UBP'] ?? 0,
                    'DTR'            => $detail['DTR'] ?? 0,
                    'PlanWorkTime'   => $detail['WorkTime'] ?? 0,
                    'PlanGSPH'       => $detail['GSPH'] ?? 0,
                    'Stroke'         => $detail['Stroke'] ?? 0,
                    'QtyMesin1'      => $m1,
                    'QtyMesin2'      => $m2,
                    'QtyMesin3'      => $m3,
                    'QtyMesin4'      => $m4,
                    'QtyMesin5'      => $m5,
                    'TotalMesin'     => ($m1 + $m2 + $m3 + $m4 + $m5),
                    'DieChangeHigh'  => $detail['DieChangeHigh'] ?? 0,
                    'PoNumber'       => $detail['PoNumber'] ?? null,
                    'JmlPallet'      => $detail['JmlPallet'] ?? 0,
                    'CT'             => $detail['CT'] ?? 0,
                    'JmlMaterial'    => $detail['JmlMaterial'] ?? 0,
                    'create_by'      => auth()->user()->NamaKaryawan ?? 'System',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                // 7. SINKRONISASI KE INPUT HARIAN (Menggunakan updateOrInsert)
                $idHarian = 'IH-' . $id . '-' . $index;
                DB::table('prod_trsinputharian')->updateOrInsert(
                    ['IdInputHarian' => $idHarian],
                    [
                        'IdProductionLine' => $request->IdProductionLine,
                        'IdItemProduksi'   => $idItem,
                        'TanggalProduksi'  => $request->TanggalProduksi,
                        'PlanQtyA'         => $detail['PlanQty1'] ?? 0,
                        'PlanQtyB'         => $detail['PlanQty2'] ?? 0,
                        'PlanGSPH'         => $detail['GSPH'] ?? 0,
                        'QtyMesin1'        => $m1,
                        'QtyMesin2'        => $m2,
                        'QtyMesin3'        => $m3,
                        'QtyMesin4'        => $m4,
                        'QtyMesin5'        => $m5,
                        // 'TotalMesin'       => $this->hitungTotalMesin($detail),
                        'update_by'        => auth()->user()->NamaKaryawan ?? 'System',
                        'updated_at'       => now(),
                    ]
                );
            }

            DB::commit();
            return redirect()->route('productionschedule.index')->with('success', 'Data Jadwal Produksi Berhasil Diperbarui');
            
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'Terjadi Kesalahan' . $e->getMessage());
        }
    }

    /**
     * Hapus Jadwal
     */
    public function destroy($id)
    {
        try {
            $id = trim($id);
            $schedule = TrsPlanScheduleProduction::find($id);
            if (!$schedule) return response()->json(['success' => false, 'message' => 'Data Tidak Ditemukan'], 404);

            $isRunning = DB::table('prod_trsinputharian')
                ->where('IdInputHarian', 'LIKE', 'IH-' . $id . '-%')
                ->where('StatusProses', '=', 'Running')
                ->exists();

            if ($isRunning) {
                return response()->json(['success' => false, 'message' => 'Jadwal Tersebut Sedang Berjalan.'], 422);
            }

            DB::beginTransaction();
            DB::table('prod_trsinputharian')->where('IdInputHarian', 'LIKE', 'IH-' . $id . '-%')->delete();
            DB::table('prod_detailplanscheduleproduksi')->where('IdPlanSchedule', $id)->delete();
            $schedule->delete();
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Data Jadwal Produksi Berhasil Diperbarui']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * AJAX: Ambil Baris Detail untuk Form Create/Edit
     */
    public function getDetailRow($index) {
        $item = MsItemProduction::where('Status', 1)->get();
        return view('Produksi.productionschedule.partials.detail_row', compact('item', 'index'))->render();
    }

    public function import(Request $request) 
    {
        $request->validate([
            'excel_file' => 'required|mimes:xlsx,xls',
            'line_type'  => 'required'
        ]);

        try {
            Excel::import(new \App\Imports\ProductionScheduleImport($request->line_type), $request->file('excel_file'));
            return response()->json(['success' => true, 'message' => 'Data Jadwal Produksi Berhasil Diperbarui']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi Kesalahan' . $e->getMessage()], 500);
        }
    }
}