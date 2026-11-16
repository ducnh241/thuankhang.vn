@extends('layouts.backend')

{{-- Cài đặt → tab "Thông báo": báo khi có đơn mới / khách báo đã chuyển khoản / hồ sơ KYC mới, qua Telegram và email. Một form
     (#notification-form, edit-form.js lo dải "Chưa lưu thay đổi"); mỗi kênh có nút gửi thử dùng cấu hình ĐÃ LƯU (form
     riêng #test-telegram-form / #test-mail-form, nút nằm trong thẻ của kênh nhờ thuộc tính form=""). Bí mật (bot token,
     mật khẩu SMTP) không hiện lại: ô để trống = giữ nguyên. Chỉ Root admin sửa được; người khác chỉ xem (inert). --}}
@php
    use App\Support\NotificationSettings;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $old = session()->hasOldInput();
    $text = fn (string $key) => $old ? (is_scalar(old($key)) ? (string) old($key) : '') : (is_array($config[$key]) ? implode(', ', $config[$key]) : (string) $config[$key]);
    $on = fn (string $key) => $old ? (bool) old($key) : (bool) $config[$key];
    $eventOn = fn (string $event) => $old ? (bool) data_get(old('events'), $event) : $config['events'][$event];
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $telegramSet = NotificationSettings::telegramConfigured($config);
    $mailSet = NotificationSettings::mailConfigured($config);
    $state = fn (bool $enabled, bool $set) => $enabled && $set
        ? '<span class="ant-tag ant-tag-green m-0">Đang bật</span>'
        : ($set ? '<span class="ant-tag m-0">Đang tắt</span>' : '<span class="ant-tag ant-tag-orange m-0">Chưa cấu hình</span>');
    $encryption = $text('mail_encryption') ?: 'tls';
    $switch = fn (string $name, bool $checked, string $title, string $hint) => new \Illuminate\Support\HtmlString(
        '<label class="mb-5 flex items-start gap-3" for="sw_'.$name.'">'
        .'<button type="button" role="switch" id="sw_'.$name.'" aria-checked="'.($checked ? 'true' : 'false').'" class="ant-switch mt-0.5 flex-none'.($checked ? ' ant-switch-checked' : '').'"><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>'
        .'<input type="hidden" name="'.$name.'" value="0" /><input type="checkbox" name="'.$name.'" value="1" class="switch-input" hidden'.($checked ? ' checked' : '').' />'
        .'<span><span class="block text-[rgba(0,0,0,0.85)]">'.e($title).'</span><span class="block '.$muted.'">'.e($hint).'</span></span></label>');
@endphp

@section('title', 'Thông báo - Cài đặt')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <span class="page-topbar-crumb">Cài đặt</span>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">Thông báo</h1>
  </div>
  @if ($canEdit)
    {{-- Hiện khi form có thay đổi chưa lưu --}}
    <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
      <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
      <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
      <button type="submit" form="notification-form" class="save-bar-save">Lưu</button>
    </div>
  @endif
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1040px] grid-cols-1 items-start gap-4 lg:grid-cols-[220px_minmax(0,1fr)]">
  @include('backend.settings._nav', ['active' => 'notification', 'links' => true])

  <div class="flex min-w-0 flex-col gap-4">
    @unless ($canEdit)
      <div class="ant-alert ant-alert-info" role="alert">
        <span class="ant-alert-icon">{{ ant_icon('exclamation-circle') }}</span>
        <div class="ant-alert-content"><div class="ant-alert-message">Chỉ Root admin sửa được cấu hình thông báo. Bạn chỉ có quyền xem.</div></div>
      </div>
    @endunless

    <form method="POST" id="notification-form" action="{{ route('backend.settings.notification.update') }}" class="ant-form ant-form-vertical edit-form flex min-w-0 flex-col gap-4"
      novalidate autocomplete="off" @if ($canEdit) data-edit-form @else data-readonly inert @endif @if ($errors->any()) data-dirty @endif>
      @csrf
      @method('PUT')

      {{-- Khi nào thì báo (chung cho cả hai kênh) --}}
      <div class="ant-card ant-card-bordered" data-card="events">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Gửi thông báo khi</div></div></div>
        <div class="ant-card-body">
          <div class="flex flex-col gap-3">
            @foreach (NotificationSettings::EVENTS as $event => [$label, $hint])
              @php $checked = $eventOn($event); @endphp
              <div>
                <input type="hidden" name="events[{{ $event }}]" value="0" />
                <label @class(['ant-checkbox-wrapper', 'ant-checkbox-wrapper-checked' => $checked])
                  ><span @class(['ant-checkbox', 'ant-checkbox-checked' => $checked])><input type="checkbox" class="ant-checkbox-input" name="events[{{ $event }}]" value="1" @checked($checked) /><span class="ant-checkbox-inner"></span></span
                  ><span>{{ $label }}</span></label
                >
                <div class="ml-6 text-xs {{ $muted }}">{{ $hint }}</div>
              </div>
            @endforeach
          </div>
          <div class="mt-3 text-xs {{ $muted }}">Áp dụng cho các kênh đang bật bên dưới. Tin đơn hàng gồm mã đơn, tên người nhận, số tiền, hình thức nhận hàng; tin KYC chỉ gồm tên và mã thành viên (không có số CCCD, số tài khoản). Tin nào cũng kèm link mở trang tương ứng trong quản trị.</div>
        </div>
      </div>

      {{-- Telegram --}}
      <div class="ant-card ant-card-bordered" data-card="telegram">
        <div class="ant-card-head"><div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">Telegram</div>
          <div class="ant-card-extra" data-state="telegram">{!! $state($config['telegram_enabled'], $telegramSet) !!}</div>
        </div></div>
        <div class="ant-card-body">
          {{ $switch('telegram_enabled', $on('telegram_enabled'), 'Gửi thông báo qua Telegram', 'Tin gửi vào nhóm / chat bên dưới.') }}
          <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('telegram_token') }}">
              <div class="ant-col ant-form-item-label"><label for="telegram_token" title="Bot token">Bot token</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  {{-- Không điền lại token đã lưu vào ô (bí mật): để trống = giữ nguyên --}}
                  <input type="password" id="telegram_token" name="telegram_token" value="" class="ant-input" autocomplete="new-password" spellcheck="false"
                    placeholder="{{ $masked['telegram_token'] ? 'Đã lưu: '.$masked['telegram_token'].' — để trống nếu không đổi' : 'VD: 1234567890:AAH…' }}" />
                </div></div>
                {{ ant_form_error($errors->first('telegram_token')) }}
                <div class="ant-form-item-extra">Tạo bot bằng <strong>@BotFather</strong> trên Telegram (lệnh /newbot) rồi chép token vào đây. Token lưu mã hóa, không hiện lại.</div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('telegram_chat_id') }}">
              <div class="ant-col ant-form-item-label"><label for="telegram_chat_id" title="Chat ID nhận tin">Chat ID nhận tin</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="telegram_chat_id" name="telegram_chat_id" value="{{ $text('telegram_chat_id') }}" class="ant-input tabular-nums" maxlength="40" placeholder="VD: -1001234567890" />
                </div></div>
                {{ ant_form_error($errors->first('telegram_chat_id')) }}
                <div class="ant-form-item-extra">Thêm bot vào nhóm kế toán rồi lấy ID của nhóm (số âm). Gửi cho một người thì dùng ID của người đó, sau khi họ bấm Start với bot.</div>
              </div>
            </div>
          </div>
          @if ($canEdit)
            <div class="flex flex-wrap items-center gap-3 border-t border-[#f0f0f0] pt-4">
              <button type="submit" form="test-telegram-form" class="ant-btn" @disabled(! $telegramSet) data-hide-when-dirty>{{ ant_icon('thunderbolt') }}<span>Gửi tin thử</span></button>
              <span class="text-xs {{ $muted }}">{{ $telegramSet ? 'Gửi một tin tới chat đã lưu. Vừa sửa cấu hình thì bấm Lưu trước rồi mới thử.' : 'Lưu bot token và chat ID trước, rồi gửi tin thử.' }}</span>
            </div>
          @endif
        </div>
      </div>

      {{-- Email --}}
      <div class="ant-card ant-card-bordered" data-card="mail">
        <div class="ant-card-head"><div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">Email</div>
          <div class="ant-card-extra" data-state="mail">{!! $state($config['mail_enabled'], $mailSet) !!}</div>
        </div></div>
        <div class="ant-card-body">
          {{ $switch('mail_enabled', $on('mail_enabled'), 'Gửi thông báo qua email', 'Thư gửi tới các địa chỉ nhận bên dưới.') }}
          <div class="ant-row ant-form-item{{ $hasError('mail_recipients') }}">
            <div class="ant-col ant-form-item-label"><label for="mail_recipients" title="Email nhận thông báo">Email nhận thông báo</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="mail_recipients" name="mail_recipients" value="{{ $text('mail_recipients') }}" class="ant-input" maxlength="500" placeholder="VD: ketoan@congty.vn, ketoan2@congty.vn" />
              </div></div>
              {{ ant_form_error($errors->first('mail_recipients')) }}
              <div class="ant-form-item-extra">Tối đa {{ NotificationSettings::MAX_RECIPIENTS }} địa chỉ, cách nhau bằng dấu phẩy.</div>
            </div>
          </div>

          <div class="mb-1 font-medium text-[rgba(0,0,0,0.85)]">Máy chủ gửi thư (SMTP)</div>
          <div class="mb-3 text-xs {{ $muted }}">
            {{ $serverCanSend
                ? 'Để trống thì dùng máy chủ thư đã cấu hình sẵn trên máy chủ website.'
                : 'Máy chủ website chưa cấu hình gửi thư, nên cần nhập SMTP ở đây (VD Gmail: smtp.gmail.com, cổng 587, mật khẩu ứng dụng).' }}
          </div>
          <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('mail_host') }}">
              <div class="ant-col ant-form-item-label"><label for="mail_host" title="Máy chủ SMTP">Máy chủ SMTP</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="mail_host" name="mail_host" value="{{ $text('mail_host') }}" class="ant-input" maxlength="150" placeholder="VD: smtp.gmail.com" spellcheck="false" />
                </div></div>
                {{ ant_form_error($errors->first('mail_host')) }}
              </div>
            </div>
            <div class="grid grid-cols-2 gap-x-4">
              <div class="ant-row ant-form-item{{ $hasError('mail_encryption') }}">
                <div class="ant-col ant-form-item-label"><label for="mail_encryption" title="Mã hóa">Mã hóa</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <select id="mail_encryption" name="mail_encryption" class="ant-input">
                      @foreach (NotificationSettings::ENCRYPTIONS as $value => [$label])
                        <option value="{{ $value }}" @selected($encryption === $value)>{{ $label }}</option>
                      @endforeach
                    </select>
                  </div></div>
                  {{ ant_form_error($errors->first('mail_encryption')) }}
                </div>
              </div>
              <div class="ant-row ant-form-item{{ $hasError('mail_port') }}">
                <div class="ant-col ant-form-item-label"><label for="mail_port" title="Cổng">Cổng</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="text" id="mail_port" name="mail_port" value="{{ $text('mail_port') }}" class="ant-input tabular-nums" inputmode="numeric" maxlength="5" placeholder="587" />
                  </div></div>
                  {{ ant_form_error($errors->first('mail_port')) }}
                </div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('mail_username') }}">
              <div class="ant-col ant-form-item-label"><label for="mail_username" title="Tên đăng nhập SMTP">Tên đăng nhập SMTP</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="mail_username" name="mail_username" value="{{ $text('mail_username') }}" class="ant-input" maxlength="150" autocomplete="off" spellcheck="false" placeholder="Thường là địa chỉ email" />
                </div></div>
                {{ ant_form_error($errors->first('mail_username')) }}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('mail_password') }}">
              <div class="ant-col ant-form-item-label"><label for="mail_password" title="Mật khẩu SMTP">Mật khẩu SMTP</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="password" id="mail_password" name="mail_password" value="" class="ant-input" maxlength="200" autocomplete="new-password"
                    placeholder="{{ $masked['mail_password'] ? 'Đã lưu — để trống nếu không đổi' : 'Mật khẩu / mật khẩu ứng dụng' }}" />
                </div></div>
                {{ ant_form_error($errors->first('mail_password')) }}
                <div class="ant-form-item-extra">Lưu mã hóa, không hiện lại.</div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('mail_from_address') }}">
              <div class="ant-col ant-form-item-label"><label for="mail_from_address" title="Địa chỉ gửi">Địa chỉ gửi</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="mail_from_address" name="mail_from_address" value="{{ $text('mail_from_address') }}" class="ant-input" maxlength="150" spellcheck="false" placeholder="VD: thongbao@congty.vn" />
                </div></div>
                {{ ant_form_error($errors->first('mail_from_address')) }}
                <div class="ant-form-item-extra">Email của tài khoản SMTP (bắt buộc khi nhập máy chủ SMTP).</div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('mail_from_name') }}">
              <div class="ant-col ant-form-item-label"><label for="mail_from_name" title="Tên người gửi">Tên người gửi</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="mail_from_name" name="mail_from_name" value="{{ $text('mail_from_name') }}" class="ant-input" maxlength="100" placeholder="Để trống = tên website" />
                </div></div>
                {{ ant_form_error($errors->first('mail_from_name')) }}
              </div>
            </div>
          </div>
          @if ($canEdit)
            <div class="flex flex-wrap items-center gap-3 border-t border-[#f0f0f0] pt-4">
              <button type="submit" form="test-mail-form" class="ant-btn" @disabled(! $mailSet) data-hide-when-dirty>{{ ant_icon('mail') }}<span>Gửi email thử</span></button>
              <span class="text-xs {{ $muted }}">{{ $mailSet ? 'Gửi một email tới các địa chỉ nhận đã lưu. Vừa sửa cấu hình thì bấm Lưu trước rồi mới thử.' : 'Lưu địa chỉ nhận và máy chủ SMTP trước, rồi gửi email thử.' }}</span>
            </div>
          @endif
        </div>
      </div>
    </form>

    @if ($canEdit)
      <form method="POST" id="test-telegram-form" action="{{ route('backend.settings.notification.test', 'telegram') }}" hidden>@csrf</form>
      <form method="POST" id="test-mail-form" action="{{ route('backend.settings.notification.test', 'mail') }}" hidden>@csrf</form>
    @endif
  </div>
</div>
@endsection
