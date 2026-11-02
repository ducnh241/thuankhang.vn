@extends('layouts.backend')

{{-- Cài đặt website (theo settings.html của template): một form, 4 tab (?tab=general|social|seo|tracking) chuyển bằng JS, thanh
     trên cùng "Cài đặt › tab" có dải "Chưa lưu thay đổi". Lưu vào bảng settings sẵn có (App\Support\SiteSettings), dùng
     cho website bên ngoài. Chỉ có quyền xem (thiếu setting/edit): các ô khóa lại (inert), không có nút lưu.
     JS: settings.js (tab, ảnh, chân trang Tiptap, xem trước), edit-form.js (thay đổi chưa lưu). --}}
@php
    use App\Http\Controllers\Backend\SettingController;
    use App\Support\SiteSettings;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    // Lưu lỗi: ô theo dữ liệu vừa gửi (ô xóa trắng thành null qua ConvertEmptyStringsToNull — vẫn phải hiện trống)
    $value = fn (string $key) => session()->hasOldInput() ? (is_string(old($key)) ? old($key) : '') : $settings[$key];
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    $keywords = session()->hasOldInput()
        ? array_values(array_filter((array) old('seo_keywords', []), 'is_string')) // bỏ hết từ khóa rồi lưu lỗi: vẫn trống
        : SiteSettings::keywords($settings['seo_keywords']);
    $keywordError = $errors->has('seo_keywords') ? 'seo_keywords' : collect($errors->keys())->first(fn ($k) => str_starts_with($k, 'seo_keywords.'));
    // Logo / favicon: [ô tệp, khóa lưu, tên, loại nhận, dung lượng tối đa, gợi ý]
    $images = [
        'logo' => ['site_logo', 'Logo', 'image/png,image/jpeg,image/webp', 2 * 1024 * 1024, 'PNG, JPG hoặc WEBP, tối đa 2 MB. Hiện ở đầu trang website.', 'Chọn ảnh PNG, JPG hoặc WEBP.', 'Logo tối đa 2 MB.'],
        'favicon' => ['site_favicon', 'Favicon', 'image/png,image/x-icon,image/vnd.microsoft.icon,image/webp', 512 * 1024, 'Ảnh vuông PNG, ICO hoặc WEBP, tối thiểu 32 × 32 px, tối đa 512 KB.', 'Chọn ảnh PNG, ICO hoặc WEBP.', 'Favicon tối đa 512 KB.'],
    ];
    $tabs = collect(SettingController::TABS)->map(fn ($t) => $t[0]);
@endphp

@section('title', $tabs[$tab].' - Cài đặt')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <span class="page-topbar-crumb">Cài đặt</span>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title" id="page-title">{{ $tabs[$tab] }}</h1>
  </div>
  @if ($canEdit)
    {{-- Hiện khi form có thay đổi chưa lưu --}}
    <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
      <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
      <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
      <button type="submit" form="settings-form" class="save-bar-save">Lưu</button>
    </div>
  @endif
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<form method="POST" id="settings-form" action="{{ route('backend.settings.update') }}" enctype="multipart/form-data"
  class="ant-form ant-form-vertical edit-form mx-auto grid max-w-[1040px] grid-cols-1 items-start gap-4 lg:grid-cols-[220px_minmax(0,1fr)]"
  novalidate autocomplete="off" data-tabs="{{ $tabs->toJson() }}" data-title-suffix="Cài đặt - {{ config('app.name') }}"
  @if ($canEdit) data-edit-form @else data-readonly @endif @if ($errors->any()) data-dirty @endif>
  @csrf
  @method('PUT')
  <input type="hidden" name="_tab" value="{{ $tab }}" />

  <nav class="ant-card ant-card-bordered settings-nav" role="tablist" aria-label="Các mục cài đặt">
    @foreach (SettingController::TABS as $key => [$label, $tabIcon])
      <button type="button" @class(['settings-tab', 'is-active' => $key === $tab]) role="tab" data-tab="{{ $key }}" aria-selected="{{ $key === $tab ? 'true' : 'false' }}" aria-controls="settings-{{ $key }}">{{ ant_icon($tabIcon) }}<span>{{ $label }}</span></button>
    @endforeach
  </nav>

  <div class="flex min-w-0 flex-col gap-4">
    @unless ($canEdit)
      <div class="ant-alert ant-alert-info" role="alert">
        <span class="ant-alert-icon">{{ ant_icon('exclamation-circle') }}</span>
        <div class="ant-alert-content"><div class="ant-alert-message">Bạn chỉ có quyền xem cài đặt.</div></div>
      </div>
    @endunless

    <div class="min-w-0" @unless ($canEdit) inert @endunless>
      {{-- Tab Chung --}}
      <section @class(['flex flex-col gap-4', 'hidden' => $tab !== 'general']) id="settings-general" role="tabpanel" data-panel="general">
        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Website</div></div></div>
          <div class="ant-card-body">
            <div class="ant-row ant-form-item{{ $hasError('site_name') }}" data-required="Nhập tên website.">
              <div class="ant-col ant-form-item-label"><label for="site_name" class="ant-form-item-required" title="Tên website">Tên website</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="site_name" name="site_name" value="{{ $value('site_name') }}" class="ant-input" maxlength="80" placeholder="Đức NH" />
                </div></div>
                {!! $error('site_name') !!}
              </div>
            </div>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
              @foreach ($images as $field => [$key, $label, $accept, $maxSize, $hint, $typeMessage, $sizeMessage])
                @php $src = SiteSettings::imageUrl($settings[$key]); @endphp
                {{-- Chọn ảnh thì xem trước ngay, lưu form mới tải lên; ⓧ trên ảnh = bỏ ảnh --}}
                <div class="ant-row ant-form-item mb-0{{ $hasError($field) }}" data-image-field="{{ $field }}" data-max-size="{{ $maxSize }}" data-type-message="{{ $typeMessage }}" data-size-message="{{ $sizeMessage }}">
                  <div class="ant-col ant-form-item-label"><label for="{{ $field }}-file" title="{{ $label }}">{{ $label }}</label></div>
                  <div class="ant-col ant-form-item-control">
                    <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                      <div class="ant-upload-picture-card-wrapper">
                        <div class="ant-upload-list ant-upload-list-picture-card settings-image" data-current="{{ $src }}">
                          @if ($src)
                            <div class="ant-upload-list-picture-card-container">
                              <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
                                <div class="ant-upload-list-item-info">
                                  <span><a class="ant-upload-list-item-thumbnail" href="{{ $src }}" target="_blank" rel="noopener noreferrer"><img src="{{ $src }}" alt="" class="ant-upload-list-item-image" /></a></span>
                                </div>
                                <span class="ant-upload-list-item-actions"><a href="{{ $src }}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">{{ ant_icon('eye') }}</a><button title="Bỏ ảnh" type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only remove-file">{{ ant_icon('delete') }}</button></span>
                              </div>
                            </div>
                          @endif
                          <div @class(['ant-upload ant-upload-select ant-upload-select-picture-card', 'hidden' => $src])>
                            <span tabindex="0" class="ant-upload" role="button" data-image-pick aria-label="Chọn {{ mb_strtolower($label) }}">
                              <div>{{ ant_icon('plus') }}<div class="mt-2">Tải lên</div></div>
                            </span>
                          </div>
                        </div>
                      </div>
                      {{-- Ô chọn tệp thật nằm ngoài khung .ant-upload (controls.js xóa tệp của ô nằm trong khung sau khi đọc) --}}
                      <input type="file" name="{{ $field }}" id="{{ $field }}-file" accept="{{ $accept }}" class="hidden" />
                      <input type="checkbox" name="remove_{{ $field }}" value="1" class="hidden" data-image-remove />
                    </div></div>
                    {!! $error($field) !!}
                    <div class="ant-form-item-extra">{{ $hint }}</div>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        </div>

        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Liên hệ</div></div></div>
          <div class="ant-card-body">
            <p class="settings-card-desc">Hiện ở đầu trang, chân trang và trang liên hệ của website.</p>
            <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
              <div class="ant-row ant-form-item{{ $hasError('site_hotline') }}">
                <div class="ant-col ant-form-item-label"><label for="site_hotline" title="Hotline">Hotline</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <span class="ant-input-affix-wrapper"><span class="ant-input-prefix">{{ ant_icon('phone') }}</span><input type="tel" id="site_hotline" name="site_hotline" value="{{ $value('site_hotline') }}" class="ant-input" maxlength="30" placeholder="0900 000 000" /></span>
                  </div></div>
                  {!! $error('site_hotline') !!}
                </div>
              </div>
              <div class="ant-row ant-form-item{{ $hasError('site_email') }}" data-type="email" data-type-message="Email không đúng định dạng.">
                <div class="ant-col ant-form-item-label"><label for="site_email" title="Email">Email</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <span class="ant-input-affix-wrapper"><span class="ant-input-prefix">{{ ant_icon('mail') }}</span><input type="email" id="site_email" name="site_email" value="{{ $value('site_email') }}" class="ant-input" maxlength="150" placeholder="lienhe@example.com" /></span>
                  </div></div>
                  {!! $error('site_email') !!}
                </div>
              </div>
            </div>
            <div class="ant-row ant-form-item mb-0{{ $hasError('site_address') }}">
              <div class="ant-col ant-form-item-label"><label for="site_address" title="Địa chỉ">Địa chỉ</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="site_address" name="site_address" class="ant-input" rows="2" maxlength="300">{{ $value('site_address') }}</textarea>
                </div></div>
                {!! $error('site_address') !!}
              </div>
            </div>
          </div>
        </div>

        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Chân trang</div></div></div>
          <div class="ant-card-body">
            <div class="ant-row ant-form-item mb-0{{ $hasError('site_footer') }}">
              <div class="ant-col ant-form-item-label"><label for="site_footer_editor" title="Nội dung chân trang">Nội dung chân trang</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  {{-- Trình soạn thảo Tiptap (editor.js); HTML ghi vào ô ẩn site_footer để gửi cùng form --}}
                  <div class="rich-editor" data-placeholder="Bản quyền, thông tin công ty, vài link ngắn…" data-label="Nội dung chân trang">
                    <div class="rich-editor-toolbar"></div>
                    <div class="rich-editor-content" id="site_footer_editor"></div>
                    <textarea class="rich-editor-source hidden" aria-label="HTML chân trang"></textarea>
                    <div class="rich-editor-footer"></div>
                  </div>
                  <textarea name="site_footer" class="hidden" aria-hidden="true" tabindex="-1">{{ $value('site_footer') }}</textarea>
                </div></div>
                {!! $error('site_footer') !!}
              </div>
            </div>
          </div>
        </div>

        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Bản đồ Google</div></div></div>
          <div class="ant-card-body">
            <div class="ant-row ant-form-item mb-0{{ $hasError('site_map') }}" data-map-message="Dán mã nhúng (iframe) hoặc link Google Maps.">
              <div class="ant-col ant-form-item-label"><label for="site_map" title="Bản đồ">Bản đồ</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="site_map" name="site_map" class="ant-input settings-code" rows="3" maxlength="2000" spellcheck="false" placeholder='&lt;iframe src="https://www.google.com/maps/embed?pb=…"&gt;&lt;/iframe&gt;'>{{ $value('site_map') }}</textarea>
                </div></div>
                {!! $error('site_map') !!}
                <div class="ant-form-item-extra">Trên Google Maps: Chia sẻ → Nhúng bản đồ → Sao chép HTML rồi dán vào đây. Dán link Google Maps cũng được.</div>
              </div>
            </div>
            <div class="settings-map hidden" id="site-map-preview"></div>
          </div>
        </div>
      </section>

      {{-- Tab Mạng xã hội --}}
      <section @class(['flex flex-col gap-4', 'hidden' => $tab !== 'social']) id="settings-social" role="tabpanel" data-panel="social">
        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Mạng xã hội</div></div></div>
          <div class="ant-card-body">
            <p class="settings-card-desc">Link hiện thành biểu tượng ở chân trang website. Để trống để ẩn mạng đó.</p>
            @foreach (SiteSettings::SOCIALS as $key => [$label, $socialIcon, $example])
              @php $name = 'social_'.$key; @endphp
              <div @class(['ant-row ant-form-item', 'mb-0' => $loop->last, 'ant-form-item-has-error ant-form-item-with-help' => $errors->has($name)]) data-type="url" data-type-message="Nhập link đầy đủ, bắt đầu bằng https://">
                <div class="ant-col ant-form-item-label"><label for="{{ $name }}" title="{{ $label }}"><span class="inline-flex items-center gap-2">{{ ant_icon($socialIcon) }}{{ $label }}</span></label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="url" id="{{ $name }}" name="{{ $name }}" value="{{ $value($name) }}" class="ant-input" maxlength="300" placeholder="{{ $example }}" />
                  </div></div>
                  {!! $error($name) !!}
                </div>
              </div>
            @endforeach
          </div>
        </div>
      </section>

      {{-- Tab SEO --}}
      <section @class(['flex flex-col gap-4', 'hidden' => $tab !== 'seo']) id="settings-seo" role="tabpanel" data-panel="seo">
        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thẻ meta mặc định</div></div></div>
          <div class="ant-card-body">
            <p class="settings-card-desc">Dùng cho các trang không tự đặt tiêu đề và mô tả.</p>
            <div class="seo-preview mb-6" aria-label="Xem trước kết quả tìm kiếm">
              <div class="seo-preview-site">
                <span class="seo-preview-favicon" id="seo-preview-favicon">{{ ant_icon('shop') }}</span>
                <div class="min-w-0">
                  <div class="seo-preview-name" id="seo-preview-name"></div>
                  <div class="seo-preview-url">{{ url('/') }}</div>
                </div>
              </div>
              <div class="seo-preview-title" id="seo-preview-title"></div>
              <div class="seo-preview-description" id="seo-preview-description"></div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('seo_title') }}">
              <div class="ant-col ant-form-item-label"><label for="seo_title" title="Tiêu đề">Tiêu đề (meta title)</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="seo_title" name="seo_title" value="{{ $value('seo_title') }}" class="ant-input" maxlength="70" />
                </div></div>
                {!! $error('seo_title') !!}
                <div class="ant-form-item-extra"><span data-counter-for="seo_title" data-max="70"></span></div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('seo_description') }}">
              <div class="ant-col ant-form-item-label"><label for="seo_description" title="Mô tả">Mô tả (meta description)</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="seo_description" name="seo_description" class="ant-input" rows="3" maxlength="160">{{ $value('seo_description') }}</textarea>
                </div></div>
                {!! $error('seo_description') !!}
                <div class="ant-form-item-extra"><span data-counter-for="seo_description" data-max="160"></span></div>
              </div>
            </div>
            <div class="ant-row ant-form-item mb-0{{ $keywordError ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}">
              <div class="ant-col ant-form-item-label"><label for="seo_keywords" title="Từ khóa">Từ khóa (meta keywords)</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <select data-antd multiple data-mode="tags" id="seo_keywords" name="seo_keywords[]" class="w-full" data-placeholder="Gõ từ khóa rồi bấm Enter">
                    @foreach ($keywords as $keyword)
                      <option value="{{ $keyword }}" selected>{{ $keyword }}</option>
                    @endforeach
                  </select>
                </div></div>
                @if ($keywordError)
                  <div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">{{ $errors->first($keywordError) }}</div></div>
                @endif
              </div>
            </div>
          </div>
        </div>
      </section>

      {{-- Tab Tracking: mã theo dõi chèn vào mọi trang của website --}}
      <section @class(['flex flex-col gap-4', 'hidden' => $tab !== 'tracking']) id="settings-tracking" role="tabpanel" data-panel="tracking">
        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Mã theo dõi</div></div></div>
          <div class="ant-card-body">
            <p class="settings-card-desc">Dán nguyên đoạn mã dịch vụ cung cấp. Mã chạy trên mọi trang của website.</p>
            @foreach ([
                'code_head' => ['Trong &lt;head&gt;', 8, 'Chèn trước <code>&lt;/head&gt;</code> — vd Google Analytics, mã xác minh Search Console.'],
                'code_body' => ['Đầu &lt;body&gt;', 4, 'Chèn ngay sau <code>&lt;body&gt;</code> — vd Google Tag Manager (noscript).'],
                'code_bottom' => ['Cuối &lt;body&gt;', 6, 'Chèn trước <code>&lt;/body&gt;</code> — vd khung chat, Facebook Pixel.'],
            ] as $name => [$label, $rows, $hint])
              <div @class(['ant-row ant-form-item', 'mb-0' => $loop->last, 'ant-form-item-has-error ant-form-item-with-help' => $errors->has($name)])>
                <div class="ant-col ant-form-item-label"><label for="{{ $name }}">{!! $label !!}</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="{{ $name }}" name="{{ $name }}" class="ant-input settings-code" rows="{{ $rows }}" maxlength="20000" spellcheck="false">{{ $value($name) }}</textarea>
                  </div></div>
                  {!! $error($name) !!}
                  <div class="ant-form-item-extra">{!! $hint !!}</div>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      </section>
    </div>
  </div>
</form>
@endsection
