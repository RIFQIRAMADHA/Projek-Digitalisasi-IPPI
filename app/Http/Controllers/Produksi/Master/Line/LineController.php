<?php

namespace App\Http\Controllers\Produksi\Master\Line;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produksi\Master\MsProductionLine; // ✅ Pakai model baru
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class LineController extends Controller
{
    public function index()
    {
        $line = MsProductionLine::orderBy('NamaProductionLine', 'asc')
            ->orderBy('Shift', 'asc')
            ->paginate(10);
            
        return view('Produksi.master.productionline.index', compact('line'));
    }

    public function create()
    {
        return view('Produksi.master.productionline.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'NamaProductionLine' => [
                'required', 
                'string', 
                'max:255',
                // Unik berdasarkan kombinasi Shift di tabel 
                Rule::unique('prod_msproductionline')->where(function ($query) use ($request) {
                    return $query->where('NamaProductionLine', $request->NamaProductionLine)
                                 ->where('Shift', $request->Shift);
                }),
            ],
            'Shift' => 'required|string|max:255',
        ], [
            'NamaProductionLine.required' => 'Production Line wajib diisi.',
            'NamaProductionLine.unique'   => 'Kombinasi Line dan Shift ini sudah ada (Duplikat).',
            'Shift.required'              => 'Shift wajib diisi.',
        ]);

        // Generate ID PLNxxx menggunakan MsProductionLine
        $last = MsProductionLine::orderBy('IdProductionLine', 'desc')->first();
        $number = $last ? (int) substr($last->IdProductionLine, 3) + 1 : 1;
        $IdProductionLine = 'PLN' . str_pad($number, 3, '0', STR_PAD_LEFT);

        $line = new MsProductionLine(); // ✅
        $line->IdProductionLine = $IdProductionLine;
        $line->NamaProductionLine = $request->NamaProductionLine;
        $line->Shift = $request->Shift;
        $line->Status = 1; 
        $line->create_by = Auth::user()->NamaKaryawan;
        
        $line->save();

        return redirect()->route('master.productionline.index')
            ->with('success', 'Data Jalur Produksi Berhasil Diperbarui');
    }

    public function show($id)
    {
        $line = MsProductionLine::findOrFail($id); // ✅
        return view('Produksi.master.productionline.show', compact('line'));
    }

    public function edit($id)
    {
        $line = MsProductionLine::findOrFail($id); // ✅
        return view('Produksi.master.productionline.edit', compact('line'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'NamaProductionLine' => [
                'required', 
                'string', 
                'max:255',
                Rule::unique('prod_msproductionline')->where(function ($query) use ($request) {
                    return $query->where('NamaProductionLine', $request->NamaProductionLine)
                                 ->where('Shift', $request->Shift);
                })->ignore($id, 'IdProductionLine'),
            ],
            'Shift' => 'required|string|max:255',
        ], [
            'NamaProductionLine.required' => 'Production Line wajib diisi.',
            'NamaProductionLine.unique'   => 'Kombinasi Line dan Shift ini sudah digunakan!',
            'Shift.required'              => 'Shift wajib diisi.',
        ]);

        $line = MsProductionLine::findOrFail($id); // ✅
        $line->update([
            'NamaProductionLine' => $request->NamaProductionLine,
            'Shift' => $request->Shift,
            'update_by' => Auth::user()->NamaKaryawan,
        ]);

        return redirect()->route('master.productionline.index')
            ->with('success', 'Data Jalur Produksi Berhasil Diperbarui');
    }

    public function destroy($id)
    {
        // 1. CARI DATA MANUAL (Biar aman dari error Not Found)
        $line = \App\Models\Produksi\Master\MsProductionLine::where('IdProductionLine', $id)->first();

        if (!$line) {
            return redirect()->route('master.productionline.index')
                ->with('error', 'Data Jalur Produksi tidak ditemukan!');
        }

        // 2. CEK RELASI KE PRODUCTION SCHEDULE
        $isUsedInSchedule = \Illuminate\Support\Facades\DB::table('prod_trsplanscheduleproduction')
            ->where('IdProductionLine', $line->IdProductionLine)
            ->exists();

        // 3. JIKA DIPAKAI, TOLAK PENGHAPUSAN!
        if ($isUsedInSchedule) {
            return redirect()->route('master.productionline.index')
                ->with('error', 'Gagal! Jalur Produksi ini tidak bisa dihapus karena sedang digunakan pada Jadwal Produksi.');
        }

        // 4. JIKA AMAN, BARU EKSEKUSI HAPUS
        $line->delete();

        return redirect()->route('master.productionline.index')
            ->with('success', 'Data Jalur Produksi Berhasil Dihapus');
    }
}