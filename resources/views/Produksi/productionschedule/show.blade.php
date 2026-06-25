@extends('Produksi.layouts.main')

@section('title', 'Detail Production Schedule')
@section('page-title', 'Detail Production Schedule')

@section('content')

{{-- BREADCRUMB --}}
<div class="breadcrumb">
    <span>IPS</span>
    <span class="separator">></span>
    <span>Daily Input</span>
    <span class="separator">></span>
    <span class="active">Detail Production Schedule</span>
</div>

{{-- INFORMASI HEADER (SAMA KAYA CUSTOMER DETAIL GRID) --}}
<div class="detail-grid">
    <div class="detail-item">
        <label>Production Line</label>
        <span>{{ $schedule->productionLine->NamaProductionLine }} - {{ $schedule->productionLine->Shift }}</span>
    </div>

    <div class="detail-item">
        <label>Nama PIC</label>
        <span>{{ $schedule->pic->NamaKaryawan ?? 'PIC Tidak Ditemukan' }}</span>
    </div>

    <div class="detail-item">
        <label>Tanggal Produksi</label>
        <span>{{ date('d-m-Y', strtotime($schedule->TanggalProduksi)) }}</span>
    </div>

    <div class="detail-item">
        <label>Revisi</label>
        @if($schedule->Status)
            <span class="badge" style="background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 12px;">
                <i class="fas fa-history"></i> {{ $schedule->Status }}
            </span>
        @else
            <span class="badge badge-success" style="background: #e2fbe8; color: #1e7e34; border: 1px solid #c3e6cb; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 12px;">
                Original
            </span>
        @endif
    </div>

    <div class="detail-item">
        <label>Waktu Dibuat</label>
        <span>{{ $schedule->created_at->format('d-m-Y H:i') }}</span>
    </div>
</div>

<hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

{{-- TABEL DETAIL ITEM PRODUKSI (TETAP UTUH GAK DIAKALI) --}}
<h5 style="font-size: 15px; margin-bottom: 20px; color: #333; font-weight: 600;">Daftar Item Produksi</h5>

@php
    $isLineK = str_contains(strtoupper($schedule->productionLine->NamaProductionLine), 'LINE K');
@endphp

<div style="background: #fff; border: 1px solid #eee; border-radius: 8px; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1200px;">
        <thead style="background: #f8f9fa;">
            <tr>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Item & PO</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Plan Qty (A/B)</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Waktu (S-F)</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Press Time</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Uchi/Soto</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">BQ / Q-Check</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">TPT / WorkT</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">GSPH / Stroke</th>
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Pallet / Material</th>
                @if($isLineK)
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Mesin (M1-M5)</th>
                @endif
                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6;">Note</th>
            </tr>
        </thead>
        <tbody>
            @foreach($schedule->details as $d)
            <tr>
                {{-- Item & PO --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    <strong>{{ $d->item->JobNumber }}</strong><br>
                    <small style="color: #666;">{{ $d->item->NamaPart }}</small><br>
                    <span class="badge bg-light text-dark" style="font-size: 10px; border: 1px solid #ddd;">PO: {{ $d->PoNumber ?? '-' }}</span>
                </td>
                
                {{-- Qty --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    <span style="font-weight: bold; color: #4361ee;">{{ $d->PlanQtyA ?? 0 }}</span> / 
                    <span style="color: #666;">{{ $d->PlanQtyB ?? 0 }}</span>
                </td>

                {{-- S-F --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee; font-weight: 600;">
                    {{ date('H:i', strtotime($d->PlanStart)) }} - {{ date('H:i', strtotime($d->PlanFinish)) }}
                </td>

                {{-- Press Time --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    {{ $d->PressTime ?? 0 }} <small>Min</small>
                </td>

                {{-- Uchi / Soto --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    {{ $d->DiesChangeUchi ?? 0 }} / {{ $d->DiesChangeSoto ?? 0 }}
                </td>

                {{-- BQ / QCheck --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    BQ: {{ $d->BqSht ?? 0 }}<br>QC: {{ $d->FirstQCheck ?? 0 }}
                </td>

                {{-- TPT / Work --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    TPT: <strong>{{ $d->TPT ?? 0 }}</strong><br>
                    WT: {{ $d->PlanWorkTime ?? 0 }}
                </td>

                {{-- GSPH / Stroke --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    G: {{ $d->PlanGSPH ?? 0 }}<br>S: <strong>{{ $d->Stroke ?? 0 }}</strong>
                </td>

                {{-- Pallet / Material --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    P: {{ $d->JmlPallet ?? 0 }}<br>M: {{ $d->JmlMaterial ?? 0 }}
                </td>

                {{-- Mesin (Jika Line K) --}}
                @if($isLineK)
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee;">
                    <div style="display: flex; gap: 3px; justify-content: center;">
                        @for($m=1; $m<=5; $m++)
                            @php $val = $d->{"QtyMesin".$m}; @endphp
                            <span style="background: {{ $val > 0 ? '#edf2ff' : '#f8f9fa' }}; border: 1px solid {{ $val > 0 ? '#4361ee' : '#ddd' }}; padding: 2px 5px; border-radius: 4px; color: {{ $val > 0 ? '#4361ee' : '#ccc' }}; font-size: 10px; font-weight: bold;">
                                {{ $val > 0 ? $val : 0 }}
                            </span>
                        @endfor
                    </div>
                    <small style="display:block; margin-top:4px;">Tot: {{ $d->TotalMesin }}</small>
                </td>
                @endif

                {{-- Note --}}
                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #eee; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ $d->Note ?? '-' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- TOMBOL KEMBALI (SAMA KAYA CUSTOMER FORM ACTIONS) --}}
<div class="form-actions">
    <a href="{{ route('productionschedule.index') }}" class="btn btn-outline">
        Back
    </a>
</div>

@endsection