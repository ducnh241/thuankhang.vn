@extends('layouts.backend')

@section('title', 'Dashboard')

{{-- Tổng quan tạm thời: lời chào + chỉ số hệ thống + nhật ký gần nhất (theo quyền). Dashboard affiliate (A1) làm ở
     Step 10. --}}
@php
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $strong = 'text-[rgba(0,0,0,0.85)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $hour = (int) now()->format('G');
    $greeting = $hour < 11 ? 'Chào buổi sáng' : ($hour < 14 ? 'Chào buổi trưa' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'));
    $weekdays = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
@endphp

@section('topbar')
  <div class="page-topbar-left">
    <h1 class="page-topbar-title">{{ $greeting }}, {{ auth()->user()->name }}</h1>
    <span class="page-topbar-divider max-md:hidden" aria-hidden="true"></span>
    <span class="page-topbar-sub min-w-0 shrink truncate max-md:hidden">{{ $weekdays[$today->dayOfWeek] }}, {{ $today->format('d/m/Y') }}</span>
  </div>
@endsection

@section('content')
<div id="dashboard" class="mx-auto flex w-full max-w-[1200px] flex-col gap-4">
  @if ($stats)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
      @foreach ($stats as $key => [$label, $value, $icon, $url])
        <a href="{{ $url }}" class="ant-card ant-card-bordered block hover:bg-[#fafafa]" data-stat="{{ $key }}">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">{{ $label }}</div>
              <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f7ff] text-base text-[#007bff]">{{ ant_icon($icon) }}</span>
            </div>
            <div class="mt-2 text-2xl font-medium tabular-nums {{ $strong }}">{{ $count($value) }}</div>
          </div>
        </a>
      @endforeach
    </div>
  @endif

  @if ($recentLogs)
    <div class="ant-card ant-card-bordered" data-card="audit">
      <div class="ant-card-head">
        <div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">Hoạt động gần đây</div>
          <div class="ant-card-extra"><a href="{{ route('backend.audit-logs.index') }}">Xem nhật ký</a></div>
        </div>
      </div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content overflow-x-auto">
            <table style="table-layout: auto">
              <tbody class="ant-table-tbody">
                @forelse ($recentLogs as $log)
                  <tr class="ant-table-row">
                    <td class="ant-table-cell">
                      <span class="font-medium {{ $strong }}">{{ $log->actorName() }}</span>
                      <span class="{{ $muted }}">· {{ $log->actionLabel() }}@if ($log->subjectLabel()) {{ mb_strtolower($log->subjectLabel()) }}@if ($log->auditable_id) #{{ $log->auditable_id }}@endif @endif</span>
                    </td>
                    <td class="ant-table-cell whitespace-nowrap text-xs tabular-nums {{ $muted }}" style="text-align: right" title="{{ $log->created_at?->format('d/m/Y H:i:s') }}">{{ $log->created_at?->diffForHumans() }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder"><td colspan="2" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có hoạt động nào.</p></div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  @endif

  @if (! $stats && $recentLogs === null)
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-body">
        <div class="ant-empty"><p class="ant-empty-description">Chào mừng bạn đến với {{ config('app.name') }}. Tài khoản của bạn chưa được cấp quyền xem mục nào — liên hệ quản trị viên nếu cần.</p></div>
      </div>
    </div>
  @endif
</div>
@endsection
