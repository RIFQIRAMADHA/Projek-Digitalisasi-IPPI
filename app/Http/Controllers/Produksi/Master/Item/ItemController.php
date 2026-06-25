<?php

namespace App\Http\Controllers\Produksi\Master\Item;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produksi\Master\MsItemProduction; 
use App\Models\Produksi\Master\MsCustomer;       
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        // 🔥 SIMPAN URL TERAKHIR BESERTA PAGE & SEARCH KE SESSION
        session(['last_item_url' => request()->fullUrl()]);

        $status = ($request->status == 'non-aktif') ? 0 : 1;
        $search = $request->search;

        $item = MsItemProduction::with('customer')
            ->where('Status', $status)
            ->when($search, function($query) use ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('NamaPart', 'like', "%{$search}%")
                    ->orWhere('PartNumber', 'like', "%{$search}%")
                    ->orWhere('JobNumber', 'like', "%{$search}%");
                });
            })
            ->orderBy('updated_at', 'asc')
            ->paginate(10);

        return view('Produksi.master.itemproduction.index', compact('item'));
    }

    public function create()
    {
        $customers = MsCustomer::where('Status', 1)->get();
        return view('Produksi.master.itemproduction.create', compact('customers'));
    }

    public function store(Request $request)
    {
        // 1. Gabungkan Job Number untuk keperluan pengecekan dan penyimpanan
        $combinedJobNumber = $request->JobNumberA;
        if ($request->filled('JobNumberB')) {
            $combinedJobNumber .= '/' . $request->JobNumberB;
        }

        // 2. Validasi Input (Menambahkan unique untuk PartNumber)
        $request->validate([
            'IdCustomer'   => 'required',
            'JobNumberA'   => 'required|string',
            'PartNumber'   => 'required|string|unique:prod_msitemproduction,PartNumber', // Validasi unik
            'NamaPart'     => 'required|string',
            'Model'        => 'required',
            'CT'           => 'required|numeric|min:0',
            'BestGSPH'     => 'required|numeric|min:0', 
            'Berat'        => 'required|numeric|min:0',
            'QtyPerPallet' => 'required|numeric|min:0',
            'Gambar'       => 'required|image|mimes:jpeg,png,jpg|max:10240',
        ], [
            'IdCustomer.required'   => 'Pilih Customer terlebih dahulu.',
            'JobNumberA.required'   => 'Job Number wajib diisi.',
            'PartNumber.required'   => 'Part Number wajib diisi.',
            'PartNumber.unique'     => 'Part Number ini sudah terdaftar di sistem!',
            'NamaPart.required'     => 'Nama Part wajib diisi.',
            'Model.required'        => 'Model produk wajib diisi.',
            'CT.required'           => 'Cycle Time (CT) wajib diisi.',
            'BestGSPH.required'     => 'Target GSPH wajib diisi.',
            'BestGSPH.numeric'      => 'Target GSPH harus berupa angka.',
            'QtyPerPallet.required' => 'Qty Per Pallet wajib diisi.',
            'QtyPerPallet.numeric'  => 'Qty Per Pallet harus berupa angka.',
            'Berat.required'        => 'Berat part wajib diisi.',
            'Gambar.required'       => 'Gambar produk wajib diunggah!',
            'Gambar.max'            => 'Ukuran gambar maksimal adalah 10 MB!',
        ]);

        // 3. Validasi Manual untuk Gabungan Job Number (Cegah Duplikat)
        $existsJob = MsItemProduction::where('JobNumber', $combinedJobNumber)->exists();
        if ($existsJob) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['JobNumberA' => 'Job Number (' . $combinedJobNumber . ') sudah terdaftar!']);
        }

        // 4. Generate ID Otomatis
        $last = MsItemProduction::orderBy('IdItemProduksi', 'desc')->first();
        $number = $last ? (int) substr($last->IdItemProduksi, 3) + 1 : 1;
        $IdItemProduksi = 'ITM' . str_pad($number, 4, '0', STR_PAD_LEFT);

        // 5. Upload Gambar
        $gambarPath = $request->hasFile('Gambar') ? $request->file('Gambar')->store('itemproduction', 'public') : null;

        // 6. Simpan ke Database
        $item = new MsItemProduction();
        $item->IdItemProduksi = $IdItemProduksi;
        $item->IdCustomer     = $request->IdCustomer;
        $item->JobNumber      = $combinedJobNumber;
        $item->PartNumber     = $request->PartNumber;
        $item->NamaPart       = $request->NamaPart;
        $item->Model          = $request->Model;
        $item->CT             = $request->CT;
        $item->BestGSPH       = $request->BestGSPH; 
        $item->Berat          = $request->Berat;
        $item->QtyPerPallet   = $request->QtyPerPallet;
        $item->Gambar         = $gambarPath;
        $item->create_by      = Auth::user()->NamaKaryawan;
        $item->Status         = 1; 
        $item->save();

        return redirect()->route('master.itemproduction.index')->with('success', 'Data Item Produksi Berhasil Diperbarui');
    }

    public function edit($id)
    {
        $item = MsItemProduction::findOrFail($id);
        $customers = MsCustomer::where('Status', 1)->get();

        $jobs = explode('/', $item->JobNumber);
        $jobA = $jobs[0] ?? '';
        $jobB = $jobs[1] ?? '';

        return view('Produksi.master.itemproduction.edit', compact('item', 'customers', 'jobA', 'jobB'));
    }

    public function update(Request $request, $id)
    {
        $item = MsItemProduction::findOrFail($id);

        // Fitur Restore jika status non-aktif
        if ($request->has('restore')) {
            $item->update(['Status' => 1, 'update_by' => Auth::user()->NamaKaryawan]);
            return redirect()->route('master.itemproduction.index')->with('success', 'Data Item Produksi Berhasil Diperbarui');
        }

        // 1. Gabungkan Job Number
        $combinedJobNumber = $request->JobNumberA;
        if ($request->filled('JobNumberB')) {
            $combinedJobNumber .= '/' . $request->JobNumberB;
        }

        // 2. Validasi (Unique PartNumber kecuali ID sendiri)
        $request->validate([
            'IdCustomer'   => 'required',
            'PartNumber'   => 'required|string|unique:prod_msitemproduction,PartNumber,' . $id . ',IdItemProduksi',
            'JobNumberA'   => 'required|string',
            'NamaPart'     => 'required',
            'Model'        => 'required',
            'CT'           => 'required|numeric|min:0',
            'BestGSPH'     => 'required|numeric|min:0', 
            'Berat'        => 'required|numeric|min:0',
            'QtyPerPallet' => 'required|numeric|min:0',
            'Gambar'       => 'nullable|image|mimes:jpeg,png,jpg|max:10240', // 10MB
        ], [
            // Pesan kalau kosong (required)
            'IdCustomer.required'   => 'Pilih Customer terlebih dahulu.',
            'JobNumberA.required'   => 'Job Number wajib diisi.',
            'PartNumber.required'   => 'Part Number wajib diisi.',
            'NamaPart.required'     => 'Nama Part wajib diisi.',
            'Model.required'        => 'Model produk wajib diisi.',
            'CT.required'           => 'Cycle Time (CT) wajib diisi.',
            'BestGSPH.required'     => 'Target GSPH wajib diisi.',
            'QtyPerPallet.required' => 'Qty Per Pallet wajib diisi.',
            'Berat.required'        => 'Berat part wajib diisi.',
            
            // Pesan validasi tipe/unik
            'PartNumber.unique'     => 'Part Number sudah digunakan oleh item lain!',
            'CT.numeric'            => 'Cycle Time harus berupa angka.',
            'BestGSPH.numeric'      => 'Target GSPH harus berupa angka.',
            'QtyPerPallet.numeric'  => 'Qty Per Pallet harus berupa angka.',
            'Berat.numeric'         => 'Berat harus berupa angka.',
            'Gambar.max'            => 'Ukuran gambar maksimal adalah 10 MB!',
        ]);

        // 3. Validasi Manual Job Number (Cegah duplikat dengan item lain)
        $existsJob = MsItemProduction::where('JobNumber', $combinedJobNumber)
                    ->where('IdItemProduksi', '!=', $id)
                    ->exists();
        if ($existsJob) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['JobNumberA' => 'Job Number sudah digunakan oleh item lain!']);
        }

        // 4. Update Gambar jika ada file baru
        $gambarPath = $item->Gambar;
        if ($request->hasFile('Gambar')) {
            if ($item->Gambar) { 
                Storage::disk('public')->delete($item->Gambar); 
            }
            $gambarPath = $request->file('Gambar')->store('itemproduction', 'public');
        }

        // 5. Update Data
        $item->update([
            'IdCustomer'   => $request->IdCustomer,
            'JobNumber'    => $combinedJobNumber,
            'PartNumber'   => $request->PartNumber,
            'NamaPart'     => $request->NamaPart,
            'Model'        => $request->Model,
            'CT'           => $request->CT,
            'BestGSPH'     => $request->BestGSPH, 
            'Berat'        => $request->Berat,
            'QtyPerPallet' => $request->QtyPerPallet,
            'Gambar'       => $gambarPath,
            'update_by'    => Auth::user()->NamaKaryawan, 
        ]);

        return redirect(session('last_item_url', route('master.itemproduction.index')))
            ->with('success', 'Data Item Produksi Berhasil Diperbarui');
    }

    public function show($id)
    {
        $item = MsItemProduction::with('customer')->findOrFail($id);
        return view('Produksi.master.itemproduction.show', compact('item'));
    }

    public function destroy($id)
    {
        $item = MsItemProduction::findOrFail($id);
        $item->update(['Status' => 0, 'update_by' => Auth::user()->NamaKaryawan]);
        return redirect(session('last_item_url', route('master.itemproduction.index')))
            ->with('success', 'Data Item Produksi Berhasil Diperbarui');
    }
}