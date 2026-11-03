@extends('layouts.backend')

@section('title', 'Tham số chung - Cấu hình affiliate')
@section('inline-errors', '1')

{{-- A5 – Tham số chung (đặc tả 6.5): các ô dựng từ App\Support\AffiliateSettings::FIELDS, chia thẻ theo nhóm. --}}
@php
    use App\Support\AffiliateSettings;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $value = fn (string $key) => session()->hasOldInput()
        ? (is_scalar(old($key)) ? (string) old($key) : '')
        : AffiliateSettings::display($key, $settings[$key]);
    $units = AffiliateSettings::UNITS;
    $groups = collect($keys)->groupBy(fn ($key) => AffiliateSettings::FIELDS[$key][0]);
@endphp

@section('topbar')
  @include('backend.affiliate._topbar', ['title' => 'Tham số chung', 'form' => 'affiliate-settings-form'])
@endsection

@section('content')
<form method="POST" id="affiliate-settings-form" action="{{ route('backend.affiliate.settings.update') }}" class="ant-form ant-form-vertical edit-form mx-auto flex max-w-[880px] flex-col gap-4"
  novalidate autocomplete="off" @if ($canEdit) data-edit-form @else data-readonly @endif @if ($errors->any()) data-dirty @endif>
  @csrf
  @method('PUT')
  @include('backend.affiliate._readonly')
  @foreach (AffiliateSettings::GROUPS as $group => $title)
    <div class="ant-card ant-card-bordered" data-group="{{ $group }}" @unless ($canEdit) inert @endunless>
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">{{ $title }}</div></div></div>
      <div class="ant-card-body">
        <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
          @foreach ($groups[$group] ?? [] as $key)
            @php [, $label, $type, $default, $rules, $hint] = AffiliateSettings::FIELDS[$key]; $required = in_array('required', $rules, true); @endphp
            <div class="ant-row ant-form-item{{ $errors->has($key) ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}">
              <div class="ant-col ant-form-item-label"><label for="aff_{{ $key }}" @class(['ant-form-item-required' => $required]) title="{{ $label }}">{{ $label }}</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  @php $input = '<input type="text" id="aff_'.$key.'" name="'.$key.'" value="'.e($value($key)).'" class="ant-input'
                      .(in_array($type, ['percent', 'money', 'int'], true) ? ' tabular-nums' : '').(in_array($type, ['code', 'name'], true) ? ' uppercase' : '').'"'
                      .match ($type) { 'money', 'int' => ' inputmode="numeric"'.($type === 'money' ? ' data-money' : ''), 'percent' => ' inputmode="decimal"', default => '' }.' />'; @endphp
                  @if ($type === 'percent')
                    {{-- Input có hậu tố --}}
                    <span class="ant-input-affix-wrapper">{!! $input !!}<span class="ant-input-suffix">%</span></span>
                  @elseif ($type === 'money' || isset($units[$key]))
                    {{-- Input có addon đơn vị --}}
                    <span class="ant-input-group-wrapper"><span class="ant-input-wrapper ant-input-group">{!! $input !!}<span class="ant-input-group-addon">{{ $type === 'money' ? 'đ' : $units[$key] }}</span></span></span>
                  @else
                    {!! $input !!}
                  @endif
                </div></div>
                {{ ant_form_error($errors->first($key)) }}
                @if ($hint || ($settings[$key] === null && $default === null && in_array($key, ['pit_percent', 'bank_code', 'bank_account', 'bank_account_name', 'root_referral_code'], true)))
                  <div class="ant-form-item-extra">@if ($settings[$key] === null && $default === null && $key !== 'max_vp_per_province')<span class="ant-tag ant-tag-orange m-0 mr-1">Chưa cấu hình</span>@endif{{ $hint }}</div>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  @endforeach
  <p class="ant-typography ant-typography-secondary m-0 text-xs">Ngày trả thưởng KPI sửa ở trang <a href="{{ route('backend.affiliate.kpi.index') }}">KPI tháng</a>.</p>
</form>
@endsection
