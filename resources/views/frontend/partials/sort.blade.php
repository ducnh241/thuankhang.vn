{{-- Ô sắp xếp danh sách sản phẩm (tự gửi khi đổi). Biến: $sort, $hidden (các tham số giữ lại). --}}
<form method="GET" class="flex items-center gap-2 text-sm">
  @foreach ($hidden ?? [] as $name => $value)
    @if ($value !== null && $value !== '')<input type="hidden" name="{{ $name }}" value="{{ $value }}" />@endif
  @endforeach
  <label for="sort" class="text-slate-500">Sắp xếp</label>
  <select id="sort" name="sort" data-autosubmit class="rounded-lg border border-slate-200 bg-white py-1.5 pr-8 pl-3 text-sm outline-none focus:border-brand-500">
    @foreach (\App\Support\Catalog::SORTS as $key => $label)
      <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
    @endforeach
  </select>
  <noscript><button type="submit" class="rounded-lg border border-slate-200 px-3 py-1.5">Áp dụng</button></noscript>
</form>
