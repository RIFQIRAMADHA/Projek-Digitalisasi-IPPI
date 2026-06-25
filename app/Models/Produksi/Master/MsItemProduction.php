<?php

namespace App\Models\Produksi\Master;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Produksi\Transaksi\TrsInputHarian;

/** @method \Illuminate\Database\Eloquent\Relations\BelongsTo customer() */

class MsItemProduction extends Model
{
    use HasFactory;

    protected $table = 'prod_msitemproduction'; // sesuai validasi kamu
    protected $primaryKey = 'IdItemProduksi';
    protected $keyType = 'string';
    public $incrementing = false; // karena pakai id manual

    //karena di migration ada timestamps
    public $timestamps = true;
    

    protected $fillable = [
        'IdItemProduksi',
        'IdCustomer',
        'JobNumber',
        'PartNumber',
        'NamaPart',
        'Model',
        'Gambar',
        'CT',
        'BestGSPH',
        'Berat',
        'QtyPerPallet',
        'Status',
        'create_by',
        'update_by'
    ];

    protected $casts = [
        'CT' => 'decimal:2',
        'BestGSPH' => 'decimal:2',
        'Berat' => 'decimal:2',
        'QtyPerPallet' => 'decimal:2',
        'Status' => 'integer'
    ];

    public function customer()
    {
        return $this->belongsTo(MsCustomer::class, 'IdCustomer', 'IdCustomer');
    }

    // File: ItemProduction.php
    public function inputHarian()
    {
        return $this->hasMany(\App\Models\Produksi\Transaksi\TrsInputHarian::class, 'IdItemProduksi', 'IdItemProduksi');
    }
}
