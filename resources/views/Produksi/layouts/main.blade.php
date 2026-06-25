<!DOCTYPE html>
<html lang="id">
<head>
    {{-- Core CSS A-Track --}}
    <link rel="stylesheet" href="{{ asset('css/Produksi/header.css') }}">
    <link rel="stylesheet" href="{{ asset('css/Produksi/components.css') }}">
    <link rel="stylesheet" href="{{ asset('css/Produksi/layout.css') }}"> 
    
    {{-- 1. SELECT2 CSS LOKAL (Anti-Block) --}}
    <link rel="stylesheet" href="{{ asset('css/select2.min.css') }}">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'A-Track')</title>

    {{-- PWA Setup --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#e11d2e">
    <link rel="apple-touch-icon" href="{{ asset('images/logo-ippi.png') }}">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- Core CSS A-Track --}}
    <link rel="stylesheet" href="/css/produksi/header.css">
    <link rel="stylesheet" href="/css/produksi/components.css">
    
    {{-- 1. SELECT2 CSS LOKAL (Anti-Block) --}}
    <link rel="stylesheet" href="{{ asset('css/select2.min.css') }}">
    
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <style>
        /* Styling agar Select2 sinkron dengan desain A-Track */
        .select2-container--default .select2-selection--single {
            height: 38px !important;
            border: 1px solid #ddd !important;
            border-radius: 8px !important;
            display: flex;
            align-items: center;
        }
        .select2-container--default .select2-selection__arrow {
            height: 36px !important;
        }
        .select2-container {
            width: 100% !important;
        }
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #4361ee !important;
            outline: none !important;
            padding: 8px !important;
            border-radius: 6px !important;
        }
        
        .select2-dropdown {
            z-index: 9999 !important; /* Biar nggak ketutup elemen lain */
            border: 1px solid #ddd !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
        }

        .select2-hidden-accessible {
            border: 0 !important;
            clip: rect(0 0 0 0) !important;
            height: 1px !important;
            margin: -1px !important;
            overflow: hidden !important;
            padding: 0 !important;
            position: absolute !important;
            width: 1px !important;
        }

        /* 1. Sembunyikan field AM/PM secara paksa */
        input[type="time"]::-webkit-datetime-edit-ampm-field {
            display: none !important;
        }

        /* 2. Hapus panah/spinner bawaan yang memicu picker 12-jam */
        input[type="time"]::-webkit-inner-spin-button,
        input[type="time"]::-webkit-clear-button {
            -webkit-appearance: none !important;
            display: none !important;
        }

        /* 3. Paksa agar input terlihat seperti text standar */
        input[type="time"] {
            -webkit-appearance: textfield !important;
            -moz-appearance: textfield !important;
            appearance: textfield !important;
        }
    </style>

    @vite(['resources/js/app.js'])
</head>

<body>

{{-- HEADER --}}
@include('Produksi.components.header')

<div class="page-container">
    <div class="content-card">
        <div class="card-header">
            <h2 class="page-title">@yield('page-title')</h2>
            <div class="card-actions">
                @yield('card-actions')
            </div>
        </div>

        <div class="card-body">
            @yield('content')
        </div>
    </div>
</div>

{{-- JAVASCRIPT SECTION --}}
{{-- 1. JQuery Wajib Paling Atas --}}
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

{{-- 2. Select2 JS LOKAL --}}
<script src="{{ asset('js/select2.min.js') }}"></script>

{{-- 3. SweetAlert2 LOKAL --}}
<script src="{{ asset('js/sweetalert2.all.min.js') }}"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    
    /* --- FUNGSI SEARCH DROPDOWN (SELECT2) --- */
    window.initSelect2 = function(selector) {
        const target = selector || '.custom-select2, .select-item-search';
        
        $(target).each(function() {
            if (!$(this).hasClass("select2-hidden-accessible")) {
                $(this).select2({
                    placeholder: "--- Cari Job / Nama Item ---",
                    allowClear: true,
                    width: '100%',
                    minimumResultsForSearch: 0
                });
            }
        });
    }

    $(document).ready(function() {
        initSelect2();
    });

    /* --- LOGIC JAM DIGITAL --- */
    function updateClock() {
        const now = new Date();
        const time = now.toLocaleTimeString('id-ID', {
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        });
        const clockEl = document.getElementById('clock');
        if (clockEl) clockEl.innerText = time;
    }
    setInterval(updateClock, 1000);
    updateClock();

    /* --- LOGIC DROPDOWN MENU --- */
    document.querySelectorAll('.dropdown-trigger').forEach(trigger => {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            const parent = this.closest('.menu-dropdown');
            document.querySelectorAll('.menu-dropdown.open').forEach(d => {
                if (d !== parent) d.classList.remove('open');
            });
            parent.classList.toggle('open');
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.menu-dropdown')) {
            document.querySelectorAll('.menu-dropdown.open').forEach(d => d.classList.remove('open'));
        }
    });

    /* --- NOTIFIKASI SWEETALERT --- */
    @if(session('login_success_title'))
        Swal.fire({
            icon: 'success',
            title: '{{ session("login_success_title") }}',
            text: '{{ session("login_success_text") }}',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true
        });
    @endif

    @if(session('success'))
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: '{{ session("success") }}',
            timer: 2000,
            showConfirmButton: false
        });
    @endif

    @if(session('error'))
        Swal.fire({
            icon: 'error',
            title: 'Terjadi kesalahan',
            text: '{{ session("error") }}',
            confirmButtonColor: '#e11d2e'
        });
    @endif

    @if(session('unauthorized_title'))
        Swal.fire({
            icon: 'error',
            title: '{{ session("unauthorized_title") }}',
            text: '{{ session("unauthorized_text") }}',
            confirmButtonColor: '#2c3e50',
            confirmButtonText: 'OK',
            allowOutsideClick: false
        });
    @endif
});

function confirmLogout() {
    if (typeof Swal === 'undefined') {
        if(confirm("Apakah Anda yakin ingin keluar?")) {
            document.getElementById('logout-form-header').submit();
        }
        return;
    }

    Swal.fire({
        title: 'Konfirmasi Keluar',
        text: "Apakah Anda yakin ingin mengakhiri sesi dan keluar dari sistem?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2c3e50',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Logout',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Logout...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            const logoutForm = document.getElementById('logout-form-header');
            if (logoutForm) logoutForm.submit();
        }
    });
}
</script>

<script>
    // Daftarin Service Worker PWA
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('PWA ServiceWorker berhasil didaftarkan:', registration.scope);
                })
                .catch(err => {
                    console.log('PWA ServiceWorker gagal didaftarkan:', err);
                });
        });
    }
</script>

</body>
</html>