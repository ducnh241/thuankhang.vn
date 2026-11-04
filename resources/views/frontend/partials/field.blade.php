{{-- Ô nhập của form website. Biến: $name, $label, $type (text), $value, $required, $hint, $attrs (chuỗi thuộc tính thêm). --}}
@php $id = 'f_'.$name; $hasError = $errors->has($name); @endphp
<div>
  <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-slate-700">{{ $label }}@if (! empty($required))<span class="text-rose-500"> *</span>@endif</label>
  <input id="{{ $id }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" value="{{ $value ?? '' }}" {!! $attrs ?? '' !!}
    @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}_error" @endif
    @class(['w-full rounded-lg border bg-white px-3 py-2.5 text-sm outline-none transition focus:ring-2',
      'border-rose-400 focus:border-rose-500 focus:ring-rose-100' => $hasError,
      'border-slate-300 focus:border-brand-500 focus:ring-brand-100' => ! $hasError]) />
  @if ($hasError)<p id="{{ $id }}_error" class="mt-1 text-sm text-rose-600">{{ $errors->first($name) }}</p>@endif
  @if (! empty($hint))<p class="mt-1 text-xs text-slate-500">{!! $hint !!}</p>@endif
</div>
