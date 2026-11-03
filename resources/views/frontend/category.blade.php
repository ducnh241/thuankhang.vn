@extends('frontend.layouts.app')

{{-- Trang danh mục (S1): đường dẫn, danh mục con, sắp xếp, lưới sản phẩm (gồm cả danh mục con), phân trang. --}}
@section('title', $category->name)
@section('description', (string) $category->description)

@section('content')
  <div class="mx-auto max-w-7xl px-4 pt-6">
    @include('frontend.partials.breadcrumb', ['trail' => $trail])
    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 md:text-3xl">{{ $category->name }}</h1>
        @if ($category->description)<p class="mt-1 max-w-2xl text-sm text-slate-600">{{ $category->description }}</p>@endif
      </div>
      @include('frontend.partials.sort', ['sort' => $sort])
    </div>

    @if ($children->isNotEmpty())
      <div class="mt-5 flex flex-wrap gap-2">
        @foreach ($children as $child)
          <a href="{{ $child->url() }}" class="rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm text-slate-700 hover:border-brand-500 hover:text-brand-700">{{ $child->name }}</a>
        @endforeach
      </div>
    @endif

    <p class="mt-6 text-sm text-slate-500">{{ number_format($products->total(), 0, ',', '.') }} sản phẩm</p>
    @if ($products->isNotEmpty())
      <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        @foreach ($products as $product)
          @include('frontend.partials.product-card')
        @endforeach
      </div>
      {{ $products->links('frontend.partials.pagination') }}
    @else
      <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">Danh mục này chưa có sản phẩm.</div>
    @endif
  </div>
@endsection
