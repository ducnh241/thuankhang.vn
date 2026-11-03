@extends('frontend.layouts.app')

{{-- Tìm kiếm (S1): từ khóa (tên, mã, mô tả ngắn), lọc danh mục, sắp xếp. --}}
@section('title', $q !== '' ? 'Tìm “'.$q.'”' : 'Tìm sản phẩm')

@section('content')
  <div class="mx-auto max-w-7xl px-4 pt-6">
    <h1 class="text-2xl font-bold text-slate-900 md:text-3xl">{{ $q !== '' ? 'Kết quả cho “'.$q.'”' : 'Tìm sản phẩm' }}</h1>

    <form method="GET" action="{{ route('shop.search') }}" class="mt-5 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center">
      <input type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="Tên sản phẩm, mã sản phẩm…" aria-label="Từ khóa"
        class="w-full flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
      <select name="category" aria-label="Danh mục" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500">
        <option value="">Tất cả danh mục</option>
        @foreach ($categories as $item)
          <option value="{{ $item->id }}" @selected($category?->id === $item->id)>{{ $item->indentedName() }}</option>
        @endforeach
      </select>
      <select name="sort" aria-label="Sắp xếp" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500">
        @foreach (\App\Support\Catalog::SORTS as $key => $label)
          <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
        @endforeach
      </select>
      <button type="submit" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tìm</button>
    </form>

    @if ($products === null)
      <p class="mt-10 text-center text-slate-500">Nhập từ khóa hoặc chọn danh mục để tìm sản phẩm.</p>
    @elseif ($products->isEmpty())
      <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">
        Không tìm thấy sản phẩm phù hợp. Thử từ khóa khác hoặc bỏ lọc danh mục.
      </div>
    @else
      <p class="mt-6 text-sm text-slate-500">Tìm thấy {{ number_format($products->total(), 0, ',', '.') }} sản phẩm</p>
      <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        @foreach ($products as $product)
          @include('frontend.partials.product-card')
        @endforeach
      </div>
      {{ $products->links('frontend.partials.pagination') }}
    @endif
  </div>
@endsection
