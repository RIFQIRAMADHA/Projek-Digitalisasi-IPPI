<?php

namespace App\Http\Controllers\Produksi\Master\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produksi\Master\MsCustomer; // ✅ Sudah disesuaikan
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        // Default status aktif (1), kecuali jika request status adalah non-aktif (0)
        $status = ($request->status == 'non-aktif') ? 0 : 1;

        $customer = MsCustomer::where('Status', $status)
            ->orderBy('updated_at', 'asc')
            ->paginate(10);

        return view('Produksi.master.customer.index', compact('customer'));
    }

    public function create()
    {
        return view('Produksi.master.customer.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'NamaCustomer'    => 'required|string|max:255|unique:prod_msCustomer,NamaCustomer',
            'NamaCustomerPIC' => 'nullable|regex:/^[a-zA-Z\s]+$/',
            'AlamatCustomer'  => 'nullable|string|max:255',
            'NoTelpCustomer'  => 'nullable|digits_between:1,13|unique:prod_msCustomer,NoTelpCustomer',
            'EmailCustomer'   => 'nullable|email|unique:prod_msCustomer,EmailCustomer',
            'NPWPCustomer'    => 'nullable|digits_between:1,15|unique:prod_msCustomer,NPWPCustomer',
        ], [
            'NamaCustomer.required'    => 'Nama Customer wajib diisi.',
            'NamaCustomer.unique'      => 'Nama Customer ini sudah terdaftar.',
            'NamaCustomerPIC.regex'    => 'Nama PIC hanya boleh huruf dan spasi.',
            'NoTelpCustomer.unique'    => 'Nomor telepon sudah digunakan customer lain.',
            'EmailCustomer.email'      => 'Format email tidak valid.',
            'EmailCustomer.unique'     => 'Email sudah digunakan customer lain.',
            'NPWPCustomer.unique'      => 'NPWP sudah terdaftar di sistem.',
        ]);

        // Logic Auto ID CST001, CST002...
        $last = MsCustomer::orderBy('IdCustomer', 'desc')->first();
        $number = $last ? (int) substr($last->IdCustomer, 3) + 1 : 1;
        $IdCustomer = 'CST' . str_pad($number, 3, '0', STR_PAD_LEFT);

        $customer = new MsCustomer();
        $customer->IdCustomer = $IdCustomer;
        $customer->NamaCustomer = $request->NamaCustomer;
        
        // Mengisi default '-' jika input kosong
        $customer->NamaCustomerPIC = $request->NamaCustomerPIC ?? '-';
        $customer->AlamatCustomer = $request->AlamatCustomer ?? '-';
        $customer->NoTelpCustomer = $request->NoTelpCustomer ?? '-';
        $customer->EmailCustomer  = $request->EmailCustomer ?? '-';
        $customer->NPWPCustomer   = $request->NPWPCustomer ?? '-';
        
        $customer->create_by = Auth::user()->NamaKaryawan;
        $customer->Status = 1; 
        $customer->save();

        return redirect()->route('master.customer.index')
            ->with('success', 'Data Pelanggan Berhasil Diperbarui');
    }

    public function show($id)
    {
        $customer = MsCustomer::findOrFail($id);
        return view('Produksi.master.customer.show', compact('customer'));
    }

    public function edit($id)
    {
        $customer = MsCustomer::findOrFail($id);
        return view('Produksi.master.customer.edit', compact('customer'));
    }

    public function update(Request $request, $id)
    {
        $customer = MsCustomer::findOrFail($id);

        // Logic Restore (Jika dari non-aktif ke aktif)
        if ($request->has('restore')) {
            $customer->update([
                'Status' => 1,
                'update_by' => Auth::user()->NamaKaryawan
            ]);
            return redirect()->route('master.customer.index', ['status' => 'non-aktif'])
                ->with('success', 'The customer has been reactivated!');
        }

        // VALIDASI: Hanya Nama yang Required
        $request->validate([
            'NamaCustomer'    => ['required', 'string', 'max:255', Rule::unique('prod_msCustomer', 'NamaCustomer')->ignore($id, 'IdCustomer')],
            'NamaCustomerPIC' => 'nullable|regex:/^[a-zA-Z\s]+$/',
            'AlamatCustomer'  => 'nullable|string|max:255',
            'NoTelpCustomer'  => ['nullable', 'digits_between:1,13', Rule::unique('prod_msCustomer', 'NoTelpCustomer')->ignore($id, 'IdCustomer')],
            'EmailCustomer'   => ['nullable', 'email', Rule::unique('prod_msCustomer', 'EmailCustomer')->ignore($id, 'IdCustomer')],
            'NPWPCustomer'    => ['nullable', 'digits_between:1,15', Rule::unique('prod_msCustomer', 'NPWPCustomer')->ignore($id, 'IdCustomer')],
        ], [
            'NamaCustomer.required' => 'Nama Customer wajib diisi.',
            'NamaCustomer.unique'   => 'Nama Customer sudah terdaftar.',
            'NamaCustomerPIC.regex' => 'Nama PIC hanya boleh huruf dan spasi.',
            'NoTelpCustomer.unique' => 'Nomor telepon sudah digunakan.',
            'EmailCustomer.unique'  => 'Email sudah digunakan.',
            'NPWPCustomer.unique'   => 'NPWP sudah terdaftar.',
        ]);

        // UPDATE: Pakai default '-' jika field opsional dikosongkan
        $customer->update([
            'NamaCustomer'    => $request->NamaCustomer,
            'NamaCustomerPIC' => $request->NamaCustomerPIC ?? '-',
            'AlamatCustomer'  => $request->AlamatCustomer ?? '-',
            'NoTelpCustomer'  => $request->NoTelpCustomer ?? '-',
            'EmailCustomer'   => $request->EmailCustomer ?? '-',
            'NPWPCustomer'    => $request->NPWPCustomer ?? '-',
            'update_by'       => Auth::user()->NamaKaryawan, 
        ]);

        return redirect()->route('master.customer.index')
            ->with('success', 'Data Pelanggan Berhasil Diperbarui');
    }

    public function destroy($id)
    {
        $customer = MsCustomer::findOrFail($id);
        
        // 🔥 CEK RELASI: Apakah Customer ini sedang dipakai di tabel MsItemProduction?
        $isUsedInItem = \App\Models\Produksi\Master\MsItemProduction::where('IdCustomer', $id)->exists();

        // Kalau datanya dipake, tolak penghapusan!
        if ($isUsedInItem) {
            return redirect()->route('master.customer.index')
                ->with('error', 'Data Pelanggan tidak dapat dihapus karena masih terhubung pada transaksi atau data lain.');
        }

        // Kalau aman (nggak dipake di mana-mana), baru boleh HARD DELETE
        $customer->delete();

        return redirect()->route('master.customer.index')
            ->with('success', 'Data Pelanggan Berhasil Dihapus');
    }
}