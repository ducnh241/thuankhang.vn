{{-- Hộp thoại của trang Nhắc gia hạn (Modal Ant Design đặt sẵn trong trang): soạn thông báo gửi khách; ghi nhận đã gia hạn
     (túi lỗi 'renew'); khách hủy gia hạn (túi lỗi 'cancel'). Server báo lỗi thì hộp mở lại đúng dự án (data-reopen +
     old('project_id')), lỗi hiện dưới từng ô. JS: resources/admin/js/renewal.js --}}
@php
    use App\Models\Payment;
    use App\Support\Money;

    $canRenew = auth()->user()->can('renewal/renew');
    $renewErrors = $errors->getBag('renew');
    $cancelErrors = $errors->getBag('cancel');
    $field = fn ($bag, string $name) => $bag->has($name) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $explain = fn ($bag, string $name) => $bag->has($name)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($bag->first($name)).'</div></div>' : '';
    $old = fn (string $name, $default = '') => is_string(old($name)) ? old($name) : $default;
    $oldMoney = fn (string $name) => is_numeric(old($name)) ? Money::format(old($name)) : $old($name);
    $today = now()->toDateString();
    $method = $old('method', $renewErrors->any() ? '' : 'bank_transfer');
@endphp

{{-- Thông báo gửi khách: xem, sửa, sao chép --}}
<div class="ant-modal-root hidden" id="message-modal">
  <div class="ant-modal-mask"></div>
  <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="message-modal-title">
    <div role="document" class="ant-modal" style="width: 560px">
      <div class="ant-modal-content">
        <button type="button" aria-label="Đóng" class="ant-modal-close" data-modal-dismiss><span class="ant-modal-close-x">{{ ant_icon('close', 'ant-modal-close-icon') }}</span></button>
        <div class="ant-modal-header"><div class="ant-modal-title" id="message-modal-title" data-message-title>Thông báo gia hạn</div></div>
        <div class="ant-modal-body">
          <p class="mt-0 mb-3 text-[rgba(0,0,0,0.45)]">Sửa nội dung nếu cần rồi sao chép gửi cho khách.</p>
          <textarea class="ant-input" rows="12" data-message-text aria-label="Nội dung thông báo"></textarea>
        </div>
        <div class="ant-modal-footer flex items-center justify-end gap-2">
          <span class="mr-auto hidden text-[#52c41a]" data-message-copied>{{ ant_icon('check-circle') }} Đã sao chép</span>
          <button type="button" class="ant-btn" data-modal-dismiss><span>Đóng</span></button>
          <button type="button" class="ant-btn ant-btn-primary" data-message-copy>{{ ant_icon('copy') }}<span>Sao chép</span></button>
        </div>
      </div>
    </div>
  </div>
</div>

@if ($canRenew)
  {{-- Ghi nhận khách đã gia hạn: ghi khoản thu "gia hạn" + lùi ngày gia hạn kế tiếp 1 năm --}}
  <div class="ant-modal-root hidden" id="renew-modal" data-url="{{ route('backend.renewals.renew', '__ID__') }}" data-default-date="{{ $today }}"
    @if ($renewErrors->any() && is_numeric(old('project_id'))) data-reopen="{{ old('project_id') }}" @endif>
    <div class="ant-modal-mask"></div>
    <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="renew-modal-title">
      <div role="document" class="ant-modal" style="width: 520px">
        <div class="ant-modal-content">
          <button type="button" aria-label="Đóng" class="ant-modal-close" data-modal-dismiss><span class="ant-modal-close-x">{{ ant_icon('close', 'ant-modal-close-icon') }}</span></button>
          <div class="ant-modal-header"><div class="ant-modal-title" id="renew-modal-title">Ghi nhận đã gia hạn</div></div>
          <div class="ant-modal-body">
            <p class="mt-0 mb-4 text-[rgba(0,0,0,0.45)] wrap-anywhere" data-renew-name></p>
            @if ($renewErrors->has('due'))
              <div class="ant-alert ant-alert-error mb-4" role="alert"><span class="ant-alert-message">{{ $renewErrors->first('due') }}</span></div>
            @endif
            <form method="POST" action="" id="renew-form" class="ant-form ant-form-vertical edit-form" novalidate autocomplete="off">
              @csrf
              <input type="hidden" name="project_id" value="{{ $old('project_id') }}" />
              <input type="hidden" name="due" value="{{ $old('due') }}" />
              <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                @foreach (['hosting_amount' => 'Phí hosting', 'domain_amount' => 'Phí domain'] as $name => $text)
                  <div class="ant-row ant-form-item{{ $field($renewErrors, $name) }}" data-required="Nhập {{ mb_strtolower($text) }} (0 nếu không thu).">
                    <div class="ant-col ant-form-item-label"><label for="renew_{{ $name }}" class="ant-form-item-required" title="{{ $text }}">{{ $text }}</label></div>
                    <div class="ant-col ant-form-item-control">
                      <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                        <input type="text" id="renew_{{ $name }}" name="{{ $name }}" value="{{ $oldMoney($name) }}" class="ant-input tabular-nums" inputmode="decimal" data-money placeholder="0" />
                      </div></div>
                      {!! $explain($renewErrors, $name) !!}
                    </div>
                  </div>
                @endforeach
              </div>
              <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                <div class="ant-row ant-form-item{{ $field($renewErrors, 'paid_at') }}" data-required="Chọn ngày thu.">
                  <div class="ant-col ant-form-item-label"><label for="renew_paid_at" class="ant-form-item-required" title="Ngày thu">Ngày thu</label></div>
                  <div class="ant-col ant-form-item-control">
                    <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                      <div class="ant-picker w-full" data-picker="date">
                        <div class="ant-picker-input">
                          <input id="renew_paid_at" name="paid_at" value="{{ $renewErrors->any() ? $old('paid_at') : $today }}" placeholder="Chọn ngày" readonly autocomplete="off" />
                          <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
                        </div>
                      </div>
                    </div></div>
                    {!! $explain($renewErrors, 'paid_at') !!}
                  </div>
                </div>
                <div class="ant-row ant-form-item{{ $field($renewErrors, 'method') }}">
                  <div class="ant-col ant-form-item-label"><label for="renew_method" title="Phương thức">Phương thức</label></div>
                  <div class="ant-col ant-form-item-control">
                    <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                      <select data-antd data-allow-clear id="renew_method" name="method" class="w-full" data-placeholder="Chưa rõ">
                        <option value=""></option>
                        @foreach (Payment::METHODS as $key => $text)
                          <option value="{{ $key }}" @selected($method === $key)>{{ $text }}</option>
                        @endforeach
                      </select>
                    </div></div>
                    {!! $explain($renewErrors, 'method') !!}
                  </div>
                </div>
              </div>
              <div class="ant-row ant-form-item{{ $field($renewErrors, 'note') }}">
                <div class="ant-col ant-form-item-label"><label for="renew_note" title="Ghi chú">Ghi chú</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="renew_note" name="note" class="ant-input" rows="2" maxlength="1000" placeholder="Bỏ trống: “Gia hạn năm …” theo kỳ gia hạn">{{ $old('note') }}</textarea>
                  </div></div>
                  {!! $explain($renewErrors, 'note') !!}
                </div>
              </div>
              <div class="renewal-summary">
                <div>Tổng thu <strong class="tabular-nums" data-renew-total>0</strong></div>
                <div class="text-xs text-[rgba(0,0,0,0.45)]">Kỳ gia hạn <span class="tabular-nums" data-renew-due></span> · ngày gia hạn kế tiếp <span class="tabular-nums" data-renew-current></span> → <strong class="tabular-nums text-[rgba(0,0,0,0.85)]" data-renew-next></strong></div>
              </div>
            </form>
          </div>
          <div class="ant-modal-footer">
            <button type="button" class="ant-btn" data-modal-dismiss><span>Hủy</span></button>
            <button type="submit" form="renew-form" class="ant-btn ant-btn-primary" data-modal-submit>{{ ant_icon('check') }}<span>Xác nhận đã gia hạn</span></button>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Khách hủy gia hạn: dự án chuyển "Đã hủy gia hạn", lý do ghi vào ghi chú dự án --}}
  <div class="ant-modal-root hidden" id="cancel-modal" data-url="{{ route('backend.renewals.cancel', '__ID__') }}"
    @if ($cancelErrors->any() && is_numeric(old('project_id'))) data-reopen="{{ old('project_id') }}" @endif>
    <div class="ant-modal-mask"></div>
    <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="cancel-modal-title">
      <div role="document" class="ant-modal" style="width: 480px">
        <div class="ant-modal-content">
          <button type="button" aria-label="Đóng" class="ant-modal-close" data-modal-dismiss><span class="ant-modal-close-x">{{ ant_icon('close', 'ant-modal-close-icon') }}</span></button>
          <div class="ant-modal-header"><div class="ant-modal-title" id="cancel-modal-title">Khách hủy gia hạn</div></div>
          <div class="ant-modal-body">
            <p class="mt-0 mb-1 font-medium wrap-anywhere" data-cancel-name></p>
            <p class="mt-0 mb-4 text-[rgba(0,0,0,0.45)]">Dự án sẽ chuyển sang “Đã hủy gia hạn” và ghi lý do vào ghi chú dự án; vẫn hiện ở nhóm “Đã hủy gia hạn” để soát lại.</p>
            <form method="POST" action="" id="cancel-form" class="ant-form ant-form-vertical edit-form" novalidate autocomplete="off">
              @csrf
              <input type="hidden" name="project_id" value="{{ $old('project_id') }}" />
              <div class="ant-row ant-form-item mb-0{{ $field($cancelErrors, 'reason') }}" data-required="Nhập lý do khách hủy gia hạn.">
                <div class="ant-col ant-form-item-label"><label for="cancel_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="cancel_reason" name="reason" class="ant-input" rows="3" maxlength="1000" placeholder="VD: Khách chuyển sang bên khác, không dùng website nữa…">{{ $old('reason') }}</textarea>
                  </div></div>
                  {!! $explain($cancelErrors, 'reason') !!}
                </div>
              </div>
            </form>
          </div>
          <div class="ant-modal-footer">
            <button type="button" class="ant-btn" data-modal-dismiss><span>Đóng</span></button>
            <button type="submit" form="cancel-form" class="ant-btn ant-btn-primary ant-btn-dangerous" data-modal-submit><span>Xác nhận hủy gia hạn</span></button>
          </div>
        </div>
      </div>
    </div>
  </div>
@endif
