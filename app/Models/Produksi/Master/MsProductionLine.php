<?php

namespace App\Models\Produksi\Master;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Produksi\Transaksi\TrsInputHarian;

class MsProductionLine extends Model
{
    use HasFactory;

    protected $table = 'prod_msproductionline';
    protected $primaryKey = 'IdProductionLine';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'IdProductionLine',
        'NamaProductionLine',
        'Shift',
        'Status',
        'create_by',
        'update_by'
    ];

    public function inputHarian()
    {
        return $this->hasMany(\App\Models\Produksi\Transaksi\TrsInputHarian::class, 'IdProductionLine', 'IdProductionLine');
    }

}