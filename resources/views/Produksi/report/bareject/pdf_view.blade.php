<!DOCTYPE html>
<html>
<head>
    <title>Berita Acara Reject</title>
    <style>
        @page { margin: 10px; }
        body { font-family: 'Arial', sans-serif; font-size: 8px; color: #000; }
        
        /* Layout Header Atas */
        .header-container { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        .header-container td { border: 1px solid #000; padding: 2px; vertical-align: top; }
        .main-title { font-size: 18px; font-weight: bold; text-decoration: underline; }
        
        /* Table Signatures */
        .table-sig { width: 100%; border-collapse: collapse; text-align: center; }
        .table-sig td { border: 1px solid #000; height: 40px; font-size: 7px; }
        .sig-title { height: 12px !important; background: #eee; font-weight: bold; }

        /* Tabel Data Utama */
        .table-pdf { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .table-pdf th { background-color: #f82b3d; color: white; border: 1px solid #000; padding: 3px; font-size: 7px; }
        .table-pdf td { border: 1px solid #000; padding: 3px; text-align: center; word-wrap: break-word; }
        
        .bg-gray { background-color: #f1f2f6; }
        .bg-yellow { background-color: #ffff00; font-weight: bold; }
        .text-left { text-align: left; padding-left: 5px; }
        .font-bold { font-weight: bold; }
        
        /* Footer & Summary */
        .footer-table { width: 100%; margin-top: 5px; border-collapse: collapse; }
        .footer-table td { border: 1px solid #000; padding: 3px; }
    </style>
</head>
<body>

    {{-- HEADER: Judul & Slot Tanda Tangan --}}
    <table class="header-container">
        <tr>
            <td width="40%" style="border: none;">
                <div class="main-title">SCRAP EX PRODUKSI</div>
                <div style="font-weight: bold;">TANGGAL PENGELUARAN: {{ \Carbon\Carbon::parse($tanggal)->format('d/m/Y') }}</div>
            </td>
            <td width="30%" style="text-align: center; font-weight: bold; font-size: 10px;">
                NOMOR REGISTER
                <div style="border: 1px dashed #000; margin-top: 5px; padding: 5px;">
                    {{ $noRegister ?? 'BA / .... / PIC - REJECT / ....' }}
                </div>
            </td>
            <td width="30%" style="border: none;">
                <div style="text-align: right; font-weight: bold;">Line : STAMPING E / F / K - LINE</div>
            </td>
        </tr>
    </table>

    {{-- TABEL TANDA TANGAN --}}
    <table class="table-sig">
        <tr class="sig-title">
            <td colspan="3">Dibuat</td>
            <td colspan="5">Disetujui</td>
            <td colspan="1">Diterima</td>
            <td rowspan="3" width="100px" style="text-align: left; padding: 5px;">
                <b>Dies:</b> Dies Problem<br>
                <b>Mach:</b> Machine Problem<br>
                <b>Mat:</b> Material Problem<br>
                <b>Meth:</b> Methode Handling & Setting Problem
            </td>
        </tr>
        <tr><td>Foreman Prod.</td><td>Foreman QC</td><td>Foreman D.S</td><td>( M. Azka )<br>Ka. Sie Prod</td><td>( Ruri S. )<br>Ka. Sie MAD</td><td>( Ilham M.W )<br>Ka. Sie MTC & QA</td><td>( Eko H )<br>Ka. Dept.</td><td>( Sriyanto )<br>Division Head</td><td>( Adang K )<br>G.A.</td></tr>
        <tr style="height: 40px;"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    </table>

    <div style="text-align: center; font-weight: bold; margin: 5px 0; font-size: 12px;">LIST PENGELUARAN SCRAP EX PRODUKSI</div>

    {{-- TABEL DATA UTAMA --}}
    <table class="table-pdf">
        <thead>
            <tr>
                <th rowspan="2" width="20px">NO</th>
                <th rowspan="2" width="55px">TANGGAL</th>
                <th rowspan="2" width="70px">JOB NUMBER</th>
                <th rowspan="2" width="30px">QTY</th>
                <th rowspan="2" width="40px">BERAT/ PCS</th>
                <th rowspan="2" width="55px">BERAT TOTAL</th>
                <th colspan="4">PENYEBAB SCRAP</th>
                <th rowspan="2" width="90px">JENIS KERUSAKAN</th>
                <th rowspan="2">PENYEBAB</th>
                <th rowspan="2">COUNTER MEASURE</th>
                <th colspan="2">MATERIAL</th>
            </tr>
            <tr>
                <th width="25px">DIES</th><th width="25px">MACH</th><th width="25px">MAT</th><th width="25px">METH</th>
                <th width="40px">IPPI</th><th width="40px">CUSTOMER</th>
            </tr>
        </thead>
        <tbody>
            @php 
                $totalQty = 0; $totalBerat = 0; 
                $sumDies = 0; $sumMach = 0; $sumMat = 0; $sumMeth = 0;
            @endphp
            @foreach($item as $index => $row)
            @php
                $harian = optional($row->inputHarian);
                
                // Logika: Ambil item dari harian, kalau harian null (BA Manual), ambil dari relasi item langsung
                $prodItem = ($harian->exists && $harian->item) ? $harian->item : $row->item;
                
                $qty = $row->Qty ?? 0;
                $beratPcs = optional($prodItem)->Berat ?? 0;
                $beratTotal = $beratPcs * $qty;
                
                $area = strtolower($row->TipeReject ?? optional($row->masterReject)->TipeReject ?? '');
                
                $customer = optional(optional($prodItem)->customer);
                $namaCust = strtoupper($customer->NamaCustomer ?? '');
                $isADM = str_contains($namaCust, 'ADM');

                // Tanggal Display dinamis
                $tglDisplay = $harian->exists ? 
                              \Carbon\Carbon::parse($harian->TanggalProduksi)->format('d-M-y') : 
                              \Carbon\Carbon::parse($row->created_at)->format('d-M-y');

                // Akumulasi Total
                $totalQty += $qty; $totalBerat += $beratTotal;
                if(in_array($area, ['dies', 'op-10'])) $sumDies += $qty;
                if(in_array($area, ['machine', 'mach', 'op-20'])) $sumMach += $qty;
                if(in_array($area, ['material', 'mat', 'op-30'])) $sumMat += $qty;
                if(in_array($area, ['method', 'meth', 'op-40'])) $sumMeth += $qty;
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $tglDisplay }}</td>
                <td class="font-bold">{{ optional($prodItem)->JobNumber ?? '-' }}</td>
                <td>{{ number_format($qty, 0) }}</td>
                <td>{{ number_format($beratPcs, 2) }}</td>
                <td class="font-bold">{{ number_format($beratTotal, 2) }}</td>
                
                <td class="bg-gray">{{ in_array($area, ['dies', 'op-10']) ? $qty : '' }}</td>
                <td class="bg-gray">{{ in_array($area, ['machine', 'mach', 'op-20']) ? $qty : '' }}</td>
                <td class="bg-gray">{{ in_array($area, ['material', 'mat', 'op-30']) ? $qty : '' }}</td>
                <td class="bg-gray">{{ in_array($area, ['method', 'meth', 'op-40']) ? $qty : '' }}</td>

                <td class="font-bold">{{ $row->NamaKerusakan ?? (optional($row->masterReject)->NamaReject ?? '-') }}</td>
                <td class="text-left">{{ $row->Penyebab ?? '-' }}</td>
                <td class="text-left">{{ $row->CounterMeasure ?? '-' }}</td>
                
                <td class="bg-gray">{{ !$isADM ? 'IPPI' : '-' }}</td>
                <td class="bg-gray">{{ $isADM ? 'ADM' : '-' }}</td>
            </tr>
            @endforeach
            
            {{-- ROW TOTAL --}}
            <tr class="font-bold">
                <td colspan="3" class="bg-yellow">TOTAL</td>
                <td class="bg-yellow">{{ $totalQty }}</td>
                <td class="bg-yellow"></td>
                <td class="bg-yellow" style="background-color: #fce4d6;">{{ number_format($totalBerat, 2) }}</td>
                <td style="background-color: #00b0f0; color: #fff;">{{ $sumDies }}</td>
                <td style="background-color: #ff0000; color: #fff;">{{ $sumMach }}</td>
                <td style="background-color: #ffff00;">{{ $sumMat }}</td>
                <td style="background-color: #00b050; color: #fff;">{{ $sumMeth }}</td>
                <td colspan="5" style="border: none;"></td>
            </tr>
        </tbody>
    </table>

    {{-- FOOTER SUMMARY --}}
    <table style="width: 300px; margin-top: 10px; border-collapse: collapse;">
        <tr>
            <td width="100px" class="bg-yellow">TOTAL COST REJECT</td>
            <td width="150px" style="background-color: #fce4d6; font-weight: bold; font-size: 10px;">
                Rp {{ number_format($totalBerat * 19000, 0, ',', '.') }}
            </td>
        </tr>
    </table>

</body>
</html>