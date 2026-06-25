<?php

namespace App\Http\Controllers\Produksi\Master\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produksi\Master\MsKaryawan; // ✅ Sudah disesuaikan ke MsKaryawan
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class EmployeeController extends Controller
{
    // Variabel helper daftar jabatan
    private function getValidJabatan()
    {
        // Jabatan Paten
        $paten = ['admin', 'quality', 'leader k', 'leader e', 'leader f', 'foreman', 'supervisor', 'ppc'];
        
        // Jabatan Dinamis dari Line
        $lines = \App\Models\Produksi\Master\MsProductionLine::all();
        foreach ($lines as $line) {
            $kode = strtolower(substr($line->NamaProductionLine, -1));
            $paten[] = "leader $kode";
            $paten[] = "foreman $kode";
        }
        
        return array_unique($paten);
    }

    public function index(Request $request)
    {
        // Menggunakan paginate(10) supaya rapi
        $karyawan = MsKaryawan::where('Status', 1)
            ->orderBy('NamaKaryawan', 'asc')
            ->paginate(10); 

        return view('Produksi.master.employee.index', compact('karyawan'));
    }

    public function create()
    {
        $lines = \App\Models\Produksi\Master\MsProductionLine::all();
        return view('Produksi.master.employee.create', compact('lines'));
    }

    public function store(Request $request)
    {
        $validJabatan = $this->getValidJabatan();

        $request->validate([
            // POIN 6: 'unique' dihapus dari NamaKaryawan
            'NamaKaryawan' => ['required', 'regex:/^[a-zA-Z\s]+$/'],
            // POIN 8: NRP dibatasi max_digits:6
            'NRPKaryawan'  => ['required', 'numeric', 'max_digits:6', 'unique:prod_mskaryawan,NRPKaryawan'],
            // POIN 7: Password dibatasi max:12
            'PasswordKaryawan' => 'required|min:4|max:12',
            'Jabatan'          => ['required', Rule::in($validJabatan)],
        ], [
            'NamaKaryawan.required' => 'Nama Karyawan wajib diisi.',
            'NamaKaryawan.regex'    => 'Nama hanya boleh berisi huruf dan spasi.',
            'NRPKaryawan.required'  => 'NRP wajib diisi.',
            'NRPKaryawan.unique'    => 'NRP ini sudah digunakan oleh karyawan lain.',
            'NRPKaryawan.numeric'   => 'NRP harus berupa angka.',
            'NRPKaryawan.max_digits'=> 'NRP tidak boleh lebih dari 6 digit.',
            'PasswordKaryawan.required'  => 'Password wajib diisi.',
            'PasswordKaryawan.min'       => 'Password minimal harus 4 karakter.',
            'PasswordKaryawan.max'       => 'Password maksimal harus 12 karakter.',
            'Jabatan.required'       => 'Jabatan wajib dipilih.',
            'Jabatan.in'             => 'Jabatan yang dipilih tidak valid.',
        ]);

        // Generate ID EMPxxx menggunakan MsKaryawan
        $last = MsKaryawan::orderBy('IdKaryawan', 'desc')->first();
        $number = $last ? ((int) substr($last->IdKaryawan, 3)) + 1 : 1;
        $IdKaryawan = 'EMP' . str_pad($number, 3, '0', STR_PAD_LEFT);

        MsKaryawan::create([
            'IdKaryawan' => $IdKaryawan,
            'NamaKaryawan' => $request->NamaKaryawan,
            'NRPKaryawan' => $request->NRPKaryawan,
            'PasswordKaryawan' => bcrypt($request->PasswordKaryawan),
            'Jabatan' => $request->Jabatan,
            'Status' => 1,
            'create_by' => Auth::user()->NamaKaryawan,
        ]);

        return redirect()->route('master.employee.index')
            ->with('success', 'Data Karyawan Berhasil Diperbarui');
    }

    // Mengubah type hint Karyawan menjadi MsKaryawan agar Route Model Binding jalan
    public function show(MsKaryawan $employee)
    {
        $karyawan = $employee;
        return view('Produksi.master.employee.show', compact('karyawan'));
    }

    public function edit(MsKaryawan $employee)
    {
        $karyawan = $employee;
        
        // Ambil data lines supaya bisa di-loop di dropdown view edit
        $lines = \App\Models\Produksi\Master\MsProductionLine::all();
        
        // CRITICAL: Pastikan 'lines' ada di dalam compact()
        return view('Produksi.master.employee.edit', compact('karyawan', 'lines'));
    }

    public function update(Request $request, MsKaryawan $employee)
    {
        $validJabatan = $this->getValidJabatan();

        $request->validate([
            // POIN 6: 'unique' dihapus dari NamaKaryawan saat update
            'NamaKaryawan' => ['required', 'regex:/^[a-zA-Z\s]+$/'],
            // POIN 8: NRP dibatasi max_digits:6 saat update
            'NRPKaryawan' => [
                'required', 
                'numeric',
                'max_digits:6', 
                Rule::unique('prod_mskaryawan', 'NRPKaryawan')->ignore($employee->IdKaryawan, 'IdKaryawan')
            ],
            // POIN 7: Validasi password max:12 saat update jika diisi
            'PasswordKaryawan' => 'nullable|min:4|max:12',
            'Jabatan' => ['required', Rule::in($validJabatan)],
        ], [
            'NRPKaryawan.unique'        => 'NRP sudah digunakan oleh karyawan lain!',
            'NamaKaryawan.required'     => 'Nama Karyawan wajib diisi.',
            'NRPKaryawan.required'      => 'NRP wajib diisi.',
            'NRPKaryawan.numeric'       => 'NRP harus berupa angka.',
            'NRPKaryawan.max_digits'    => 'NRP tidak boleh lebih dari 6 digit.',
            'PasswordKaryawan.min'      => 'Password minimal harus 4 karakter.',
            'PasswordKaryawan.max'      => 'Password maksimal harus 12 karakter.',
            'Jabatan.required'          => 'Jabatan wajib dipilih.',
            'Jabatan.in'                => 'Jabatan yang dipilih tidak valid atau Line terkait sudah tidak aktif.',
        ]);

        $employee->update([
            'NamaKaryawan' => $request->NamaKaryawan,
            'NRPKaryawan' => $request->NRPKaryawan,
            'PasswordKaryawan' => $request->PasswordKaryawan 
                ? bcrypt($request->PasswordKaryawan) 
                : $employee->PasswordKaryawan,
            'Jabatan' => $request->Jabatan,
            'update_by' => Auth::user()->NamaKaryawan,
        ]);

        return redirect()->route('master.employee.index')
            ->with('success', 'Data Karyawan Berhasil Diperbarui');
    }

    public function destroy($id)
    {
        // 1. CARI MANUAL PAKAI WHERE (Biar nggak nyasar ke kolom 'id')
        $employee = \App\Models\Produksi\Master\MsKaryawan::where('IdKaryawan', $id)->first();

        // Kalau datanya beneran nggak ada (jaga-jaga)
        if (!$employee) {
            return redirect()->route('master.employee.index')
                ->with('error', 'Data Karyawan tidak ditemukan!');
        }

        // 2. CEK RELASI KE PRODUCTION SCHEDULE
        $isUsedInSchedule = \Illuminate\Support\Facades\DB::table('prod_trsplanscheduleproduction')
            ->where('IdKaryawan', $employee->IdKaryawan)
            ->orWhere('create_by', $employee->NamaKaryawan)
            ->exists();

        // 3. JIKA DIPAKAI, TOLAK MENTAH-MENTAH!
        if ($isUsedInSchedule) {
            return redirect()->route('master.employee.index')
                ->with('error', 'Gagal! Karyawan ini tidak bisa dihapus karena masih terhubung pada transaksi atau data lain.');
        }

        // 4. JIKA AMAN, BARU EKSEKUSI HAPUS
        $employee->delete();
        
        return redirect()->route('master.employee.index')
            ->with('success', 'Data Karyawan Berhasil Dihapus');
    }
}