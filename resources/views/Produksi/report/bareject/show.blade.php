@extends('Produksi.layouts.main')

@section('title', 'Detail BA Reject')
@section('page-title', 'Detail BA Reject')

@section('content')

{{-- BREADCRUMB --}}
<div class="breadcrumb">
    <span>IPS</span>
    <span class="separator">></span>
    <span>Report</span>
    <span class="separator">></span>
    <a href="{{ route('report.bareject.index') }}" style="text-decoration:none; color:inherit;">BA Reject</a>
    <span class="separator">></span>
    <span class="active">Detail</span>
</div>

@php
    $harian = $data->inputHarian;
    // Ambil item dari harian atau dari manual input
    $prodItem = $harian ? $harian->item : $data->item;
    
    $qty = $data->Qty ?? 0;
    $beratPcs = optional($prodItem)->Berat ?? 0;
    $beratTotal = $beratPcs * $qty;
@endphp

<div class="detail-grid">

    <div class="detail-item">
        <label>Tanggal Kejadian</label>
        <span>
            {{ $harian && $harian->TanggalProduksi 
                ? \Carbon\Carbon::parse($harian->TanggalProduksi)->format('d/m/Y') 
                : \Carbon\Carbon::parse($data->created_at)->format('d/m/Y') }}
        </span>
    </div>

    <div class="detail-item">
        <label>Job Number</label>
        <span style="font-weight: 800; color: #e11d2e;">{{ optional($prodItem)->JobNumber ?? '-' }}</span>
    </div>

    <div class="detail-item">
        <label>Nama Part</label>
        <span>{{ optional($prodItem)->NamaPart ?? '-' }}</span>
    </div>

    <div class="detail-item">
        <label>Customer</label>
        <span>{{ optional(optional($prodItem)->customer)->NamaCustomer ?? '-' }}</span>
    </div>

    <div class="detail-item">
        <label>QTY Reject</label>
        <span style="font-weight: 800;">{{ $qty }} PCS</span>
    </div>

    <div class="detail-item">
        <label>Berat Total</label>
        <span>{{ number_format($beratTotal, 2, ',', '.') }} Kg</span>
    </div>

    <div class="detail-item">
        <label>Area Problem</label>
        <span class="badge badge-dark" style="text-transform: uppercase;">
            {{ $data->TipeReject ?? optional($data->masterReject)->TipeReject ?? '-' }}
        </span>
    </div>

    <div class="detail-item">
        <label>Jenis Kerusakan</label>
        <span>{{ $data->NamaKerusakan ?? (optional($data->masterReject)->NamaReject ?? '-') }}</span>
    </div>

    <div class="detail-item" style="grid-column: span 2;">
        <label>Penyebab (Root Cause)</label>
        <span style="line-height: 1.6;">{{ $data->Penyebab ?? '-' }}</span>
    </div>

    <div class="detail-item" style="grid-column: span 2;">
        <label>Counter Measure</label>
        <span style="line-height: 1.6;">{{ $data->CounterMeasure ?? '-' }}</span>
    </div>

    <div class="detail-item">
        <label>Status Document</label>
        <span class="badge {{ ($data->Status == 'Done' || $data->Status == 1) ? 'badge-success' : 'badge-primary' }}">
            {{ ($data->Status == 'Done' || $data->Status == 1) ? 'DONE' : 'ON PROGRESS' }}
        </span>
    </div>
</div>

<div class="form-actions" style="margin-top: 20px; display: flex; gap: 10px;">
    <a href="{{ route('report.bareject.index') }}" class="btn btn-outline">
        Back
    </a>
</div>

@endsection