{{-- Thanh tab của trang Cài đặt, dùng chung cho form 4 tab (settings/edit) và tab "Thông báo" (settings/notification — trang
     riêng vì có bí mật: bot token, mật khẩu SMTP). Biến: $active (khóa tab đang mở), $links (true = mọi tab là link sang
     trang Cài đặt — dùng ở trang Thông báo; false = 4 tab đầu là nút chuyển bằng JS settings.js). --}}
@php use App\Http\Controllers\Backend\SettingController; @endphp
<nav class="ant-card ant-card-bordered settings-nav" role="tablist" aria-label="Các mục cài đặt">
  @foreach (SettingController::TABS as $key => [$label, $tabIcon])
    @if ($links ?? false)
      <a href="{{ route('backend.settings.edit', ['tab' => $key]) }}" class="settings-tab" role="tab" aria-selected="false">{{ ant_icon($tabIcon) }}<span>{{ $label }}</span></a>
    @else
      <button type="button" @class(['settings-tab', 'is-active' => $key === $active]) role="tab" data-tab="{{ $key }}" aria-selected="{{ $key === $active ? 'true' : 'false' }}" aria-controls="settings-{{ $key }}">{{ ant_icon($tabIcon) }}<span>{{ $label }}</span></button>
    @endif
  @endforeach
  <a href="{{ route('backend.settings.notification.edit') }}" @class(['settings-tab', 'is-active' => $active === 'notification']) role="tab" aria-selected="{{ $active === 'notification' ? 'true' : 'false' }}" data-settings-link>{{ ant_icon('mail') }}<span>Thông báo</span></a>
</nav>
