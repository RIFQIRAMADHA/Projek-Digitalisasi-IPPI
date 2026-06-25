<?php

namespace App\Models\Produksi\Master;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MsCustomer extends Model
{
    use HasFactory;

    protected $table = 'prod_mscustomer';
    protected $primaryKey = 'IdCustomer';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true; // karena tabel punya created_at & updated_at

    protected $fillable = [
        'IdCustomer',
        'NamaCustomer',
        'AlamatCustomer',
        'NamaCustomerPIC',
        'NoTelpCustomer',
        'EmailCustomer',
        'NPWPCustomer',
        'Status',
        'create_by',
        'update_by'
    ];

}
