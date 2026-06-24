<?php

namespace App\Models\Produksi\Master;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MsKaryawan extends Authenticatable
{
    use HasFactory;

    protected $table = 'prod_mskaryawan';

    // LOGIN pakai NRP
    protected $primaryKey = 'NRPKaryawan';
    protected $keyType = 'string';
    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'IdKaryawan',
        'NamaKaryawan',
        'NRPKaryawan',
        'PasswordKaryawan',
        'Jabatan',
        'Status',
        'create_by',
        'update_by'
    ];

    protected $hidden = [
        'PasswordKaryawan'
    ];

    /**
     * Laravel Auth ambil password dari kolom ini
     */
    public function getAuthPassword()
    {
        return $this->PasswordKaryawan;
    }

    /**
     * 🔥 PENTING
     * Route (view/edit/delete) pakai IdKaryawan
     */
    public function getRouteKeyName()
    {
        return 'IdKaryawan';
    }

    /**
     * ✨ ACCESSOR JABATAN
     * Otomatis merapikan tampilan jabatan saat dipanggil di View
     * Contoh: 'leader k' -> 'Leader K', 'ppc' -> 'Ppc'
     */
    public function getJabatanAttribute($value)
    {
        return ucwords($value);
    }

    /**
     * ✨ HELPER CEK ROLE
     * Biar nanti lu tinggal panggil: if(auth()->user()->isAdmin())
     */
    public function isAdmin()
    {
        return $this->Jabatan === 'admin';
    }

    public function isLeader()
    {
        return str_contains(strtolower($this->Jabatan), 'leader');
    }
}
