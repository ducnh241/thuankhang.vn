{{-- Thông báo sau thao tác (session success / error). --}}
@if (session('success') || session('error'))
  <div class="mx-auto max-w-7xl px-4 pt-4">
    @if (session('success'))
      <div class="rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
      <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ session('error') }}</div>
    @endif
  </div>
@endif
