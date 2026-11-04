{{-- Nhãn trạng thái đơn trên website. Biến: $status (Order::displayStatus()). --}}
@php
    $classes = [
        'pending_payment' => 'bg-amber-100 text-amber-800', 'paid' => 'bg-sky-100 text-sky-800', 'processing' => 'bg-indigo-100 text-indigo-800',
        'completed' => 'bg-brand-100 text-brand-800', 'expired' => 'bg-slate-100 text-slate-600', 'cancelled' => 'bg-rose-100 text-rose-700',
    ];
@endphp
<span class="rounded-full px-3 py-1 text-xs font-semibold {{ $classes[$status] ?? 'bg-slate-100 text-slate-600' }}">{{ \App\Models\Order::STATUSES[$status] ?? $status }}</span>
