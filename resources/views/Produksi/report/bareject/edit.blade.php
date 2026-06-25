@extends('Produksi.layouts.main')

@section('title', 'Update BA Reject')
@section('page-title', 'Update BA Reject')

@section('content')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .form-section { 
        background: #fff; 
        border: 2px solid #343a40; 
        border-radius: 12px; 
        padding: 30px; 
        margin-bottom: 25px; 
    }
    .label-custom { 
        font-size: 13px; 
        font-weight: 700; 
        margin-bottom: 10px; 
        display: block; 
        color: #333; 
    }
    .input-custom { 
        border-radius: 10px; 
        height: 42px; 
        border: 1.5px solid #343a40; 
        box-sizing: border-box; 
        font-size: 13px; 
        width: 100%; 
        padding: 0 10px;
    }
    .textarea-custom { 
        border-radius: 10px; 
        border: 1.5px solid #343a40; 
        padding: 12px; 
        resize: none; 
        font-size: 13px; 
        width: 100%; 
    }

    /* 🛠️ FORCE STYLE PLACEHOLDER ABU-ABU SINKRON DENGAN CREATE */
    select.input-custom option[value=""] {
        color: #888 !important;
    }
    select.input-custom:invalid {
        color: #888 !important;
    }
    select.input-custom {
        color: #333;
    }
</style>

<div class="breadcrumb">
    <span>IPS</span> <span class="separator">></span> <span>Report</span> <span class="separator">></span>
    <a href="{{ route('report.bareject.index') }}" style="text-decoration:none; color:inherit;">BA Reject</a> <span class="separator">></span>
    <span class="active">Update Data</span>
</div>

<div class="page-container">
    <form action="{{ route('report.bareject.update', $data->id) }}" method="POST" id="formEditBaReject">
        @csrf
        @method('PUT')
        
        <div class="form-section">
            {{-- BARIS 1: INFO TANGGAL & AREA --}}
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
                <div class="form-group">
                    <label class="label-custom">Tanggal Kejadian <span style="color: red;">*</span></label>
                    <input type="date" name="tanggal_ba" class="form-control input-custom" 
                           value="{{ old('tanggal_ba', isset($data->inputHarian) ? $data->inputHarian->TanggalProduksi : date('Y-m-d', strtotime($data->created_at))) }}" required>
                </div>
                <div class="form-group">
                    <label class="label-custom">Area Problem <span style="color: red;">*</span></label>
                    <select name="AreaProblem" class="form-select input-custom" required style="width: 100%;">
                        <option value="" disabled hidden>Pilih Area</option>
                        @foreach(['OP-10', 'OP-20', 'OP-30', 'OP-40'] as $area)
                            <option value="{{ $area }}" {{ old('AreaProblem', $data->AreaProblem) == $area ? 'selected' : '' }}>{{ $area }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- BARIS 2: ITEM, QTY A/B, JENIS, DAMAGE --}}
            <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr 1.2fr; gap: 20px; margin-bottom: 30px;">
                <div class="form-group">
                    <label class="label-custom">Item / Job Number <span style="color: red;">*</span></label>
                    <select name="IdItemProduksi" id="selectItem" class="form-select input-custom" onchange="checkItemType(this)" required>
                        <option value="">- Select Item -</option>
                        @foreach($allItems as $it)
                            <option value="{{ $it->IdItemProduksi }}" 
                                    data-job="{{ $it->JobNumber }}" 
                                    {{ $data->IdItemProduksi == $it->IdItemProduksi ? 'selected' : '' }}>
                                {{ $it->JobNumber }} | {{ $it->NamaPart }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label class="label-custom">QTY A <span style="color: red;">*</span></label>
                    <input type="number" name="RejectA" id="qtyA" class="form-control input-custom" 
                           value="{{ old('RejectA', $data->RejectA) }}" required style="text-align: center;">
                </div>

                <div class="form-group">
                    <label class="label-custom">QTY B</label>
                    <input type="number" name="RejectB" id="qtyB" class="form-control input-custom" 
                           value="{{ old('RejectB', $data->RejectB) }}" style="text-align: center;">
                </div>

                <div class="form-group">
                    <label class="label-custom">Jenis Reject <span style="color: red;">*</span></label>
                    <select name="IdReject" class="form-select input-custom" required>
                        <option value="">- Select Type -</option>
                        @foreach($masterReject as $ms)
                            <option value="{{ $ms->IdReject }}" {{ $data->IdReject == $ms->IdReject ? 'selected' : '' }}>{{ $ms->TipeReject }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label class="label-custom">Nama Kerusakan <span style="color: red;">*</span></label>
                    <select name="NamaKerusakan" id="selectKerusakan" class="form-select input-custom" onchange="autoFillPenyebab(this)" required>
                        <option value="">- Select Damage -</option>
                        @php
                            $listKerusakan = ['CRACK', 'NECK', 'GELOMBANG OVER', 'KELIPET', 'BARET OVER', 'BENJOL OVER', 'TWIST', 'HOLE VARIAN NG', 'KARAT OVER', 'PENYOK/DEFORM', 'MATERIAL NG', 'MINUS OVER', 'BALANCING PROCESS', 'REJECT TRIAL', 'MARKING NG', 'DOUBLE LINE (PROFIL)', 'MATERIAL MENUMPANG'];
                            $currentKerusakan = $data->NamaKerusakan ?? '';
                        @endphp
                        @foreach($listKerusakan as $opt)
                            <option value="{{ $opt }}" {{ $currentKerusakan == $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- ANALISA PENYEBAB --}}
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div class="form-group">
                    <label class="label-custom">Penyebab (Root Cause) <span style="color: red;">*</span></label>
                    <textarea name="Penyebab" id="textareaPenyebab" class="form-control textarea-custom" rows="5" placeholder="Penyebab otomatis terisi..." style="background-color: #f8f9fa;" readonly>{{ old('Penyebab', $data->Penyebab) }}</textarea>
                </div>
                <div class="form-group">
                    <label class="label-custom">Counter Measure (Perbaikan)</label>
                    <textarea name="CounterMeasure" class="form-control textarea-custom" rows="5" placeholder="Input langkah perbaikan...">{{ old('CounterMeasure', $data->CounterMeasure) }}</textarea>
                </div>
            </div>
        </div>

        {{-- TOMBOL AKSION --}}
        <div class="form-actions" style="display: flex; gap: 12px; margin-top: 20px; padding-bottom: 30px;">
            <button type="button" class="btn btn-primary" onclick="confirmUpdate()" style="background: #4361ee; border: none; padding: 10px 40px; border-radius: 10px; color:white; font-weight:bold; cursor:pointer;">Update</button>
            <a href="{{ route('report.bareject.index') }}" class="btn btn-outline" style="padding: 10px 30px; border-radius: 10px; text-decoration:none; color:#666; border:1.5px solid #343a40; background-color: #ffffff; font-weight:700;">Cancel</a>
        </div>
    </form>
</div>

<script>
    // 1. Data kamus mapping penyebab resmi IPS Astra
    const mappingPenyebab = {
        'CRACK': 'Tarikan Part Terlalu Kuat Dan Melebihi Batas Maksimal Elastisitas Material',
        'NECK': 'Penipisan Material yang Melebihi Batas Maksimal akibat Tarikan Part Terlalu Kuat',
        'GELOMBANG OVER': 'Tarikan Part loss dan Lekukan Gelombang lebih dari 3 alur',
        'KELIPET': 'Tarikan Part Loss Dan Terlipat pada Area Profil',
        'BARET OVER': 'Surface Atau Permukaan Dies pada Area Upper atau Lower Dies Kasar Sehingga Menimbulkan garis Gesekan Yang sudah tembus Hingga Bagian Dalam Panel',
        'BENJOL OVER': 'Tarikan Part Tertahan dan Terakumulasi pada Satu Area Saat proses Pembentukan Part Draw',
        'TWIST': 'Profil Part melintir Akibat terjatuh, Handling Tidak Sesuai atau Tertindih',
        'HOLE VARIAN NG': 'Hole Varian Tidak Ada, Kurang Atau Tidak Sesuai Sample Part',
        'KARAT OVER': 'Material Storage Terkontaminasi Air atau Uap Air',
        'PENYOK/DEFORM': 'Handling Part Terjatuh Dari Handling Robot /Vacum Miss',
        'MATERIAL NG': 'Specifikasi Material Yang Digunakan Salah/tidak Sesuai',
        'MINUS OVER': 'Tarikan Part Draw Tidak Konstan, Penyok Material Yang Terproses Dan Mocel Atau Penempatan Material Tidak Fix',
        'BALANCING PROCESS': 'Reject Part Separating Yang Ditemukan Sebelum Proses Finish',
        'REJECT TRIAL': 'Reject EX Trial dan Ex WIP Repair Dies',
        'MARKING NG': 'Bottom Mark, Initial ID, Embos Special Tidak Ada Atau Tidak Sesuai Sample Part',
        'DOUBLE LINE (PROFIL)': 'Proses Penempatan Part Pada Proses Restrike/Bending Un-match',
        'MATERIAL MENUMPANG': 'Posisi Material Menumpang Di Stopper Dies Draw Dan Terproses'
    };

    // 2. Fungsi Auto-Fill Penyebab
    function autoFillPenyebab(selectElement) {
        if (!selectElement || selectElement.selectedIndex === -1) return;
        
        const damageKey = selectElement.options[selectElement.selectedIndex].text.trim().toUpperCase();
        const textarea = document.getElementById('textareaPenyebab');
        
        if (!textarea) return;

        if (mappingPenyebab[damageKey]) {
            textarea.value = mappingPenyebab[damageKey];
        } else {
            textarea.value = ""; 
        }
    }

    // 3. Fungsi Pengecekan Joint Job QTY B (Elastis Pas Load & Ganti Pilihan)
    function checkItemType(selectElement) {
        const qtyBField = document.getElementById('qtyB');
        if(!qtyBField) return;

        if(!selectElement) {
            qtyBField.disabled = true;
            qtyBField.style.backgroundColor = "#e9ecef";
            qtyBField.value = 0;
            return;
        }

        const jobNum = selectElement.getAttribute('data-job') || "";
        const isJoint = jobNum.includes('/');
        
        qtyBField.disabled = !isJoint;
        qtyBField.style.backgroundColor = !isJoint ? "#e9ecef" : "#ffffff";
        if(!isJoint) qtyBField.value = 0;
    }

    // 4. Initializer Saat Halaman Pertama Kali Dibuka (DOM Ready)
    $(document).ready(function() {
        // Inisialisasi Select2
        $('.select2-search').select2({
            placeholder: "- Select Item -",
            allowClear: true
        });

        $('#selectItem').on('select2:select', function (e) {
            checkItemType(e.params.data.element);
        });

        $('#selectItem').on('select2:clear', function (e) {
            checkItemType(null);
        });
        
        // Panggil checkItemType saat load (untuk ngatur QtyB berdasarkan data yang udah kesimpen di database)
        const currentSelected = $('#selectItem').find(':selected')[0];
        if(currentSelected && currentSelected.value !== "") {
            checkItemType(currentSelected);
        } else {
            checkItemType(null);
        }
    });

    // 5. Konfirmasi Update via SweetAlert2 (KONFIRMASI DULU, BARU CEK ERROR)
    function confirmUpdate() {
        // MUNCULIN POP-UP KONFIRMASI DI AWAL BANGET
        Swal.fire({
            title: 'Perbarui BA Reject Data?',
            text: "Pastikan Semua Data Yang Dimasukkan Sudah Benar.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6', // Warna biru 
            cancelButtonColor: '#6c757d',  // Warna abu-abu
            confirmButtonText: 'Update',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            // KALAU USER KLIK 'UPDATE', BARU KITA CEK DATANYA
            if (result.isConfirmed) {
                const form = document.getElementById('formEditBaReject');
                let errorList = [];

                // --- Cek ada data yang kosong nggak ---
                if (!$('input[name="tanggal_ba"]').val()) errorList.push("Tanggal Kejadian wajib diisi.");
                if (!$('select[name="AreaProblem"]').val()) errorList.push("Area Problem wajib dipilih.");
                if (!$('#selectItem').val()) errorList.push("Item / Job Number wajib dipilih.");
                
                const qtyA = $('#qtyA').val();
                if (!qtyA || qtyA <= 0) errorList.push("Quantity Reject A minimal harus 1.");
                
                if (!$('select[name="IdReject"]').val()) errorList.push("Jenis Reject wajib dipilih.");
                if (!$('#selectKerusakan').val()) errorList.push("Nama Kerusakan wajib dipilih.");

                // --- Kalau ada yang kosong, timpa pop-up pake SweetAlert Error ---
                if (errorList.length > 0) {
                    let listHtml = `
                        <div style="text-align: left; font-size: 14px; color: #555;">
                            <p style="margin-bottom: 10px;">Mohon periksa kembali data yang Anda masukkan:</p>
                            <ul style="margin-top: 0; padding-left: 20px;">
                    `;
                    errorList.forEach(function(err) {
                        listHtml += `<li>${err}</li>`;
                    });
                    listHtml += `</ul></div>`;

                    Swal.fire({
                        icon: 'error',
                        title: 'Terjadi kesalahan',
                        html: listHtml,
                        confirmButtonColor: '#e11d2e', // Merah Astra
                        confirmButtonText: 'OK'
                    });
                } 
                // --- Kalau datanya udah lengkap semua, kirim datanya! ---
                else {
                    const qtyBField = document.getElementById('qtyB');
                    if (qtyBField && qtyBField.disabled) {
                        qtyBField.disabled = false;
                    }
                    form.submit(); 
                }
            }
        });
    }
</script>
@endsection