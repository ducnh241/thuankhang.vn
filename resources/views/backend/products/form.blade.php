@extends('layouts.backend')

{{-- Thêm / sửa sản phẩm (A6), dựng như trang sửa Trang: cột chính (Tên, Đường dẫn, Mô tả ngắn, Mô tả — Tiptap; Ảnh;
     Giá) + cột phụ (Trạng thái, Danh mục, Mã / thứ tự). Giá nhập ĐÃ GỒM VAT; dưới ô hiện giá trước VAT và cơ sở hoa hồng.
     JS: product-form.js (đường dẫn theo tên, trình soạn thảo, tính giá, ảnh), edit-form.js (thay đổi chưa lưu). --}}
@php
    use App\Models\Product;
    use App\Support\Money;
    use App\Support\Percent;

    $editing = $product->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $old = fn (string $field, $default = null) => session()->hasOldInput() ? (is_string(old($field)) ? old($field) : '') : (string) ($default ?? $product->{$field});
    $switchOn = fn (string $field, bool $saved) => session()->hasOldInput() ? (string) old($field) === '1' : $saved;
    $onSale = $switchOn('status', $product->status !== false);
    $featured = $switchOn('is_featured', (bool) $product->is_featured);
    $price = $old('price', $product->price === null ? '' : Money::format($product->price));
    $vat = $old('vat_rate', Percent::format($product->vat_rate));
    $categoryId = $old('category_id', (string) $product->category_id);
    $images = $editing ? $product->images : collect();
    $imageError = $errors->first('images') ?: collect($errors->keys())->filter(fn ($k) => str_starts_with($k, 'images.'))->map(fn ($k) => $errors->first($k))->first();
    $base = url('/'.Product::PATH).'/';
    $switch = fn (string $id, string $name, bool $on, string $title, string $hint) => new \Illuminate\Support\HtmlString(
        '<label class="flex items-start gap-3" for="'.$id.'">'
        .'<button type="button" role="switch" id="'.$id.'" aria-checked="'.($on ? 'true' : 'false').'" class="ant-switch mt-0.5 flex-none'.($on ? ' ant-switch-checked' : '').'"><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>'
        .'<input type="hidden" name="'.$name.'" value="0" /><input type="checkbox" name="'.$name.'" value="1" class="switch-input" hidden'.($on ? ' checked' : '').' />'
        .'<span><span class="block text-[rgba(0,0,0,0.85)]">'.e($title).'</span><span class="block '.$muted.'">'.e($hint).'</span></span></label>');
@endphp

@section('title', $editing ? $product->name : 'Thêm sản phẩm')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.products.index') }}" class="page-topbar-crumb" title="Về danh sách sản phẩm">Sản phẩm</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $product->name : 'Thêm sản phẩm' }}</h1>
    @if ($editing && ! $product->status)<span class="ant-tag">Đang ẩn</span>@endif
  </div>
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="product-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo sản phẩm' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('product/delete')
        <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa sản phẩm"
          data-delete-url="{{ route('backend.products.destroy', $product) }}" data-delete-title="Xóa sản phẩm “{{ $product->name }}”?" data-delete-content="Sản phẩm ẩn khỏi cửa hàng và danh sách; đơn hàng cũ vẫn giữ thông tin.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
      @endcan
    @else
      <a href="{{ route('backend.products.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="product-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo sản phẩm</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="product-form" action="{{ $editing ? route('backend.products.update', $product) : route('backend.products.store') }}" enctype="multipart/form-data"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập tên sản phẩm.">
            <div class="ant-col ant-form-item-label"><label for="product_name" class="ant-form-item-required" title="Tên sản phẩm">Tên sản phẩm</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="product_name" name="name" value="{{ $old('name') }}" class="ant-input" maxlength="255" placeholder="VD: Viên uống Vitamin C 1000mg" />
              </div></div>
              {{ ant_form_error($errors->first('name')) }}
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('slug') }}">
            <div class="ant-col ant-form-item-label"><label for="product_slug" title="Đường dẫn">Đường dẫn</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <span class="ant-input-group-wrapper"><span class="ant-input-wrapper ant-input-group"
                  ><span class="ant-input-group-addon"><span class="page-slug-base" title="{{ $base }}"><bdi>{{ $base }}</bdi></span></span
                  ><input type="text" id="product_slug" name="slug" value="{{ $old('slug') }}" class="ant-input" maxlength="255" placeholder="tu-dong-theo-ten" @if ($old('slug') !== '') data-touched @endif /></span
                ></span>
              </div></div>
              {{ ant_form_error($errors->first('slug')) }}
              <div class="ant-form-item-extra">Để trống thì tự tạo theo tên. Chỉ chữ thường không dấu, số và dấu “-”.</div>
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('short_description') }}">
            <div class="ant-col ant-form-item-label"><label for="product_short_description" title="Mô tả ngắn">Mô tả ngắn</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="product_short_description" name="short_description" class="ant-input" rows="3" maxlength="500" placeholder="Vài câu giới thiệu, hiện dưới tên ở trang sản phẩm">{{ $old('short_description') }}</textarea>
              </div></div>
              {{ ant_form_error($errors->first('short_description')) }}
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('description') }}">
            <div class="ant-col ant-form-item-label"><label for="product_description_editor" title="Mô tả chi tiết">Mô tả chi tiết</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <div class="rich-editor page-editor" data-placeholder="Thành phần, công dụng, cách dùng…" data-label="Mô tả chi tiết">
                  <div class="rich-editor-toolbar"></div>
                  <div class="rich-editor-content" id="product_description_editor"></div>
                  <textarea class="rich-editor-source hidden" aria-label="HTML mô tả"></textarea>
                  <div class="rich-editor-footer"></div>
                </div>
                <textarea name="description" class="hidden" aria-hidden="true" tabindex="-1">{{ $old('description') }}</textarea>
              </div></div>
              {{ ant_form_error($errors->first('description')) }}
            </div>
          </div>
        </div>
      </div>

      {{-- Ảnh: picture card như Upload của Ant Design; ảnh đầu = ảnh đại diện --}}
      <div class="ant-card ant-card-bordered" data-card="images">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Ảnh sản phẩm</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $imageError ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}" data-gallery data-max="{{ Product::MAX_IMAGES }}" data-max-size="{{ 4 * 1024 * 1024 }}">
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <div class="ant-upload-picture-card-wrapper">
                  <div class="ant-upload-list ant-upload-list-picture-card product-gallery">
                    @foreach ($images as $image)
                      <div class="ant-upload-list-picture-card-container relative" data-gallery-item>
                        <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
                          <div class="ant-upload-list-item-info">
                            <span><a class="ant-upload-list-item-thumbnail" href="{{ $image->url() }}" target="_blank" rel="noopener noreferrer"><img src="{{ $image->url() }}" alt="" class="ant-upload-list-item-image" /></a></span>
                          </div>
                          <span class="ant-upload-list-item-actions"
                            ><a href="{{ $image->url() }}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">{{ ant_icon('eye') }}</a
                            ><button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only" title="Đặt làm ảnh đại diện" data-gallery-cover>{{ ant_icon('star') }}</button
                            ><button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only" title="Bỏ ảnh" data-gallery-remove>{{ ant_icon('delete') }}</button></span>
                        </div>
                        <input type="hidden" name="keep_images[]" value="{{ $image->id }}" />
                        <span class="ant-tag ant-tag-blue product-gallery-cover">Ảnh đại diện</span>
                      </div>
                    @endforeach
                    <div @class(['ant-upload ant-upload-select ant-upload-select-picture-card', 'hidden' => $images->count() >= Product::MAX_IMAGES])>
                      <span tabindex="0" class="ant-upload" role="button" data-gallery-pick aria-label="Thêm ảnh sản phẩm">
                        <div>{{ ant_icon('plus') }}<div class="mt-2">Tải lên</div></div>
                      </span>
                    </div>
                  </div>
                </div>
                {{-- Ô chọn tệp thật nằm ngoài khung .ant-upload; product-form.js gom các tệp đã chọn vào ô này --}}
                <input type="file" name="images[]" multiple accept="image/png,image/jpeg,image/webp" class="hidden" data-gallery-input aria-label="Ảnh sản phẩm" />
              </div></div>
              {{ ant_form_error($imageError) }}
              <div class="ant-form-item-extra">Tối đa {{ Product::MAX_IMAGES }} ảnh PNG, JPG hoặc WEBP, mỗi ảnh tối đa 4 MB; ảnh vuông là đẹp nhất. Ảnh đầu tiên là ảnh đại diện — bấm ★ để đổi.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered" data-card="price">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Giá bán</div></div></div>
        <div class="ant-card-body">
          <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('price') }}" data-required="Nhập giá bán.">
              <div class="ant-col ant-form-item-label"><label for="product_price" class="ant-form-item-required" title="Giá bán (đã gồm VAT)">Giá bán (đã gồm VAT)</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <span class="ant-input-group-wrapper"><span class="ant-input-wrapper ant-input-group"><input type="text" id="product_price" name="price" value="{{ $price }}" class="ant-input tabular-nums" inputmode="numeric" data-money placeholder="0" /><span class="ant-input-group-addon">đ</span></span></span>
                </div></div>
                {{ ant_form_error($errors->first('price')) }}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('vat_rate') }}" data-required="Nhập VAT.">
              <div class="ant-col ant-form-item-label"><label for="product_vat" class="ant-form-item-required" title="VAT">VAT</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <span class="ant-input-affix-wrapper"><input type="text" id="product_vat" name="vat_rate" value="{{ $vat }}" class="ant-input tabular-nums" inputmode="decimal" /><span class="ant-input-suffix">%</span></span>
                </div></div>
                {{ ant_form_error($errors->first('vat_rate')) }}
              </div>
            </div>
          </div>
          {{-- Tính sẵn theo công thức đặc tả 8.3 (product-form.js cập nhật khi gõ) --}}
          <div class="ant-alert ant-alert-info" role="status" id="price-breakdown" data-fee="{{ (float) $businessFee }}">
            {{ ant_icon('info-circle', 'ant-alert-icon') }}
            <div class="ant-alert-content"><div class="ant-alert-message">
              Giá trước VAT: <strong class="tabular-nums" data-price-before-vat>{{ $product->price !== null && $product->vat_rate !== null ? Money::format($product->priceBeforeVat()) : '—' }}</strong> đ
              · Cơ sở tính hoa hồng (trừ phí doanh nghiệp {{ Percent::format($businessFee) }}%): <strong class="tabular-nums" data-commission-base>—</strong> đ
            </div></div>
          </div>
        </div>
      </div>
    </div>

    {{-- Cột phụ --}}
    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Trạng thái</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('status') }}">
            {{ $switch('product_status_switch', 'status', $onSale, 'Đang bán', 'Tắt để ẩn sản phẩm khỏi cửa hàng (vẫn giữ trong danh sách).') }}
            {{ ant_form_error($errors->first('status')) }}
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('is_featured') }}">
            {{ $switch('product_featured_switch', 'is_featured', $featured, 'Sản phẩm nổi bật', 'Hiện ở mục nổi bật trên trang chủ.') }}
          </div>
          @if ($editing)
            <dl class="profile-facts mt-4 mb-0">
              <dt>Tạo lúc</dt><dd class="tabular-nums">{{ $product->created_at?->format('d/m/Y H:i') }}</dd>
              <dt>Cập nhật</dt><dd class="tabular-nums">{{ $product->updated_at?->format('d/m/Y H:i') }}</dd>
            </dl>
          @endif
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Phân loại</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('category_id') }}" data-required="Chọn danh mục.">
            <div class="ant-col ant-form-item-label"><label for="product_category" class="ant-form-item-required" title="Danh mục">Danh mục</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-show-search data-placeholder="Chọn danh mục" id="product_category" name="category_id" class="w-full">
                  <option value=""></option>
                  @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($categoryId === (string) $category->id)>{{ $category->indentedName() }}{{ $category->status ? '' : ' (đang ẩn)' }}</option>
                  @endforeach
                </select>
              </div></div>
              {{ ant_form_error($errors->first('category_id')) }}
              @if ($categories->isEmpty())
                <div class="ant-form-item-extra">Chưa có danh mục sản phẩm — @can('product/add')<a href="{{ route('backend.categories.index', ['type' => 'product', 'new' => 1]) }}">thêm danh mục</a>@else nhờ quản trị thêm danh mục @endcan trước.</div>
              @endif
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('sku') }}">
            <div class="ant-col ant-form-item-label"><label for="product_sku" title="Mã sản phẩm">Mã sản phẩm (SKU)</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="product_sku" name="sku" value="{{ $old('sku') }}" class="ant-input uppercase" maxlength="50" placeholder="VD: VITC-1000" />
              </div></div>
              {{ ant_form_error($errors->first('sku')) }}
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('sort_order') }}">
            <div class="ant-col ant-form-item-label"><label for="product_sort" title="Thứ tự">Thứ tự hiển thị</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                {{ ant_input_number('sort_order', $old('sort_order', (string) ($product->sort_order ?? 0)), ['id' => 'product_sort', 'min' => -9999, 'max' => 9999, 'class' => 'w-full']) }}
              </div></div>
              {{ ant_form_error($errors->first('sort_order')) }}
              <div class="ant-form-item-extra">Số nhỏ đứng trước.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
