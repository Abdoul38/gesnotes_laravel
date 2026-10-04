{{-- Habillage commun des pages de connexion (navy et crème, comme l'Espace Parents) --}}
@push('head')
<style>
/* Mêmes couleurs que l'Espace Parents — navy et crème */
.login-page { background: #0F2942 !important; font-family: Georgia, 'Times New Roman', serif; }
.login-card { background: #FAF6ED !important; box-shadow: 0 10px 40px rgba(0,0,0,.3) !important; }
.login-logo h1 { color: #0F2942 !important; font-family: Georgia, serif; letter-spacing: 1px; }
.login-logo p { color: #8B6F1F !important; font-style: italic; }
.logo-big { background: #0F2942 !important; }
.login-card .form-label { color: #444 !important; }
.login-card .form-control { border: 1px solid #d8cfb8 !important; background: #fff !important; }
.login-card .form-control:focus { border-color: #0F2942 !important; }
.login-card .btn-primary { background: #0F2942 !important; border-color: #0F2942 !important; }
.login-card .btn-primary:hover { background: #1A3A5C !important; border-color: #1A3A5C !important; }
</style>
@endpush
