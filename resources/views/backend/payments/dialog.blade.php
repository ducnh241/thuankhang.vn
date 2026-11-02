{{-- Hộp thoại thêm / sửa khoản thu (Modal Ant Design). Tham số: $fixedProject (cố định dự án, ẩn ô chọn) hoặc
     $projects (danh sách để chọn) — truyền rõ khi @include, đừng dựa vào biến $project của trang (lọt từ vòng lặp). Nút [data-payment-open] mở hộp; nút sửa truyền data-payment='{"id":..,"amount":..}';
     không có id thì là giá trị điền sẵn (vd {"type":"extra","status":"unpaid"} = ghi nợ phát sinh); thêm
     data-payment-receive = ghi nhận đã thu một khoản phát sinh (đổi sang Đã thu, ngày thu hôm nay).
     Loại "Phát sinh" có thêm Trạng thái: Chưa thu → ô ngày là ngày phát sinh, ẩn phương thức.
     Server báo lỗi (túi lỗi 'payment' + session('payment_dialog') = 'new' / id) thì hộp mở lại với dữ liệu vừa nhập,
     lỗi hiện dưới từng ô. JS: resources/admin/js/payment-modal.js --}}
@php
    $bag = $errors->getBag('payment');
    $fixedProject ??= null;
    $reopen = session('payment_dialog'); // 'new' hoặc id khoản đang sửa khi validate lỗi
    $today = now()->toDateString();
    $value = fn (string $field, $default = null) => $reopen ? old($field, $default) : $default;
    $hasError = fn (string $field) => $bag->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $bag->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($bag->first($field)).'</div></div>' : '';
    $amount = $value('amount');
    $paidAt = $value('paid_at', $today);
    $type = $value('type', 'new');
    $unpaid = $type === 'extra' && $value('status') === 'unpaid';
@endphp
<div class="ant-modal-root hidden" id="payment-modal" data-store-url="{{ route('backend.payments.store') }}" data-update-url="{{ route('backend.payments.update', '__ID__') }}"
  data-default-date="{{ $today }}" @if ($reopen) data-reopen="{{ $reopen }}" @endif>
  <div class="ant-modal-mask"></div>
  <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="payment-modal-title">
    <div role="document" class="ant-modal" style="width: 520px">
      <div class="ant-modal-content">
        <button type="button" aria-label="Đóng" class="ant-modal-close" data-payment-close><span class="ant-modal-close-x">{{ ant_icon('close', 'ant-modal-close-icon') }}</span></button>
        <div class="ant-modal-header"><div class="ant-modal-title" id="payment-modal-title" data-payment-title>Thêm khoản thu</div></div>
        <div class="ant-modal-body">
          <p class="mt-0 mb-4 text-[rgba(0,0,0,0.45)] wrap-anywhere">{{ $fixedProject ? $fixedProject->name : 'Ghi nhận tiền đã thu hoặc chi phí phát sinh của một dự án.' }}</p>
          <form method="POST" action="{{ route('backend.payments.store') }}" id="payment-form" class="ant-form ant-form-vertical edit-form" novalidate autocomplete="off">
            @csrf
            <input type="hidden" name="_method" value="POST" data-method />
            @if ($fixedProject)
              <input type="hidden" name="project_id" value="{{ $fixedProject->id }}" />
            @else
              <div class="ant-row ant-form-item{{ $hasError('project_id') }}" data-required="Chọn dự án.">
                <div class="ant-col ant-form-item-label"><label for="payment_project" class="ant-form-item-required" title="Dự án">Dự án</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <select data-antd data-show-search id="payment_project" name="project_id" class="w-full" data-placeholder="Chọn hoặc gõ để tìm dự án">
                      <option value=""></option>
                      @foreach ($projects as $option)
                        <option value="{{ $option->id }}" @selected((int) $value('project_id') === $option->id)>#{{ $option->id }} {{ $option->name }}{{ $option->customer ? ' — '.$option->customer->name : '' }}</option>
                      @endforeach
                    </select>
                  </div></div>
                  {!! $error('project_id') !!}
                </div>
              </div>
            @endif

            <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
              <div class="ant-row ant-form-item{{ $hasError('amount') }}" data-required="Nhập số tiền.">
                <div class="ant-col ant-form-item-label"><label for="payment_amount" class="ant-form-item-required" title="Số tiền">Số tiền</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="text" id="payment_amount" name="amount" value="{{ is_numeric($amount) ? \App\Support\Money::format($amount) : $amount }}" class="ant-input tabular-nums" inputmode="decimal" data-money placeholder="0" />
                  </div></div>
                  {!! $error('amount') !!}
                </div>
              </div>
              <div class="ant-row ant-form-item{{ $hasError('paid_at') }}" data-required="{{ $unpaid ? 'Chọn ngày phát sinh.' : 'Chọn ngày thu.' }}" data-payment-date>
                <div class="ant-col ant-form-item-label"><label for="payment_paid_at" class="ant-form-item-required" title="{{ $unpaid ? 'Ngày phát sinh' : 'Ngày thu' }}" data-payment-date-label>{{ $unpaid ? 'Ngày phát sinh' : 'Ngày thu' }}</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <div class="ant-picker w-full" data-picker="date">
                      <div class="ant-picker-input">
                        <input id="payment_paid_at" name="paid_at" value="{{ $paidAt }}" placeholder="Chọn ngày" readonly autocomplete="off" />
                        <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
                      </div>
                    </div>
                  </div></div>
                  {!! $error('paid_at') !!}
                </div>
              </div>
            </div>
            <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
              <div class="ant-row ant-form-item{{ $hasError('type') }}">
                <div class="ant-col ant-form-item-label"><label for="payment_type" title="Loại">Loại</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <select data-antd id="payment_type" name="type" class="w-full">
                      @foreach (\App\Models\Payment::TYPES as $key => $text)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $text }}</option>
                      @endforeach
                    </select>
                  </div></div>
                  {!! $error('type') !!}
                </div>
              </div>
              <div @class(['ant-row ant-form-item'.$hasError('method'), 'hidden' => $unpaid]) data-payment-method>
                <div class="ant-col ant-form-item-label"><label for="payment_method" title="Phương thức">Phương thức</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <select data-antd data-allow-clear id="payment_method" name="method" class="w-full" data-placeholder="Chưa rõ">
                      <option value=""></option>
                      @foreach (\App\Models\Payment::METHODS as $key => $text)
                        <option value="{{ $key }}" @selected($value('method', 'bank_transfer') === $key)>{{ $text }}</option>
                      @endforeach
                    </select>
                  </div></div>
                  {!! $error('method') !!}
                </div>
              </div>
            </div>
            {{-- Chỉ loại Phát sinh: chưa thu thì tính vào công nợ, chưa tính doanh thu --}}
            <div @class(['ant-row ant-form-item'.$hasError('status'), 'hidden' => $type !== 'extra']) data-payment-status>
              <div class="ant-col ant-form-item-label"><label title="Trạng thái">Trạng thái</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <div class="ant-radio-group ant-radio-group-outline" role="radiogroup" aria-label="Trạng thái" data-value="{{ $unpaid ? 'unpaid' : 'paid' }}">
                    @foreach (\App\Models\Payment::STATUSES as $key => $text)
                      @php $checked = $key === ($unpaid ? 'unpaid' : 'paid'); @endphp
                      <label @class(['ant-radio-button-wrapper', 'ant-radio-button-wrapper-checked' => $checked])
                        ><span @class(['ant-radio-button', 'ant-radio-button-checked' => $checked])
                          ><input type="radio" class="ant-radio-button-input" name="status" value="{{ $key }}" @checked($checked) /><span class="ant-radio-button-inner"></span></span
                        ><span>{{ $text }}</span></label
                      >
                    @endforeach
                  </div>
                </div></div>
                {!! $error('status') !!}
                <div class="ant-form-item-extra">Chưa thu: tính vào công nợ của dự án, chưa tính doanh thu. Khi khách trả, bấm “Đã thu” ở khoản này.</div>
              </div>
            </div>
            <div class="ant-row ant-form-item mb-0{{ $hasError('note') }}">
              <div class="ant-col ant-form-item-label"><label for="payment_note" title="Ghi chú">Ghi chú</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="payment_note" name="note" class="ant-input" rows="2" maxlength="2000" placeholder="VD: Đặt cọc 50%, thanh toán đợt 2, làm thêm tính năng…">{{ $value('note') }}</textarea>
                </div></div>
                {!! $error('note') !!}
              </div>
            </div>
          </form>
        </div>
        <div class="ant-modal-footer">
          <button type="button" class="ant-btn" data-payment-close><span>Hủy</span></button>
          <button type="submit" form="payment-form" class="ant-btn ant-btn-primary" data-payment-submit><span data-payment-submit-text>Lưu khoản thu</span></button>
        </div>
      </div>
    </div>
  </div>
</div>
