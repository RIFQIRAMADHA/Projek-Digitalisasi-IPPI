@extends('Produksi.layouts.main')

@section('title', 'BA Reject')
@section('page-title', 'BA Reject Report')

@section('card-actions')
<div class="export-actions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a href="{{ route('report.bareject.create') }}" class="btn-add-data">+ Add BA Reject</a>
    <button onclick="doExport('excel')" class="btn-export-excel">EXPORT EXCEL</button>
    <button onclick="doExport('pdf')" class="btn-export-pdf">EXPORT PDF</button>
</div>
@endsection

@section('content')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* 🔥 1. WRAPPER TABEL (Tambahin max-height & overflow-y) */
    .table-responsive-wrapper {
        width: 100%;
        background: #fff;
        padding: 0; /* Ubah ke 0 biar sticky-nya nempel rapi ke ujung kotak */
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        margin-top: 15px;
        overflow-x: auto;
        overflow-y: auto; /* Wajib biar bisa di-scroll internal */
        max-height: 600px; /* Batas tinggi tabel */
        border: 1px solid #e3e6f0;
        box-sizing: border-box;
    }

    /* 🔥 2. TABEL (Ubah ke separate biar border gak pecah pas scroll) */
    #barejectTable {
        width: 100%;
        min-width: 1350px;
        border-collapse: separate !important; 
        border-spacing: 0;
        font-size: 11px;
    }

    /* 🔥 3. HEADER TABEL (Bikin posisi sticky) */
    .table-reject thead th { 
        position: sticky !important; 
        background-color: #bb2121 !important; 
        color: white !important; 
        border-bottom: 1px solid #fff !important; 
        border-right: 1px solid #fff !important; 
        padding: 12px 8px;
        vertical-align: middle;
        text-align: center;
        white-space: nowrap;
        z-index: 10;
    }

    /* Tutup border kiri biar gak bolong */
    .table-reject thead th:first-child {
        border-left: 1px solid #fff !important; 
    }

    /* 🔥 JURUS HEADER BERTINGKAT */
    /* Baris 1 nempel di paling atas (0) */
    .table-reject thead tr:nth-child(1) th {
        top: 0;
        z-index: 11; /* Z-index lebih tinggi biar nutupin baris bawahnya */
    }
    
    /* Baris 2 (Dies, Mach, dll) nempel di bawah baris 1 */
    .table-reject thead tr:nth-child(2) th {
        top: 39px; /* Sesuaikan angka ini misal baris kedua ada celah/numpuk */
        z-index: 10;
    }

    /* 🔥 4. BODY TABEL (Atur border untuk mode separate) */
    .table-reject tbody td { 
        border-bottom: 1px solid #dee2e6 !important; 
        border-right: 1px solid #dee2e6 !important; 
        padding: 10px 8px; 
        vertical-align: middle; 
        text-align: center; 
        color: #333;
    }

    .table-reject tbody td:first-child {
        border-left: 1px solid #dee2e6 !important;
    }

    .text-left-wrap {
        text-align: left !important;
        min-width: 250px;
        max-width: 350px;
        white-space: normal !important;
        line-height: 1.5;
    }

    .btn-add-data, .btn-export-excel, .btn-export-pdf {
        height: 36px; padding: 0 16px; border-radius: 8px; font-weight: bold; font-size: 11.5px;
        display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; text-decoration: none;
    }
    .btn-add-data { background-color: #4361ee; color: white; }
    .btn-export-excel { background-color: #28a745; color: white; }
    .btn-export-pdf { background-color: #f82b3d; color: white; }

    .btn-action-view, .btn-action-edit, .btn-action-delete {
        padding: 5px 12px; border-radius: 6px; font-size: 10px; font-weight: bold; text-decoration: none; display: inline-block; margin: 2px;
    }
    .btn-action-view { background-color: #fff; color: #333; border: 1px solid #ddd; }
    .btn-action-edit { background-color: #4e73df; color: #fff; }
    .btn-action-delete { background-color: #e74a3b; color: #fff; border: none; }
    
    .badge-status { padding: 6px 12px; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 10px; min-width: 85px; }
    .badge-done { background-color: #28a745; color: white; }
    .badge-progress { background-color: #448aff; color: white; }

    .bg-gray-custom { background-color: #f8f9fc !important; font-weight: bold; }

    /* CUSTOM STYLE UNTUK SELECT2 DI INDEX BA REJECT */
    .select2-container--default .select2-selection--single {
        height: 38px !important;
        border: 1px solid #ddd !important;
        border-radius: 8px !important;
        display: flex !important;
        align-items: center;
        background-color: #fff;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 36px !important;
        font-size: 12px;
        font-weight: 600;
        color: #333;
        padding-left: 12px;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }
    .select2-dropdown {
        border: 1px solid #ddd !important;
        border-radius: 8px !important;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        font-size: 12px;
    }

    /* =========================================
       🔥 CUSTOM PAGINATION STYLE (RED ASTRA THEME)
       ========================================= */
    .pagination-wrapper {
        margin-top: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        width: 100%;
    }

    .pagination-wrapper .pagination { 
        display: flex; 
        justify-content: center; 
        gap: 5px; 
        list-style: none; 
        padding: 0; 
        margin: 0; 
    }
    
    .pagination-wrapper .page-item .page-link { 
        padding: 8px 16px; 
        border-radius: 8px !important; 
        border: 1px solid #ddd; 
        color: #f82b3d !important; 
        text-decoration: none; 
        font-weight: 600; 
        transition: all 0.3s; 
        background-color: #fff;
    }
    
    .pagination-wrapper .page-item:not(.active):not(.disabled) .page-link:hover {
        background-color: #ffe6e8;
        border-color: #f82b3d;
        color: #f82b3d !important;
    }

    .pagination-wrapper .page-item.active .page-link { 
        background-color: #f82b3d !important; 
        color: #fff !important; 
        border-color: #f82b3d !important; 
        z-index: 3;
    }
    
    .pagination-wrapper .page-item.disabled .page-link { 
        color: #f82b3d !important; 
        opacity: 0.5; 
        cursor: not-allowed; 
        background-color: #f9f9f9 !important; 
        border-color: #eee !important;
        pointer-events: none;
    }
</style>

<div class="breadcrumb">
    <span>IPS</span> <span class="separator">></span>
    <span>Report</span> <span class="separator">></span>
    <span class="active" style="color: #e11d2e; font-weight: 800;">BA Reject</span>
</div>

{{-- TOOLBAR FILTERS --}}
<div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #e3e6f0;">
    <div style="display: flex; align-items: center; gap: 6px;">
        <label style="font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase;">From:</label>
        <input type="date" id="filterStartDate" value="{{ $startDate }}" onchange="updateFilter()" 
            style="height: 38px; border-radius: 8px; border: 1px solid #ddd; padding: 0 12px; font-weight: 600;">
    </div>

    <div style="display: flex; align-items: center; gap: 6px;">
        <label style="font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase;">To:</label>
        <input type="date" id="filterEndDate" value="{{ $endDate }}" onchange="updateFilter()" 
            style="height: 38px; border-radius: 8px; border: 1px solid #ddd; padding: 0 12px; font-weight: 600;">
    </div>

    <div style="display: flex; align-items: center; gap: 6px; margin-left: 10px;">
        <label style="font-size: 11px; font-weight: bold; color: #666; text-transform: uppercase;">Material:</label>
        <div style="min-width: 280px; width: 320px; max-width: 100%;">
            <select id="filterMaterial" class="form-select select2-material" onchange="updateFilter()">
                <option value="">-- All Materials --</option>
                @foreach($materials as $mat)
                    @if(!empty(trim($mat->IdItemProduksi)) && !empty(trim($mat->JobNumber)))
                        <option value="{{ $mat->IdItemProduksi }}" {{ request('material') == $mat->IdItemProduksi ? 'selected' : '' }}>
                            {{ trim($mat->JobNumber) }} - {{ trim($mat->NamaPart) }}
                        </option>
                    @endif
                @endforeach
            </select>
        </div>
    </div>
</div>

<div class="table-responsive-wrapper">
    <table class="table-reject" id="barejectTable">
        <thead>
            <tr>
                <th rowspan="2" style="width: 40px;"><input type="checkbox" id="checkAll"></th>
                <th rowspan="2" style="width: 90px;">Tanggal</th>
                <th rowspan="2" style="width: 100px;">Job Number</th>
                <th rowspan="2" style="width: 50px;">QTY</th>
                <th rowspan="2" style="width: 70px;">Berat/PCS</th>
                <th rowspan="2" style="width: 80px;">Berat Total</th>
                <th colspan="4">Penyebab Scrap</th>
                <th rowspan="2" style="width: 120px;">Jenis Kerusakan</th>
                <th rowspan="2">Penyebab</th>
                <th rowspan="2">Counter Measure</th>
                <th colspan="2">MATERIAL</th>
                <th rowspan="2" style="width: 180px;">Aksi</th>
                <th rowspan="2" style="width: 100px;">Status</th>
            </tr>
            <tr>
                <th style="width: 45px;">Dies</th><th style="width: 45px;">Mach</th><th style="width: 45px;">Mat</th><th style="width: 45px;">Meth</th>
                <th style="width: 60px;">IPPI</th><th style="width: 70px;">CUSTOMER</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($item as $index => $row)
            @php
                $harian = optional($row->inputHarian);
                $prodItem = $harian->exists ? optional($harian->item) : optional($row->item);
                $qty = $row->Qty ?? 0;
                $beratPcs = $prodItem->Berat ?? 0;
                $beratTotal = $beratPcs * $qty;
                $area = strtolower($row->TipeReject ?? optional($row->masterReject)->TipeReject ?? '');
                $namaCust = strtoupper(optional($prodItem->customer)->NamaCustomer ?? '');
                $isADM = str_contains($namaCust, 'ADM');
                $tanggalRow = $harian->TanggalProduksi ? \Carbon\Carbon::parse($harian->TanggalProduksi)->format('d/m/Y') : \Carbon\Carbon::parse($row->created_at)->format('d/m/Y');
            @endphp
            <tr style="background-color: {{ $index % 2 != 0 ? '#fcfcfc' : '#fff' }};">
                <td><input type="checkbox" class="rowCheckbox" value="{{ $row->id }}"></td>
                <td>{{ $tanggalRow }}</td>
                <td style="font-weight: 800;">{{ $prodItem->JobNumber ?? '-' }}</td>
                <td>{{ number_format($qty, 0) }}</td>
                <td>{{ number_format($beratPcs, 2) }}</td>
                <td style="font-weight: 800;">{{ number_format($beratTotal, 2) }}</td>
                
                <td class="bg-gray-custom">{{ in_array($area, ['dies', 'op-10']) ? number_format($qty, 0) : '0' }}</td>
                <td class="bg-gray-custom">{{ in_array($area, ['machine', 'mach', 'op-20']) ? number_format($qty, 0) : '0' }}</td>
                <td class="bg-gray-custom">{{ in_array($area, ['material', 'mat', 'op-30']) ? number_format($qty, 0) : '0' }}</td>
                <td class="bg-gray-custom">{{ in_array($area, ['method', 'meth', 'op-40']) ? number_format($qty, 0) : '0' }}</td>
                
                <td style="font-weight: 700;">{{ $row->NamaKerusakan ?? (optional($row->masterReject)->NamaReject ?? '-') }}</td>
                <td class="text-left-wrap">{{ $row->Penyebab ?? '-' }}</td>
                <td class="text-left-wrap">{{ $row->CounterMeasure ?? '-' }}</td>
                
                <td class="bg-gray-custom">{{ !$isADM ? 'IPPI' : '-' }}</td>
                <td class="bg-gray-custom">{{ $isADM ? 'ADM' : '-' }}</td>
                
                <td>
                    <div style="display: flex; justify-content: center;">
                        <a href="{{ route('report.bareject.show', $row->id) }}" class="btn-action-view">View</a>
                        <a href="{{ route('report.bareject.edit', $row->id) }}" class="btn-action-edit">Update</a>
                        <button type="button" onclick="deleteData('{{ $row->id }}')" class="btn-action-delete">Delete</button>
                    </div>
                </td>
                <td>
                    <span class="badge-status {{ ($row->Status == 'Done' || $row->Status == 1) ? 'badge-done' : 'badge-progress' }}">
                        {{ ($row->Status == 'Done' || $row->Status == 1) ? 'DONE' : 'PROGRESS' }}
                    </span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="16" class="text-center py-4 text-muted" style="background-color: #ffffff; font-size: 13px;">
                    Data tidak tersedia.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 🔥 CONTAINER PAGINATION CUSTOM DI TENGAH --}}
<div class="pagination-wrapper">
    <div>
        {{ $item->appends(['start_date' => request('start_date'), 'end_date' => request('end_date'), 'material' => request('material')])->links('pagination::bootstrap-4') }}
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// 🛠️ MODIFIKASI: Update filter parameter menggunakan start_date & end_date
function updateFilter() {
    const startDate = document.getElementById('filterStartDate').value;
    const endDate = document.getElementById('filterEndDate').value;
    const material = document.getElementById('filterMaterial').value;
    
    let url = window.location.pathname + '?start_date=' + startDate + '&end_date=' + endDate;
    if (material) {
        url += '&material=' + material;
    }
    
    window.location.href = url;
}

document.getElementById('checkAll').addEventListener('click', function() {
    document.querySelectorAll('.rowCheckbox').forEach(cb => cb.checked = this.checked);
});

function deleteData(id) {
    Swal.fire({
        title: 'Apakah Anda Yakin Ingin Menghapus Data Ini?', text: "Data Tersebut Akan Dihapus Secara Permanen dan Tidak Dapat Dipulihkan.", icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#f82b3d', confirmButtonText: 'Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`{{ url('report/ba-reject/delete') }}/${id}`, {
                method: 'DELETE', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
            }).then(res => res.json()).then(data => {
                if(data.success) Swal.fire('Success!', data.message, 'success').then(() => location.reload());
            });
        }
    });
}

function doExport(type) {
    if (window.event) {
        window.event.preventDefault();
    }

    console.log("Tombol Export diklik!");
    const selectedRows = document.querySelectorAll('.rowCheckbox:checked');
    if (selectedRows.length === 0) {
        Swal.fire('Terjadi Kesalahan', 'Pilih satu item!', 'warning');
        return;
    }

    let statuses = [];
    selectedRows.forEach(cb => {
        let row = cb.closest('tr');
        let badge = row.querySelector('.badge-status');
        statuses.push(badge ? badge.textContent.trim() : 'PROGRESS');
    });

    const hasDone = statuses.includes('DONE');
    const hasProgress = statuses.includes('PROGRESS');

    if (hasDone && hasProgress) {
        Swal.fire('Terjadi Kesalahan', 'Anda tidak boleh menggabungkan data DONE dengan data PROGRESS!', 'error');
        return;
    }

    // ==========================================
    // 3. JIKA SEMUANYA DONE (Popup Keluar + Format Panjang Diisi Otomatis)
    // ==========================================
    if (hasDone && !hasProgress) {
        let firstId = selectedRows[0].value;
        
        Promise.all([
            fetch(`{{ url('report/ba-reject/get-no-ba') }}/${firstId}`).then(res => res.json()),
            fetch("{{ route('report.bareject.ambilNomor') }}").then(res => res.json())
        ])
        .then(([dataBa, dataNomor]) => {
            const angkaMaksimal = parseInt(dataNomor.angka_maksimal);
            
            // 🛠️ RACIK FORMAT PANJANG UNTUK DATA DONE
            // Ekstrak angka urutan dari nomor BA lama (misal dari "BA / 007 / ..." diambil 007)
            const matchLama = dataBa.no_ba ? dataBa.no_ba.match(/BA\s*[\/]\s*(\d+)/) : null;
            const angkaLamaStr = matchLama ? matchLama[1] : String(dataNomor.angka_terakhir).padStart(3, '0');
            
            // Masukkan angka lama tersebut ke dalam template format saran bawaan sistem
            // Hasilnya akan presisi: "BA / 007 / PIC - REJECT / MM / YYYY"
            const formatDonePanjang = dataNomor.format_saran.replace(/BA\s*[\/]\s*(\d+)/, `BA / ${angkaLamaStr}`);

            Swal.fire({
                title: 'Nomor BA Registrasi',
                html: `Nomor Terakhir: <b>${dataNomor.angka_terakhir}</b><br>
                       Nomor baru yang akan digunakan: <b>${angkaLamaStr}</b>`,
                input: 'text',
                inputValue: formatDonePanjang, // 🔥 FIX: Input otomatis terisi format panjang terstandarisasi
                showCancelButton: true,
                confirmButtonText: 'Export',
                cancelButtonText: 'Cancel',
                allowOutsideClick: false,
                preConfirm: (inputValue) => {
                    const match = inputValue.match(/BA\s*[\/]\s*(\d+)/);
                    const angkaInput = match ? parseInt(match[1]) : null;

                    if (!inputValue || !match) {
                        Swal.showValidationMessage('Invalid number format! Make sure it follows the “BA / 000” pattern');
                        return false;
                    }

                    if (angkaInput > angkaMaksimal) {
                        Swal.showValidationMessage(`Mohon untuk tidak melewatkan angka, karena angka maksimum terbaru yang berlaku adalah: ${String(angkaMaksimal).padStart(3, '0')}`);
                        return false;
                    }

                    return {
                        full_text: inputValue,
                        angka_final: angkaInput
                    };
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    performExport(type, result.value.full_text, false, result.value.angka_final);
                }
            });
        });
    } 
    // ==========================================
    // 4. JIKA SEMUANYA PROGRESS (Saran Nomor Baru)
    // ==========================================
    else {
        fetch("{{ route('report.bareject.ambilNomor') }}")
            .then(res => res.json())
            .then(data => {
                const angkaMaksimal = parseInt(data.angka_maksimal); 

                Swal.fire({
                    title: 'Nomor BA Registrasi',
                    html: `Nomor Terakhir: <b>${data.angka_terakhir}</b><br>
                    Nomor baru yang akan digunakan: <b>${String(data.angka_baru).padStart(3, '0')}</b>`,
                    input: 'text',
                    inputValue: data.format_saran, 
                    showCancelButton: true,
                    confirmButtonText: 'Export',
                    cancelButtonText: 'Cancel',
                    allowOutsideClick: false,
                    preConfirm: (inputValue) => {
                        const match = inputValue.match(/BA\s*[\/]\s*(\d+)/);
                        const angkaInput = match ? parseInt(match[1]) : null;

                        if (!angkaInput) {
                            Swal.showValidationMessage('Format angka tidak valid! Pastikan formatnya mengikuti pola “BA / 000”');
                            return false;
                        }

                        if (angkaInput > angkaMaksimal) {
                            Swal.showValidationMessage(`Mohon untuk tidak melewatkan angka, karena angka maksimum terbaru yang berlaku adalah: ${String(angkaMaksimal).padStart(3, '0')}`);
                            return false;
                        }

                        return {
                            full_text: inputValue,
                            angka_final: angkaInput
                        };
                    }
                }).then((result) => {
                    if (result.isConfirmed && result.value) {
                        performExport(type, result.value.full_text, true, result.value.angka_final);
                    }
                });
            });
    }
}

// 🛠️ FUNGSIONAL UTAMA: Membangun susunan parameter link download browser
function performExport(type, noBa, updateCounter, angkaSekarang = null) {
    const startDate = document.getElementById('filterStartDate').value;
    const endDate = document.getElementById('filterEndDate').value;
    const selected = Array.from(document.querySelectorAll('.rowCheckbox:checked')).map(cb => cb.value);
    
    let url = new URL(type === 'excel' ? "{{ route('report.bareject.excel') }}" : "{{ route('report.bareject.pdf') }}", window.location.origin);
    
    url.searchParams.append('start_date', startDate);
    url.searchParams.append('end_date', endDate);
    if (noBa) url.searchParams.append('no_register', noBa);
    url.searchParams.append('update_counter', updateCounter);
    if (angkaSekarang) url.searchParams.append('angka_sekarang', angkaSekarang);
    if (selected.length > 0) url.searchParams.append('ids', selected.join(','));
    
    window.location.href = url.href;
}

function searchTable(value) {
    value = value.toLowerCase();
    document.querySelectorAll("#barejectTable tbody tr").forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(value) ? "" : "none";
    });
}

$(document).ready(function() {
        $('.select2-material').select2({
            placeholder: "-- All Materials --",
            allowClear: true,
            width: '100%' // Biar nyesuain sama div bungkusannya
        });

        // Trigger fungsi updateFilter (bawaan dari lu) saat Select2 diganti atau dihapus (clear)
        $('#filterMaterial').on('select2:select select2:unselect', function (e) {
            updateFilter();
        });
    });
</script>
@endsection