@extends('Produksi.layouts.main')

@section('title', 'Daily Input')
@section('page-title', 'Daily Input')

@section('content')

<meta name="csrf-token" content="{{ csrf_token() }}">

<style>
    /* 1. Wrapper Tetap */
    .table-responsive-wrapper { 
        width: 100%; 
        max-height: 600px; 
        overflow: auto !important; 
        border: 1px solid #ffffff; 
        margin-top: 10px; 
        background: #a1a1aa;
        position: relative; 
        isolation: isolate; 
    }

    /* 2. Fix Tabel Layout tanpa ngerusak sticky */
    #inputHarianTable { 
        width: 100%; 
        min-width: 1900px;
        border-collapse: separate !important; 
        border-spacing: 0; 
        table-layout: fixed; 
    }

    #inputHarianTable th:nth-child(1) { width: 250px; }
    #inputHarianTable th:nth-child(2) { width: 140px; }
    #inputHarianTable th:nth-last-child(1) { width: 100px; }

    #inputHarianTable th, #inputHarianTable td { 
        border-bottom: 1px solid #ffffff !important; 
        border-right: 1px solid #ffffff !important;
        vertical-align: middle; 
        padding: 8px 5px !important;
        overflow: hidden; 
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* KUNCI STICKY */
    .table-input-harian thead th { position: sticky !important; top: 0 !important; background-color: #b71c1c !important; color: white; text-transform: uppercase; font-size: 11px; z-index: 50 !important; }
    .item-job-cell { position: sticky !important; left: 0 !important; background-color: #f1f2f6 !important; z-index: 40 !important; box-shadow: 2px 0 5px rgba(0,0,0,0.15); }
    .table-input-harian thead th:first-child { z-index: 60 !important; background-color: #b71c1c !important; }
    .input-table-custom:disabled { background-color: #d1d5db !important; color: #4b5563 !important; border: 1px solid #9ca3af !important; cursor: not-allowed; }

    /* 4. TOOLBAR BARU (SESUAI STRUKTUR 3 ROW) */
    .table-toolbar {
        display: flex !important;
        flex-wrap: wrap !important;
        align-items: center !important;
        gap: 10px !important;
        background: #f8f9fc;
        padding: 10px 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        border: 1px solid #e3e6f0;
        width: 100%;
        box-sizing: border-box;
    }

    .toolbar-row {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    /* Trik nempel ke kanan: row kedua (search) kita kasih margin-left: auto */
    .toolbar-row:nth-child(2) { margin-left: auto !important; }

    .input-date-custom, .select-line-custom, .input-search {
        height: 38px !important; border-radius: 5px !important; border: 1px solid #ced4da !important;
        padding: 0 10px !important; font-weight: 700 !important; font-size: 13px !important; width: 180px; 
    }
    .table-toolbar .btn { height: 38px !important; padding: 0 15px !important; font-size: 13px !important; font-weight: 800; }

    /* Style tambahan lainnya */
    .flex-cell-container { display: flex; gap: 5px; justify-content: center; align-items: center; }
    .input-table-custom { border: 1.5px solid #ffffff !important; border-radius: 4px; font-weight: 800; text-align: center !important; height: 28px; background: #fff; color: #000; font-size: 11px; }
    .box-mesin-aktif { width: 22px; height: 22px; border: 1px solid #4e73df; background-color: #eaecf4 !important; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; border-radius: 4px; color: #4e73df !important; }

    /* 🔥 MEDIA QUERY (TUMPUK RAPI DI SPLIT) */
    @media (max-width: 1050px) {
        .table-toolbar { flex-direction: column !important; align-items: stretch !important; padding: 15px !important; gap: 10px !important; }
        .toolbar-row { width: 100% !important; flex-direction: column !important; margin-left: 0 !important; gap: 10px !important; }
        .input-date-custom, .select-line-custom, .input-search, .btn { width: 100% !important; margin: 0 !important; box-sizing: border-box !important; display: block !important; }
    }
</style>

{{-- VARIABEL AWAL --}}
@php
    $selectedLine = request('line') ? \App\Models\Produksi\Master\MsProductionLine::find(request('line')) : null;
    $isLineK = $selectedLine && str_contains(strtoupper($selectedLine->NamaProductionLine), 'K');
    $isAllLines = !$selectedLine;
    $showMachineColumn = $isLineK || $isAllLines;
    $userJabatan = strtolower(auth()->user()->Jabatan);
    $isAdminPpc = in_array($userJabatan, ['admin', 'supervisor', 'ppc', 'quality']);
    $isQC = str_contains($userJabatan, 'quality') || str_contains($userJabatan, 'qc');
@endphp

<div class="breadcrumb">
    <span>IPS</span> <span class="separator">></span>
    <span>Transaksi</span> <span class="separator">></span>
    <span class="active">Daily Input</span>
</div>

{{-- MODIFIKASI STRUKTUR HTML TOOLBAR --}}
<div class="table-toolbar">
    {{-- BARIS 1: Filter --}}
    <div class="toolbar-row">
        <input type="date" class="input-date-custom" id="filterDate" value="{{ $tanggal }}" onchange="updateFilter()">
        <select class="select-line-custom" id="filterLine" onchange="updateFilter()">
            <option value="">All Line - All Shift</option>
            @foreach($lines as $l)
                <option value="{{ $l->IdProductionLine }}" {{ request('line') == $l->IdProductionLine ? 'selected' : '' }}>
                    {{ $l->NamaProductionLine }} - {{ $l->Shift }}
                </option>
            @endforeach
        </select>
    </div>
    
    {{-- BARIS 2: Search --}}
    <div class="toolbar-row">
        {{-- 🔥 FIX: Ubah request('search') menjadi $search --}}
        <input type="text" class="input-search" id="searchInput" 
            value="{{ $search ?? '' }}" 
            placeholder="Search Item..." 
            oninput="handleSearch(this.value)">
    </div>

    {{-- BARIS 3: Button --}}
    <div class="toolbar-row">
        <button type="button" class="btn" style="background: #6c5ce7; color: white;" onclick="openDetailPlanModal()">
            <i class="fas fa-list-alt"></i> SCHEDULE PLAN
        </button>

        <button type="button" class="btn" style="background: #f1c40f; color: black; font-weight: 800; font-size: 11px; border-radius: 5px; height: 35px; padding: 0 15px;" onclick="bukaExtraModal()">
            <i class="fas fa-plus me-1"> ADDITIONAL PLAN
        </button>
    </div>
</div>

<div class="table-responsive-wrapper">
    <table class="table-custom table-input-harian" id="inputHarianTable">
        <colgroup>
            <col style="width: 250px;"> 
            @if($showMachineColumn) <col style="width: 140px;"> @endif
            <col style="width: 130px;"> <col style="width: 130px;"> <col style="width: 180px;"> 
            <col style="width: 180px;"> <col style="width: 90px;"> <col style="width: 90px;"> 
            <col style="width: 170px;"> <col style="width: 80px;"> 
            <col style="width: 450px;"> {{-- Kolom Proses & Next --}}
            <col style="width: 100px;"> {{-- Kolom SAVE pindah ke ujung kanan --}}
        </colgroup>
        <thead>
            <tr>
                <th>Nama Item</th>
                @if($showMachineColumn) <th>Mesin</th> @endif
                <th>Plan QTY</th><th>Good</th><th>Repair</th><th>Reject</th>
                <th>TPT Plan</th><th>TPT Actual</th><th>Lose Time</th><th>Break</th>
                <th>Proses & Next Item</th>
                <th>SAVE</th> {{-- Header SAVE pindah ke ujung kanan --}}
            </tr>
        </thead>
        <tbody style="background: #a1a1aa;">
            @forelse ($inputs as $row)
            @php
                $jobNumber = $row->item->JobNumber ?? '-';
                $hasSlash = str_contains($jobNumber, '/');
                $isLocked = ($row->StatusProses === 'Finished');
                $isRunning = ($row->StatusProses === 'Running');
                $isStopped = ($row->StatusProses === 'Stopped');
                $disableInput = ($isLocked || $isQC);
                
                $tptPlanVal = (float)($row->standard_tpt_plan ?? ($row->detailPlan->TPT ?? 0));
                $tptAktualVal = (float)($row->actual_tpt_val ?? ($row->TPT ?? 0));
            @endphp
            <tr data-id="{{ $row->IdInputHarian }}" class="{{ $isStopped ? 'row-stopped' : '' }}">
                
                <td class="item-job-cell">
                    <div style="font-weight: 800; color: #000; white-space: normal; word-break: break-word; line-height: 1.3;">{{ $jobNumber }}</div>
                    <div style="font-size: 10px; font-weight: 700; color: #333; white-space: normal; word-break: break-word;">{{ $row->item->NamaPart ?? '-' }}</div>
                    @if($isRunning) <span class="badge bg-primary badge-status">RUNNING</span>
                    @elseif($isStopped) <span class="badge bg-warning text-dark badge-status">STOPPED</span> @endif
                </td>

                {{-- 🛠️ FIX KONSEP KOLOM MESIN: KOTAK TETEP STANDBY ABU-ABU, ANGKA HANYA JIKA AKTIF --}}
                @if($showMachineColumn)
                <td class="text-center">
                    <div class="flex-cell-container">
                        @for($i = 1; $i <= 5; $i++)
                            @php 
                                $col = "QtyMesin".$i; 
                                // Memastikan pengecekan aman baik dari relation detailPlan maupun langsung dari row
                                $isUsed = ($row->detailPlan && $row->detailPlan->$col > 0) || (isset($row->$col) && $row->$col > 0);
                            @endphp
                            @if($isUsed)
                                {{-- Jika Mesin Aktif / Dipakai: Kotak Biru + Muncul Angka Mesin --}}
                                <div class="box-mesin-aktif">{{ $i }}</div>
                            @else
                                {{-- Jika Mesin Tidak Aktif: Kotak Tetap Muncul Standby Abu-Abu, Tapi Isinya Kosong Melongpong --}}
                                <div style="width: 22px; height: 22px; border: 1px solid #d1d5db; background-color: #f3f4f6 !important; border-radius: 4px; display: flex; align-items: center; justify-content: center;"></div>
                            @endif
                        @endfor
                    </div>
                </td>
                @endif

                <td class="text-center">
                    <div class="flex-cell-container">
                        {{-- Class plan-a ditambahkan dan number_format dihapus biar JS bisa baca angkanya --}}
                        <input type="text" class="input-table-custom plan-a" 
                            value="{{ $row->plan_qty_a }}" disabled style="width: 45px !important;">
                        
                        <input type="text" class="input-table-custom plan-b" 
                            value="{{ $row->plan_qty_b }}" disabled style="width: 45px !important;">
                    </div>
                </td>

                <td class="text-center">
                    <div class="flex-cell-container">
                        <input type="number" onfocus="this.select()" class="good-a input-table-custom" value="{{ $row->GoodA }}" style="width: 45px !important;" {{ $isQC ? 'disabled' : '' }}>
                        <input type="number" onfocus="this.select()" class="good-b input-table-custom" value="{{ $row->GoodB }}" style="width: 45px !important;" {{ ($isQC || !$hasSlash) ? 'disabled' : '' }}>
                    </div>
                </td>

                <td class="text-center">
                    <div class="flex-cell-container">
                        <input type="number" class="input-table-custom" value="{{ $row->RepairA ?? 0 }}" style="width: 40px;" disabled>
                        <input type="number" class="input-table-custom" value="{{ $row->RepairB ?? 0 }}" style="width: 40px;" disabled>
                        <a href="{{ route('inputharian.repair', $row->IdInputHarian) }}" class="btn btn-sm" style="background: #ffffff; border: 1px solid #000; font-size: 10px; padding: 4px 8px; font-weight: 700; text-decoration: none; color: #000; border-radius: 4px;">Detail</a>
                    </div>
                </td>

                <td class="text-center">
                    <div class="flex-cell-container">
                        <input type="number" class="input-table-custom" value="{{ $row->RejectA ?? 0 }}" style="width: 40px;" disabled>
                        <input type="number" class="input-table-custom" value="{{ $row->RejectB ?? 0 }}" style="width: 40px;" disabled>
                        <a href="{{ route('inputharian.reject', $row->IdInputHarian) }}" class="btn btn-sm" style="background: #ffffff; border: 1px solid #000; font-size: 10px; padding: 4px 8px; font-weight: 700; text-decoration: none; color: #000; border-radius: 4px;">Detail</a>
                    </div>
                </td>

                <td class="text-center"> <span class="tpt-box-plan">{{ number_format($tptPlanVal, 1) }}</span> </td>
                <td class="text-center">
                    <span class="tpt-box-aktual {{ ($tptAktualVal > $tptPlanVal) ? 'tpt-over' : '' }}"> {{ number_format($tptAktualVal, 1) }} </span>
                </td>

                <td class="text-center">
                    <div class="flex-cell-container">
                        <input type="text" class="input-table-custom" value="{{ number_format($row->target_loss, 1) }}" style="width: 40px; background-color: #d1d5db !important;" disabled>
                        @php 
                            $nilaiDowntime = (float)($row->actual_downtime ?? 0);
                            $warnaTeksStatus = $nilaiDowntime > 0 ? '#e11d2e' : '#27ae60'; 
                        @endphp
                        <input type="text" class="input-table-custom" value="{{ number_format($nilaiDowntime, 1) }}" style="width: 40px; font-weight: bold; background-color: #d1d5db !important; color: {{ $warnaTeksStatus }} !important;" disabled>
                        <a href="{{ route('inputharian.downtime', $row->IdInputHarian) }}" class="btn btn-sm" style="background: #ffffff; border: 1px solid #000; font-size: 10px; padding: 4px 8px; font-weight: 700; text-decoration: none; color: #000; border-radius: 4px;">Detail</a>
                    </div>
                </td>

                <td class="text-center">
                    <select class="time-break-time input-table-custom" style="width: 55px;" {{ $disableInput ? 'disabled' : '' }}>
                        <option value="0" {{ $row->TimeBreakTime == 0 ? 'selected' : '' }}>-</option>
                        <option value="15" {{ $row->TimeBreakTime == 15 ? 'selected' : '' }}>15</option>
                        <option value="40" {{ $row->TimeBreakTime == 40 ? 'selected' : '' }}>40</option>
                        <option value="45" {{ $row->TimeBreakTime == 45 ? 'selected' : '' }}>45</option>
                    </select>
                </td>

                <td class="text-center">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                        {{-- LABEL PLAN --}}
                        <div style="font-size: 10px; font-weight: 800; background: #d1d5db !important; padding: 2px 10px; border-radius: 4px; border: 1px solid #9ca3af !important; color: #4b5563;">
                            PLAN: {{ $row->standard_plan_start ? \Carbon\Carbon::parse($row->standard_plan_start)->format('H:i') : '--:--' }} - {{ $row->standard_plan_finish ? \Carbon\Carbon::parse($row->standard_plan_finish)->format('H:i') : '--:--' }}
                        </div>

                        {{-- INPUT WAKTU --}}
                        <div class="flex-cell-container">
                            <input type="time" class="input-table-custom aktual-start-input" style="width: 100px !important;" value="{{ $row->AktualStart ? \Carbon\Carbon::parse($row->AktualStart)->format('H:i') : '00:00' }}">
                            <input type="time" class="input-table-custom aktual-finish-input" style="width: 100px !important;" value="{{ $row->AktualFinish ? \Carbon\Carbon::parse($row->AktualFinish)->format('H:i') : '00:00' }}">
                        </div>

                        {{-- ✅ SATU ELEMEN UNTUK TIMER & DURASI (Tidak Dobel) --}}
                        <span class="running-timer" 
                            data-id="{{ $row->IdInputHarian }}" 
                            data-status="{{ $row->StatusProses }}" 
                            data-total-proses="{{ $row->TotalProses ?? 0 }}"
                            style="font-weight: 900; font-size: 12px; color: #000;">
                            ({{ number_format(abs($row->TotalProses ?? 0), 1, '.', '') }} Menit)
                        </span>

                        {{-- TOMBOL START/STOP/SELESAI --}}
                        @if($row->StatusProses !== 'Finished')
                            @if($isRunning)
                                <div style="display: flex; gap: 5px;">
                                    <button onclick="recordFromHeader('{{ $row->IdInputHarian }}', 'stop')" style="background-color: #ffffff; color: #000; font-weight: 800; border: 1px solid #000; padding: 5px 12px; border-radius: 4px; font-size: 11px;">STOP</button>
                                    <button onclick="recordFromHeader('{{ $row->IdInputHarian }}', 'finish')" style="background-color: #ffffff; color: #000; font-weight: 800; border: 1px solid #000; padding: 5px 12px; border-radius: 4px; font-size: 11px;">FINISH</button>
                                </div>
                            @else
                                <div style="display: flex; gap: 5px;">
                                    <button onclick="recordFromHeader('{{ $row->IdInputHarian }}', 'start')" style="background-color: #ffffff; color: #000; font-weight: 800; border: 1px solid #000; padding: 5px 12px; border-radius: 4px; font-size: 10px;" {{ $isQC ? 'disabled' : '' }}>START</button>
                                    @if($isStopped || (!empty($row->AktualStart) && $row->AktualStart != '00:00:00'))
                                        <button onclick="recordFromHeader('{{ $row->IdInputHarian }}', 'finish')" style="background-color: #ffffff; color: #000; font-weight: 800; border: 1px solid #000; padding: 5px 12px; border-radius: 4px; font-size: 11px;" {{ $isQC ? 'disabled' : '' }}>FINISH</button>
                                    @endif
                                </div>
                            @endif
                        @else 
                            <span class="badge badge-secondary" style="font-size: 10px; border: 1px solid #fff; background-color: #6c757d; color: white; padding: 4px 8px;">CLOSED</span> 
                        @endif

                        {{-- DROPDOWN NEXT --}}
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <select class="select-next-item" id="next-{{ $row->IdInputHarian }}" style="width: 80px; font-size: 10px; height: 28px; border: 1px solid #000;" {{ ($isLocked || $isQC) ? 'disabled' : '' }}>
                                <option value="">Next?</option>
                                @foreach($inputs as $next)
                                    @if($next->IdInputHarian !== $row->IdInputHarian && $next->StatusProses !== 'Finished')
                                        <option value="{{ $next->IdInputHarian }}" {{ $row->NextItemId == $next->IdInputHarian ? 'selected' : '' }}>{{ $next->item->JobNumber }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <button onclick="setNextItem('{{ $row->IdInputHarian }}')" class="btn btn-dark" style="font-size: 9px; padding: 5px 8px; font-weight: 800; height: 28px;" {{ $isLocked ? 'disabled' : '' }}>SET</button>
                        </div>
                    </div>
                </td>

                <td class="text-center">
                    <div style="display: flex; flex-direction: column; gap: 5px; align-items: center;">
                        <button class="btn btn-primary btn-sm" onclick="saveHarian('{{ $row->IdInputHarian }}', this)" 
                                style="font-weight: 800; background: #ffffff; border: 1px solid #000000; color: #000; width: 90px; min-width: 90px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-sizing: border-box;">
                            SAVE
                        </button>

                        @if(strtoupper($row->StatusProses) !== 'FINISHED')
                            <button type="button" class="btn btn-xs" 
                                    style="font-weight: 800; font-size: 10px; border: 1px solid #000; width: 90px; min-width: 90px; height: 26px; padding: 0; background: #fff; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box;"
                                    onclick="confirmOper('{{ $row->IdInputHarian }}')">
                                MOVE
                            </button>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
                <tr><td colspan="13" class="text-center" style="padding: 30px; color: #000000; font-weight: 800; font-size: 14px;">TIDAK ADA DATA PRODUKSI UNTUK TANGGAL INI.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('Produksi.inputharian.partials.modal_detail_plan')
@include('Produksi.inputharian.partials.modal_extra_job')
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    let planModalObj = null;
    let searchTimer;
    
    function handleSearch(filter) {
        clearTimeout(searchTimer);
        
        // Tunggu 400ms setelah user berhenti ngetik baru jalanin filter
        searchTimer = setTimeout(() => {
            const filterText = filter.toLowerCase();
            const tbody = document.querySelector("#inputHarianTable tbody");
            const rows = Array.from(tbody.querySelectorAll("tr"));
            
            rows.forEach(row => {
                const jobCell = row.querySelector('.item-job-cell');
                const textContent = jobCell ? jobCell.innerText.toLowerCase() : "";
                
                // Kalau teks cocok, munculin. Kalau gak, sembunyiin.
                row.style.display = textContent.includes(filterText) ? "" : "none";
            });
        }, 400);
    }

    function bukaExtraModal() {
        // Cek apakah user sudah pilih Line di filter
        const currentLine = "{{ request('line') }}";
        
        if (!currentLine) {
            Swal.fire({
                icon: 'warning',
                title: 'Pilih Jalur Produksi',
                text: 'Silakan pilih jalur produksi dari filter di bagian atas sebelum menambahkan extra job.',
                confirmButtonColor: '#e11d2e'
            });
            return;
        }

        const modal = $('#modalExtraJobManual');
        if(modal.length) {
            modal.fadeIn(300).css('display', 'flex');
            $('#selectExtraManual').select2({
                dropdownParent: $('#modalExtraJobManual'),
                placeholder: "--- Enter Job Number / Part ---",
                allowClear: true,
                width: '100%',
                dropdownAutoWidth: true
            });
        }
    }

    function tutupExtraModal() {
        $('#modalExtraJobManual').fadeOut(200);
    }

    $(document).on('click', '#modalExtraJobManual', function(e) {
        if (e.target !== this) return;
        tutupExtraModal();
    });

    // --- 1. FUNGSI OPER MANUAL (TOMBOL OPER DI TABEL) ---
    function confirmOper(id) {
        Swal.fire({
            title: 'Pindahkan?',
            text: "Item ini akan dimasukkan ke dalam antrian shift berikutnya.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Move'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: "/produksi/input-harian/oper-manual/" + id,
                    type: "POST",
                    data: { _token: "{{ csrf_token() }}" },
                    success: function(res) {
                        Swal.fire('Moved!', res.message, 'success').then(() => location.reload());
                    },
                    error: function(err) {
                        Swal.fire('Oops! Something went wrong', err.responseJSON.message, 'error');
                    }
                });
            }
        })
    }

    // --- 2. GERBANG UTAMA TOMBOL START / STOP / SELESAI ---
    // --- 2. GERBANG UTAMA TOMBOL START / STOP / SELESAI ---
    function recordFromHeader(id, action) {
        const tr = document.querySelector(`tr[data-id="${id}"]`);
        if (!tr) return;

        if (action === 'finish') {
            // Ambil semua kolom (td) dalam baris ini
            const cells = tr.querySelectorAll('td');

            // --- 1. AMBIL HASIL (MENGGUNAKAN URUTAN KOLOM) ---
            
            // Good ada di kolom index 3 (atau sesuaikan urutan td Lu)
            // Kita ambil input pertama di cell Good (.good-a)
            const goodA = parseFloat(tr.querySelector('.good-a')?.value || 0);

            // Repair biasanya ada di kolom setelah Good
            // Kita cari cell yang mengandung link route 'repair'
            const repairCell = Array.from(cells).find(td => td.innerHTML.includes('repair'));
            const repairA = parseFloat(repairCell?.querySelectorAll('input')[0]?.value || 0);

            // Reject biasanya ada di kolom setelah Repair
            // Kita cari cell yang mengandung link route 'reject'
            const rejectCell = Array.from(cells).find(td => td.innerHTML.includes('reject'));
            const rejectA = parseFloat(rejectCell?.querySelectorAll('input')[0]?.value || 0);

            const totalHasilA = goodA + repairA + rejectA;

            // --- 2. AMBIL PLAN TOTAL ---
            // Plan biasanya ada di kolom index 2 (input disabled pertama dan kedua)
            const planInputs = tr.querySelectorAll('input[disabled]');
            // --- 2. AMBIL PLAN A SAJA SEBAGAI TARGET ---
            // Mengambil nilai murni dari input dengan class plan-a
            const planA = parseFloat(tr.querySelector('.plan-a')?.value || 0);

            // Validasi: Apakah Hasil = Plan A?
            if (Math.abs(totalHasilA - planA) > 0.1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Total Quantity Tidak Sama!',
                    html: `Plan: <b>${planA}</b><br>Total: <b>${totalHasilA}</b><br><small>(Good: ${goodA} + Repair: ${repairA} + Reject: ${rejectA})</small>`,
                    confirmButtonColor: '#e11d2e'
                });
                return;
            }

            // --- VALIDASI DOWNTIME ---
            // 🔥 FIX: Hapus hitungan manual di frontend. Biarkan backend (Controller) yang nge-handle penolakannya!

            Swal.fire({
                title: 'Konfirmasi Selesai',
                text: 'Selesaikan pekerjaan ini?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'Finish'
            }).then((result) => {
                if (result.isConfirmed) executeStatusRequest(id, action);
            });
        } else {
            executeStatusRequest(id, action);
        }
    }

    function searchTable() { 
        // Ambil nilai input
        const filter = document.getElementById('searchInput').value.toLowerCase();
        
        // Ambil tabel secara spesifik
        const table = document.getElementById('inputHarianTable');
        const rows = table.querySelectorAll("tbody tr");
        
        rows.forEach(row => {
            // Gabung teks dari kolom JobNumber (0) dan NamaPart (0)
            const jobCell = row.querySelector('.item-job-cell');
            const textContent = jobCell ? jobCell.innerText.toLowerCase() : "";
            
            // Tampilkan/Sembunyikan
            if (textContent.includes(filter)) {
                row.style.setProperty('display', '', 'important'); 
            } else {
                row.style.setProperty('display', 'none', 'important');
            }
        }); 
    }

    function saveHarian(id, btn) {
        const tr = btn.closest('tr');
        
        // 1. Ambil data dari input
        const goodA = tr.querySelector('.good-a')?.value || 0;
        const goodB = tr.querySelector('.good-b')?.value || 0;
        const startManual = tr.querySelector('.aktual-start-input')?.value || '00:00:00';
        let finishManual = tr.querySelector('.aktual-finish-input')?.value || '00:00:00';
        const breakTime = tr.querySelector('.time-break-time')?.value || 0;

        // Cek status mesin (Running/Stopped/Finished) dari timer
        const statusProses = tr.querySelector('.running-timer')?.dataset.status;

        // 🔥 KUNCI PERBAIKAN:
        // Jika mesin masih RUNNING, KITA PAKSA update jam finish ke waktu SAAT KLIK SAVE.
        // Biar durasi di database ikutan nambah terus dan gak nyangkut di jam SAVE yang pertama.
        if (statusProses === 'Running') {
            const now = new Date();
            finishManual = now.getHours().toString().padStart(2, '0') + ":" + 
                        now.getMinutes().toString().padStart(2, '0');
            tr.querySelector('.aktual-finish-input').value = finishManual;
        } else {
            // Jika status udah Stop/Selesai, patuhi jam manual. Kalau kosong baru isi otomatis.
            if (!finishManual || finishManual === '00:00' || finishManual === '00:00:00') {
                const now = new Date();
                finishManual = now.getHours().toString().padStart(2, '0') + ":" + 
                            now.getMinutes().toString().padStart(2, '0');
                tr.querySelector('.aktual-finish-input').value = finishManual;
            }
        }

        const data = {
            _token: '{{ csrf_token() }}',
            GoodA: goodA,
            GoodB: goodB,
            AktualStart: startManual,
            AktualFinish: finishManual,
            TimeBreakTime: breakTime
        };

        // Feedback visual (Loading)
        const originalText = btn.innerHTML;
        btn.innerHTML = 'SAVE <i class="fas fa-spinner fa-spin" style="font-size: 10px;"></i>';
        btn.disabled = true;

        $.ajax({
            url: '/produksi/input-harian/update/' + id,
            type: 'POST',
            data: data,
            success: function(res) { 
                if(res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Data berhasil disimpan.',
                        text: 'Data Daily Input berhasil di perbarui',
                        showConfirmButton: false,
                        timer: 1500
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Warning',
                        text: res.message || 'Terjadi Kesalahan'
                    });
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            },
            error: function(xhr) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Terjadi Kesalahan'
                });
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });
    }

    function setNextItem(currentId) {
        const nextId = document.getElementById('next-' + currentId).value;
        $.ajax({
            url: '/produksi/input-harian/set-next',
            type: 'POST',
            data: { _token: '{{ csrf_token() }}', currentId: currentId, nextId: nextId },
            success: function(res) { if(res.success) location.reload(); }
        });
    }

    function updateFilter() { 
        const d = document.getElementById('filterDate').value;
        const l = document.getElementById('filterLine').value;
        const s = document.getElementById('searchInput').value; // 🔥 FIX 4: Tangkap nilai search
        
        // Selalu kirim parameter search (walau kosong) supaya Session di backend ikut ter-reset
        window.location.href = `?date=${d}&line=${l}&search=${s}`; 
    }

    function openDetailPlanModal() {
        console.log("Opening Pop-up...");

        const modalEl = document.getElementById('detailPlanModal');
        if (!modalEl) return;

        // Inisialisasi Modal Bootstrap 5 dengan konfigurasi manual
        let myModal = bootstrap.Modal.getInstance(modalEl);
        if (!myModal) {
            myModal = new bootstrap.Modal(modalEl, {
                backdrop: 'static', 
                keyboard: false
            });
        }
        myModal.show();

        const tbody = document.getElementById('tbodyDetailPlanAll');
        const loading = document.getElementById('loadingDetailPlan');
        const content = document.getElementById('contentDetailPlan');

        // UI Reset
        if(loading) loading.style.display = 'block';
        if(content) content.style.display = 'none';
        if(tbody) tbody.innerHTML = '';

        $.ajax({
            url: "{{ route('inputharian.planDetails') }}",
            type: "POST",
            data: {
                _token: "{{ csrf_token() }}",
                date: document.getElementById('filterDate')?.value || "{{ date('Y-m-d') }}",
                line_id: document.getElementById('filterLine')?.value || ""
            },
            success: function(res) {
                if(loading) loading.style.display = 'none';
                if(content) content.style.display = 'block';

                if (res.success) {
                    let html = '';
                    res.data.forEach(d => {
                        let mHtml = '';
                        // Mengambil data array mesin dari response controller
                        if (d.mesin && Array.isArray(d.mesin)) {
                            d.mesin.forEach((m, i) => {
                                let active = m > 0 ? 'bg-active-m' : 'bg-inactive-m';
                                // Jika mesin aktif, kasih icon check, jika tidak tampilkan nomor mesinnya
                                let contentMesin = m > 0 ? '<i class="fas fa-check"></i>' : (i + 1);
                                mHtml += `<span class="badge-mesin ${active}">${contentMesin}</span>`;
                            });
                        }

                        html += `
                            <tr class="text-center">
                                <td class="text-start"><b style="color: #f82b3d;">${d.job_number}</b></td>
                                <td class="text-start">${d.part_name || '-'}</td>
                                <td>${d.po_number || '-'}</td>
                                <td class="fw-bold">${d.plan_qty.split(' / ')[0]}</td>
                                <td class="fw-bold">${d.plan_qty.split(' / ')[1]}</td>
                                <td class="text-success fw-bold">${d.gsph}</td>
                                <td class="text-danger">${d.schedule.split(' - ')[0]}</td>
                                <td class="text-danger">${d.schedule.split(' - ')[1]}</td>
                                <td class="fw-bold text-primary">${d.tpt}</td>
                                <td>${d.ct || '-'}</td> 
                                <td class="text-warning fw-bold">${d.loss_display}</td>
                                <td>${d.plan_work_time || '-'}</td>
                                <td>${d.stroke || '-'}</td>
                                <td>${mHtml}</td>
                                <td>${d.die_change_high || '-'}</td>
                                <td class="highlight-logistics" style="background: #fff3cd !important;">${d.jml_pallet || 0}</td>
                                <td class="highlight-logistics" style="background: #e1f5fe !important;">${d.jml_material || 0}</td>
                                <td class="text-start"><small>${d.note}</small></td>
                            </tr>`;
                    });
                    tbody.innerHTML = html;
                } else {
                    tbody.innerHTML = `<tr><td colspan="18" class="text-center py-4 text-muted">${res.message}</td></tr>`;
                }
            },
            error: function(xhr) {
                if(loading) loading.style.display = 'none';
                tbody.innerHTML = `<tr><td colspan="18" class="text-center text-danger">Terjadi Kesalahan</td></tr>`;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setInterval(() => {
            const nowMs = Date.now();

            document.querySelectorAll('.running-timer').forEach(timer => {
                // 1. KUNCI UTAMA: Hanya jalankan jika status Running
                if (timer.dataset.status !== 'Running') {
                    // Jika tidak Running (misal Stopped/Ready), jangan update timer
                    // Tapi opsional: kamu bisa tampilkan durasi statis dari database di sini
                    return; 
                }

                const tr = timer.closest('tr');
                if (!tr) return;

                const startInput = tr.querySelector('.aktual-start-input');
                const minInfo = timer;

                if (startInput && startInput.value && startInput.value.includes(':')) {
                    const [h, m] = startInput.value.split(':').map(Number);
                    
                    const start = new Date();
                    start.setHours(h, m, 0, 0); 
                    
                    let diffMs = nowMs - start.getTime();

                    // Jika selisih negatif (Shift lewat tengah malam), tambah 24 jam
                    if (diffMs < 0) {
                        diffMs += 86400000; 
                    }

                    // ✅ PENTING: Durasi yang muncul di layar = Sesi Berjalan
                    // Pastikan Controller kamu saat tombol START diklik, 
                    // selalu melakukan: DB::table(...)->update(['AktualStart' => now()]);
                    const diffMins = (diffMs / 60000).toFixed(2);
                    minInfo.innerText = `(${diffMins} Menit)`;
                    minInfo.style.color = "#4361ee";
                    minInfo.style.fontWeight = "900";
                }
            });
        }, 1000);
    });

    // =========================================================
    // MONITORING OTOMATIS: JARING SAPU BERSIH (DUA SHIFT)
    // =========================================================
    // --- 1. MONITORING OTOMATIS (DENGAN PENGUNCI) ---
    let alertSudangMuncul = false; // Pengunci biar alert gak spam tiap detik

    setInterval(() => {
        const skrg = new Date();
        const h = skrg.getHours();
        const m = skrg.getMinutes();
        const s = skrg.getSeconds();

        // Cek apakah jam pulang (Contoh Shift 1: 14:58, Shift 2: 16:05)
        const isWaktuPulangShift1 = (h === 15 && m === 10 && s === 0);
        const isWaktuPulangShift2 = (h === 16 && m === 05 && s === 0);

        if ((isWaktuPulangShift1 || isWaktuPulangShift2) && !alertSudangMuncul) {
            alertSudangMuncul = true; // Kunci!

            Swal.fire({
                title: 'Saatnya pergantian shift!',
                text: "Apakah Anda ingin memindahkan semua antrian yang tersisa ke shift berikutnya?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Move All',
                cancelButtonText: 'Later',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    executeGlobalOper();
                }
                alertSudangMuncul = false; // Buka kunci setelah user merespons
            });
        }
    }, 1000);

    // --- 2. EKSEKUSI MASSAL (SINKRON DENGAN LOGIC MANUAL) ---
    function executeGlobalOper() {
        let idLine = document.getElementById('filterLine').value;
        const tgl = document.getElementById('filterDate').value;

        // Jaga-jaga: Kalau filter kosong, ambil ID Line dari atribut data di baris tabel
        if (!idLine) {
            const firstRow = document.querySelector("#inputHarianTable tbody tr[data-id-line]");
            if (firstRow) idLine = firstRow.getAttribute('data-id-line');
        }

        if (!idLine) {
            Swal.fire('Peringatan', 'Select Line', 'warning');
            return;
        }

        // Tampilkan loading karena oper massal itu proses berat (insert banyak header/detail)
        Swal.fire({
            title: 'Move All',
            text: 'Please wait.',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
            url: "/produksi/input-harian/oper-massal-otomatis",
            type: "POST",
            data: {
                _token: "{{ csrf_token() }}",
                id_line: idLine,
                tanggal: tgl
            },
            success: function(res) {
                if(res.success) {
                    Swal.fire('Success!', res.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Terjadi Kesalahan', 'error');
            }
        });
    }

    // --- 3. EKSEKUSI STATUS (BIAR GOOD QTY SINKRON) ---
    function executeStatusRequest(id, action) {
        const tr = document.querySelector(`tr[data-id="${id}"]`);
        if (!tr) return;

        const goodA = Number(tr.querySelector('.good-a')?.value || 0);
        const goodB = Number(tr.querySelector('.good-b')?.value || 0);
        
        const manualStart = tr.querySelector('.aktual-start-input')?.value;
        const manualFinish = tr.querySelector('.aktual-finish-input')?.value;

        // Ambil Jam Murni Sistem Saat Ini (Format HH:mm:ss)
        const jamSistemSekarang = new Date().toLocaleTimeString('en-GB');

        // Logic Penentu: Jika ada input manual pakai itu, jika kosong pakai jam sekarang
        let timeToSend = '';
        if (action === 'start') {
            timeToSend = (manualStart && manualStart !== '00:00:00' && manualStart !== '00:00') ? manualStart : jamSistemSekarang;
        } else {
            timeToSend = (manualFinish && manualFinish !== '00:00:00' && manualFinish !== '00:00') ? manualFinish : jamSistemSekarang;
        }

        if (timeToSend.length === 5) timeToSend += ':00';

        const h = new Date().getHours();
        const isWaktuOper = (h === 14 || h === 15 || h === 16 || h === 4 || h === 5);

        if (action === 'stop' && isWaktuOper) {
            Swal.fire({
                title: 'Pindahkan?',
                text: "Data tersebut akan diduplikasi (jumlah yang tersisa) atau dipindahkan (jika belum dimulai).",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Move',
                cancelButtonText: 'Stop'
            }).then((result) => {
                sendUpdateStatus(id, action, timeToSend, result.isConfirmed ? 1 : 0, goodA, goodB);
            });
        } else {
            sendUpdateStatus(id, action, timeToSend, 0, goodA, goodB);
        }
    }

    // FUNGSI INI YANG SEBENERNYA NGIRIM DATA KE SERVER PAS PENCET START/STOP/SELESAI
    function sendUpdateStatus(id, action, time, oper, gA, gB) {
        const tr = document.querySelector(`tr[data-id="${id}"]`);
        
        // 1. Ambil input jam manual dari UI
        const manualStart = tr.querySelector('.aktual-start-input')?.value;
        const manualFinish = tr.querySelector('.aktual-finish-input')?.value;
        const breakTime = tr.querySelector('.time-break-time')?.value || 0;

        // 2. Logic Prioritas: Jam Manual > Jam Sistem
        // Pastikan formatnya HH:mm (browser 'time' input biasanya return HH:mm)
        let timeToSend = (action === 'start') 
            ? ((manualStart && manualStart !== '00:00') ? manualStart : time)
            : ((manualFinish && manualFinish !== '00:00') ? manualFinish : time);

        // 3. Pastikan jam punya detik (kalau perlu) agar format DB konsisten
        if (timeToSend.length === 5) timeToSend += ':00';

        // 4. Kirim ke Server
        $.ajax({
            url: '/produksi/input-harian/update-status/' + id,
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            data: JSON.stringify({ 
                action: action, 
                time: timeToSend, 
                auto_oper: oper,
                good_a: gA, 
                good_b: gB,
                time_break: breakTime
            }),
            contentType: 'application/json',
            success: function(res) { 
                if(res.success) {
                    // Berhasil update, reload halaman
                    location.reload(); 
                } else {
                    // SINI KUNCINYA: Menangkap pesan error dari validasi downtime Controller
                    Swal.fire({
                        icon: 'error',
                        title: 'Terjadi Kesalahan',
                        text: res.message, // Pesan error "Downtime belum seimbang!" muncul di sini
                        confirmButtonColor: '#e11d2e'
                    });
                }
            },
            error: function(xhr) {
                // Menangkap error 422 (Validation Error) dari Controller
                let errMsg = 'Terjadi Kesalahan';
                if(xhr.responseJSON && xhr.responseJSON.message) {
                    errMsg = xhr.responseJSON.message;
                }
                
                Swal.fire({
                    icon: 'error',
                    title: 'Terjadi Kesalahan',
                    text: errMsg,
                    confirmButtonColor: '#e11d2e'
                });
            }
        });
    }

    function setNextItem(currentId) {
        const nextId = document.getElementById('next-' + currentId).value;
        
        // Validasi dikit biar gak konyol kalau belum pilih item
        if (!nextId) {
            Swal.fire({
                icon: 'warning',
                title: 'Pilih Item',
                text: 'Silahkan pilih item',
                confirmButtonColor: '#e11d2e'
            });
            return;
        }

        $.ajax({
            url: '/produksi/input-harian/set-next',
            type: 'POST',
            data: { 
                _token: '{{ csrf_token() }}', 
                currentId: currentId, 
                nextId: nextId 
            },
            success: function(res) { 
                if(res.success) {
                    // MUNCULIN SWEETALERT DISINI
                    Swal.fire({
                        icon: 'success',
                        title: 'Item Berikutnya Telah Disimpan!',
                        text: 'Urutan produksi berhasil diperbarui.',
                        showConfirmButton: false,
                        timer: 1500
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Gagal', res.message || 'Terjadi Kesalahan', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Terjadi Kesalahan', 'error');
            }
        });
    }
</script>
@endsection