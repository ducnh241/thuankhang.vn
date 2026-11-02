@extends('layouts.backend')

@section('title', $project->name)

{{-- Chi tiết dự án. Template ProCMS không có trang này nên ghép từ thành phần sẵn có: thanh trên cùng như
     project-edit.html, Descriptions (khung xem nhanh của projects.html), bảng ant-table-small, Timeline cho lịch sử
     thay đổi, Progress cho phần đã thu. Thêm / sửa khoản thu: hộp thoại backend/payments/dialog (payment-modal.js). --}}
@php
    use App\Models\Hosting;
    use App\Models\Payment;
    use App\Models\Project;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $user = auth()->user();
    $currency = $project->currency ?: 'VND';

    $price = (float) $project->price;
    // Tiền đã thu theo loại; phát sinh chưa thu tính vào công nợ (cùng công thức Project::debtAmount)
    $received = $project->payments->reject(fn ($p) => $p->isUnpaid());
    $paidNew = (float) $received->filter(fn ($p) => ! in_array($p->type, ['renewal', 'extra'], true))->sum('amount');
    $paidRenewal = (float) $received->where('type', 'renewal')->sum('amount');
    $paidExtra = (float) $received->where('type', 'extra')->sum('amount');
    $unpaidExtra = (float) $project->payments->filter(fn ($p) => $p->isUnpaid())->sum('amount');
    $remaining = max(0, round($price - $paidNew, 2));
    $debt = round($remaining + $unpaidExtra, 2);
    $paymentData = fn ($p) => json_encode(['id' => $p->id, 'project_id' => $p->project_id, 'amount' => $p->amount, 'paid_at' => $p->paid_at?->toDateString(), 'type' => $p->type, 'status' => $p->status, 'method' => $p->method, 'note' => $p->note]);
    $percent = $price > 0 ? min(100, (int) floor($paidNew / $price * 100)) : 0;
    $customer = $project->customer;
    $counts = $customerCounts[$project->customer_id] ?? ['direct' => 0, 'total' => 0];
    $renewal = $project->nextRenewal($today);
    $tagDot = fn ($color) => preg_match('/^#[0-9a-f]{3,8}$/i', (string) $color) ? $color : '#d9d9d9';
    $dayTag = fn (int $days) => '<span class="ant-tag m-0 '.($days <= 0 ? 'ant-tag-red' : ($days <= 30 ? 'ant-tag-orange' : 'ant-tag-blue')).'">'
        .($days < 0 ? 'Quá hạn '.abs($days).' ngày' : ($days === 0 ? 'Hôm nay' : 'Còn '.$days.' ngày')).'</span>';

    // Tên cột hiển thị trong lịch sử thay đổi
    $fieldLabels = [
        'name' => 'Tên', 'customer_id' => 'Khách hàng', 'primary_domain' => 'Domain', 'domains' => 'Domain alias', 'domain_note' => 'Ghi chú domain',
        'description' => 'Mô tả', 'status' => 'Trạng thái', 'payment_status' => 'Thanh toán', 'start_date' => 'Bắt đầu',
        'deliver_date' => 'Bàn giao', 'next_renewal_date' => 'Gia hạn kế tiếp', 'price' => 'Giá', 'currency' => 'Tiền tệ',
        'renewal_hosting_amount' => 'Gia hạn hosting', 'renewal_domain_amount' => 'Gia hạn domain', 'note' => 'Ghi chú',
        'amount' => 'Số tiền', 'paid_at' => 'Ngày thu', 'method' => 'Phương thức', 'type' => 'Loại', 'tech_stack' => 'Công nghệ',
        'is_promo_active' => 'Quảng bá', 'cat_id' => 'Danh mục', 'project_id' => 'Dự án',
    ];
    $fmt = function ($field, $value) use ($categoryNames) {
        if ($value === null || $value === '') {
            return '∅';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return match ($field) {
            'status' => Project::STATUSES[$value] ?? Payment::STATUSES[$value] ?? $value,
            'payment_status' => Project::PAYMENT_STATUSES[$value] ?? $value,
            'type' => Payment::TYPES[$value] ?? $value,
            'method' => Payment::METHODS[$value] ?? $value,
            'cat_id' => $categoryNames[$value] ?? '#'.$value,
            'price', 'amount', 'renewal_hosting_amount', 'renewal_domain_amount' => Money::format((float) $value),
            // Ngày lưu dạng "2026-10-10" hoặc "2026-10-10 00:00:00" / ISO → 10/10/2026
            default => preg_match('/^(\d{4})-(\d{2})-(\d{2})([ T]00:00:00(\.0+Z?)?)?$/', (string) $value, $d)
                ? "{$d[3]}/{$d[2]}/{$d[1]}"
                : \Illuminate\Support\Str::limit((string) $value, 80),
        };
    };
    $timelineColor = ['created' => 'green', 'updated' => 'blue', 'deleted' => 'red', 'restored' => 'gray'];
    $actionTag = ['created' => 'ant-tag-green', 'updated' => 'ant-tag-blue', 'deleted' => 'ant-tag-red', 'restored' => 'ant-tag-gold'];
    $row = fn (string $label, string $content) => '<tr class="ant-descriptions-row"><th class="ant-descriptions-item-label">'.$label.'</th><td class="ant-descriptions-item-content">'.$content.'</td></tr>';
@endphp

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.projects.index') }}" class="page-topbar-crumb" title="Về danh sách dự án">Dự án</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $project->name }}</h1>
    <span class="page-topbar-sub">#{{ $project->id }}</span>
    <span class="ant-tag {{ Project::STATUS_TAGS[$project->status] ?? '' }} max-sm:hidden">{{ $project->statusLabel() }}</span>
  </div>
  <div class="page-topbar-right">
    @can('project/delete')
      @if ($project->payments->isNotEmpty())
        <span data-tooltip="Đã có {{ $project->payments->count() }} khoản thu, không xóa được (chuyển hoặc xóa khoản thu trước)"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
      @else
        <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa dự án"
          data-delete-url="{{ route('backend.projects.destroy', $project) }}" data-delete-title="Xóa dự án “{{ $project->name }}”?">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
      @endif
    @endcan
    @can('project/edit')
      <a href="{{ route('backend.projects.edit', $project) }}" class="ant-btn ant-btn-primary" aria-label="Sửa dự án">{{ ant_icon('edit') }}<span class="max-sm:hidden">Sửa dự án</span></a>
    @endcan
  </div>
@endsection

@section('content')
<div id="project-show" class="mx-auto max-w-[1200px]">
  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin</div></div></div>
        <div class="ant-card-body">
          <div class="ant-descriptions ant-descriptions-small ant-descriptions-bordered project-descriptions">
            <div class="ant-descriptions-view">
              <table>
                <tbody>
                  {!! $row('Domain', $project->domainUrl()
                      ? '<a href="'.e($project->domainUrl()).'" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 wrap-anywhere">'.ant_icon('global').e($project->primary_domain).'</a>'
                      : ($project->primary_domain ? e($project->primary_domain) : '<span class="'.$muted.'">—</span>')) !!}
                  @if ($project->domains->isNotEmpty())
                    {{-- Domain alias: cùng website, chung source code và database --}}
                    {!! $row('Domain alias', '<ul class="project-alias-list">'.$project->domains->map(fn ($d) => '<li>'
                        .($d->url() ? '<a href="'.e($d->url()).'" target="_blank" rel="noopener noreferrer" class="wrap-anywhere">'.ant_icon('global', 'mr-1').e($d->domain).'</a>' : e($d->domain))
                        .($d->note ? ' <span class="'.$muted.'">— '.e($d->note).'</span>' : '').'</li>')->implode('').'</ul>') !!}
                  @endif
                  {!! $row('Công nghệ', $project->tech_stack ? e($project->tech_stack) : '<span class="'.$muted.'">—</span>') !!}
                  {!! $row('Danh mục', $project->category
                      ? '<a href="'.e(route('backend.projects.index', ['cat_id' => $project->cat_id])).'" title="Xem các dự án cùng danh mục">'.e(($project->category->parent ? $project->category->parent->name.' › ' : '').$project->category->name).'</a>'
                      : '<span class="'.$muted.'">Chưa phân loại</span>') !!}
                  @if ($project->tags->isNotEmpty())
                    {!! $row('Tags', '<div class="flex flex-wrap gap-1">'.$project->tags->map(fn ($tag) => '<span class="ant-tag project-tag m-0" title="'.e($tag->name).'"><span class="project-tag-dot" style="background: '.$tagDot($tag->color).'"></span><span class="truncate">'.e($tag->name).'</span></span>')->implode('').'</div>') !!}
                  @endif
                  {!! $row('Bắt đầu', $project->start_date ? '<span class="tabular-nums">'.$project->start_date->format('d/m/Y').'</span>' : '<span class="'.$muted.'">—</span>') !!}
                  {!! $row('Bàn giao', $project->deliver_date ? '<span class="tabular-nums">'.$project->deliver_date->format('d/m/Y').'</span>' : '<span class="'.$muted.'">—</span>') !!}
                  {!! $row('Gia hạn kế tiếp', $project->next_renewal_date ? '<span class="tabular-nums">'.$project->next_renewal_date->format('d/m/Y').'</span>' : '<span class="'.$muted.'">—</span>') !!}
                  @foreach (['description' => 'Mô tả', 'domain_note' => 'Ghi chú domain / đăng nhập', 'note' => 'Ghi chú nội bộ'] as $field => $label)
                    @if ($project->{$field})
                      {!! $row($label, '<div class="whitespace-pre-wrap wrap-anywhere">'.e($project->{$field}).'</div>') !!}
                    @endif
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      {{-- Thanh toán --}}
      <div class="ant-card ant-card-bordered" data-card="payments">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Lịch sử thanh toán <span class="{{ $muted }} font-normal">({{ $project->payments->count() }})</span></div>
            @can('payment/add')
              {{-- Ghi nợ phát sinh: khách đặt thêm việc ngoài hợp đồng — chưa thu thì tính công nợ, chưa tính doanh thu --}}
              <div class="ant-card-extra flex gap-2">
                <button type="button" class="ant-btn ant-btn-sm" data-payment-open data-payment="{{ json_encode(['type' => 'extra', 'status' => 'unpaid']) }}" aria-label="Ghi nợ phát sinh" data-tooltip="Ghi nợ phát sinh">{{ ant_icon('file-text') }}<span class="max-sm:hidden">Ghi nợ phát sinh</span></button>
                <button type="button" class="ant-btn ant-btn-sm" data-payment-open>{{ ant_icon('plus') }}<span>Thêm khoản thu</span></button>
              </div>
            @endcan
          </div>
        </div>
        <div>
          @if ($project->payments->isEmpty())
            <div class="ant-empty ant-empty-normal my-6"><p class="ant-empty-description">Chưa có khoản thu nào.</p></div>
          @else
            <div class="ant-table-wrapper">
              <div class="ant-table ant-table-small">
                <div class="ant-table-container">
                  <div class="ant-table-content overflow-x-auto">
                    <table style="table-layout: auto">
                      <thead class="ant-table-thead">
                        <tr>
                          <th class="ant-table-cell whitespace-nowrap">Ngày thu</th>
                          <th class="ant-table-cell whitespace-nowrap max-sm:hidden">Loại</th>
                          <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Số tiền</th>
                          <th class="ant-table-cell whitespace-nowrap max-xl:hidden">Phương thức</th>
                          <th class="ant-table-cell max-sm:hidden">Ghi chú</th>
                          @if ($user->can('payment/edit') || $user->can('payment/delete'))<th class="ant-table-cell" style="text-align: right"><span class="sr-only">Thao tác</span></th>@endif
                        </tr>
                      </thead>
                      <tbody class="ant-table-tbody">
                        @foreach ($project->payments as $payment)
                          <tr class="ant-table-row" data-payment-row="{{ $payment->id }}" @if ($payment->isUnpaid()) data-unpaid @endif>
                            <td class="ant-table-cell whitespace-nowrap tabular-nums" @if ($payment->isUnpaid()) title="Ngày phát sinh (chưa thu)" @endif>{{ $payment->paid_at?->format('d/m/Y') }}</td>
                            <td class="ant-table-cell max-sm:hidden">
                              <div class="flex flex-col items-start gap-1">
                                <span class="ant-tag m-0 {{ Payment::TYPE_TAGS[$payment->type] ?? 'ant-tag-green' }}">{{ $payment->typeLabel() }}</span>
                                @if ($payment->isUnpaid())<span class="ant-tag ant-tag-red m-0">Chưa thu</span>@endif
                              </div>
                            </td>
                            <td class="ant-table-cell whitespace-nowrap tabular-nums" style="text-align: right">
                              <span @class(['font-medium', 'text-[#fa541c]' => $payment->isUnpaid()])>{{ Money::format($payment->amount) }}</span>
                              {{-- Màn hẹp: cột phương thức (dưới 1280px) và loại (điện thoại) ẩn, ghi gọn dưới số tiền --}}
                              <div class="text-xs whitespace-normal {{ $muted }} xl:hidden"><span class="sm:hidden">{{ $payment->typeLabel() }}{{ $payment->isUnpaid() ? ' · chưa thu' : '' }}{{ $payment->methodLabel() !== '' ? ' · ' : '' }}</span>{{ $payment->methodLabel() }}</div>
                              {{-- Khách trả khoản phát sinh: ghi nhận đã thu --}}
                              @if ($payment->isUnpaid() && $user->can('payment/edit'))
                                <div class="mt-1"><button type="button" class="ant-btn ant-btn-sm" aria-label="Ghi nhận đã thu {{ Money::format($payment->amount) }}"
                                  data-payment-open data-payment-receive data-payment="{{ $paymentData($payment) }}">{{ ant_icon('check') }}<span>Đã thu</span></button></div>
                              @endif
                            </td>
                            <td class="ant-table-cell whitespace-nowrap max-xl:hidden">{!! $payment->methodLabel() !== '' ? e($payment->methodLabel()) : '<span class="'.$muted.'">—</span>' !!}</td>
                            <td class="ant-table-cell max-sm:hidden"><div class="max-w-[260px] min-w-[80px] wrap-anywhere {{ $muted }}">{{ $payment->note }}</div></td>
                            @if ($user->can('payment/edit') || $user->can('payment/delete'))
                              <td class="ant-table-cell" style="text-align: right">
                                <div class="product-row-actions">
                                  @can('payment/edit')
                                    <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa khoản thu {{ Money::format($payment->amount) }}"
                                      data-payment-open data-payment="{{ $paymentData($payment) }}">{{ ant_icon('edit') }}</button>
                                  @endcan
                                  @can('payment/delete')
                                    <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa khoản thu {{ Money::format($payment->amount) }}"
                                      data-delete-url="{{ route('backend.payments.destroy', $payment) }}" data-delete-title="Xóa khoản thu {{ Money::format($payment->amount) }} ngày {{ $payment->paid_at?->format('d/m/Y') }}?">{{ ant_icon('delete') }}</button>
                                  @endcan
                                </div>
                              </td>
                            @endif
                          </tr>
                        @endforeach
                      </tbody>
                      <tfoot class="ant-table-summary">
                        <tr>
                          <td class="ant-table-cell whitespace-nowrap font-medium">Tổng đã thu</td>
                          <td class="ant-table-cell max-sm:hidden"></td>
                          <td class="ant-table-cell whitespace-nowrap font-medium tabular-nums text-[#52c41a]" style="text-align: right">{{ Money::format($paidNew + $paidRenewal + $paidExtra) }}</td>
                          <td class="ant-table-cell max-xl:hidden"></td>
                          <td class="ant-table-cell max-sm:hidden"></td>
                          @if ($user->can('payment/edit') || $user->can('payment/delete'))<td class="ant-table-cell"></td>@endif
                        </tr>
                        @if ($unpaidExtra > 0)
                          <tr data-unpaid-total>
                            <td class="ant-table-cell whitespace-nowrap font-medium" title="Phát sinh chưa thu: tính vào công nợ, chưa tính doanh thu">Chưa thu</td>
                            <td class="ant-table-cell max-sm:hidden"></td>
                            <td class="ant-table-cell whitespace-nowrap font-medium tabular-nums text-[#fa541c]" style="text-align: right">{{ Money::format($unpaidExtra) }}</td>
                            <td class="ant-table-cell max-xl:hidden"></td>
                            <td class="ant-table-cell max-sm:hidden"></td>
                            @if ($user->can('payment/edit') || $user->can('payment/delete'))<td class="ant-table-cell"></td>@endif
                          </tr>
                        @endif
                      </tfoot>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          @endif
        </div>
      </div>

      {{-- Hosting --}}
      <div class="ant-card ant-card-bordered" data-card="hostings">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Hosting <span class="{{ $muted }} font-normal">({{ $project->hostings->count() }})</span></div></div></div>
        <div>
          @if ($project->hostings->isEmpty())
            <div class="ant-empty ant-empty-normal my-6"><p class="ant-empty-description">Chưa gắn hosting nào.</p></div>
          @else
            <div class="ant-table-wrapper">
              <div class="ant-table ant-table-small">
                <div class="ant-table-container">
                  <div class="ant-table-content overflow-x-auto">
                    <table style="table-layout: auto">
                      <thead class="ant-table-thead">
                        <tr>
                          <th class="ant-table-cell">Tên</th>
                          <th class="ant-table-cell whitespace-nowrap max-sm:hidden">Nhà cung cấp</th>
                          <th class="ant-table-cell whitespace-nowrap max-md:hidden">IP</th>
                          <th class="ant-table-cell whitespace-nowrap">Hết hạn</th>
                          <th class="ant-table-cell whitespace-nowrap max-sm:hidden">Sở hữu</th>
                        </tr>
                      </thead>
                      <tbody class="ant-table-tbody">
                        @foreach ($project->hostings as $hosting)
                          @php $days = $hosting->daysLeft(); @endphp
                          <tr class="ant-table-row">
                            <td class="ant-table-cell">
                              @can('hosting/edit')<a href="{{ route('backend.hostings.edit', $hosting) }}" class="font-medium wrap-anywhere">{{ $hosting->name }}</a>@else<span class="font-medium wrap-anywhere">{{ $hosting->name }}</span>@endcan
                              @if (! $hosting->is_active)<span class="ant-tag ml-1">Ngừng dùng</span>@endif
                              @if ($hosting->pivot->note)<div class="text-xs {{ $muted }} wrap-anywhere">{{ $hosting->pivot->note }}</div>@endif
                              <div class="text-xs {{ $muted }} sm:hidden">{{ collect([$hosting->provider, Hosting::OWNERSHIPS[$hosting->ownership] ?? null])->filter()->implode(' · ') }}</div>
                            </td>
                            <td class="ant-table-cell whitespace-nowrap max-sm:hidden">{!! $hosting->provider ? e($hosting->provider) : '<span class="'.$muted.'">—</span>' !!}</td>
                            <td class="ant-table-cell whitespace-nowrap font-mono text-xs max-md:hidden">{!! $hosting->ip ? e($hosting->ip) : '<span class="'.$muted.'">—</span>' !!}</td>
                            <td class="ant-table-cell whitespace-nowrap">
                              @if ($hosting->expire_date)
                                <div class="tabular-nums">{{ $hosting->expire_date->format('d/m/Y') }}</div>
                                @if ($hosting->is_active && $days !== null && $days <= 30){!! $dayTag($days) !!}@endif
                              @else
                                <span class="{{ $muted }}">—</span>
                              @endif
                            </td>
                            <td class="ant-table-cell whitespace-nowrap max-sm:hidden"><span class="ant-tag m-0 {{ $hosting->ownership === 'mine' ? 'ant-tag-blue' : '' }}">{{ Hosting::OWNERSHIPS[$hosting->ownership] ?? $hosting->ownership }}</span></td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          @endif
        </div>
      </div>

      {{-- Lịch sử thay đổi --}}
      <div class="ant-card ant-card-bordered" data-card="history">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">
              Lịch sử thay đổi
              <div class="text-xs font-normal {{ $muted }}">50 thay đổi gần nhất của dự án và các khoản thu.</div>
            </div>
          </div>
        </div>
        <div class="ant-card-body">
          @if ($auditLogs->isEmpty())
            <p class="m-0 {{ $muted }}">Chưa có thay đổi nào được ghi lại.</p>
          @else
            <ul class="ant-timeline project-timeline">
              @foreach ($auditLogs as $log)
                @php
                    $old = $log->changes['old'] ?? [];
                    $new = $log->changes['new'] ?? [];
                @endphp
                <li @class(['ant-timeline-item', 'ant-timeline-item-last' => $loop->last])>
                  <div class="ant-timeline-item-tail"></div>
                  <div class="ant-timeline-item-head ant-timeline-item-head-{{ $timelineColor[$log->action] ?? 'gray' }}"></div>
                  <div class="ant-timeline-item-content">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <span class="ant-tag m-0 {{ $actionTag[$log->action] ?? '' }}">{{ $log->actionLabel() }}</span>
                      <span class="font-medium text-[rgba(0,0,0,0.85)]">{{ $log->subjectLabel() }}</span>
                      <span class="text-xs {{ $muted }}">{{ $log->actorName() }} · <span title="{{ $log->created_at?->format('d/m/Y H:i:s') }}">{{ $log->created_at?->diffForHumans() }}</span></span>
                    </div>
                    @if ($log->action === 'updated')
                      <div class="mt-1 flex flex-col gap-0.5 text-xs">
                        @foreach ($new as $field => $value)
                          <div class="wrap-anywhere"><span class="{{ $muted }}">{{ $fieldLabels[$field] ?? $field }}:</span> <span class="{{ $muted }} line-through">{{ $fmt($field, $old[$field] ?? null) }}</span> → <span class="font-medium text-[rgba(0,0,0,0.85)]">{{ $fmt($field, $value) }}</span></div>
                        @endforeach
                      </div>
                    @elseif ($log->auditable_type === Payment::class && ($new ?: $old))
                      @php $values = $new ?: $old; @endphp
                      <div class="mt-1 text-xs {{ $muted }}">{{ Money::format((float) ($values['amount'] ?? 0)) }} · {{ $fmt('type', $values['type'] ?? 'new') }} · {{ $fmt('paid_at', $values['paid_at'] ?? null) }}{{ ($values['status'] ?? 'paid') === 'unpaid' ? ' · chưa thu' : '' }}</div>
                    @endif
                  </div>
                </li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    </div>

    {{-- Cột phụ --}}
    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered" data-card="customer">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Khách hàng</div></div></div>
        <div class="ant-card-body">
          @if ($customer)
            <div class="font-medium text-[rgba(0,0,0,0.85)] wrap-anywhere">
              @if (! $customer->trashed() && $user->can('customer/edit'))
                <a href="{{ route('backend.customers.edit', $customer) }}">{{ $customer->name }}</a>
              @else
                {{ $customer->name }}
              @endif
            </div>
            @if ($customer->company)<div class="text-xs {{ $muted }}">{{ $customer->company }}</div>@endif
            @if ($customer->trashed())<div class="text-xs text-[#f5222d]">Khách hàng đã bị xóa</div>@endif
            @if ($customer->phone || $customer->email || $customer->address)
              <div class="mt-3 flex flex-col gap-1">
                @if ($customer->phone)<a href="tel:{{ $customer->phone }}" class="inline-flex items-center gap-2 tabular-nums">{{ ant_icon('phone') }}{{ $customer->phone }}</a>@endif
                @if ($customer->email)<a href="mailto:{{ $customer->email }}" class="inline-flex items-center gap-2 wrap-anywhere">{{ ant_icon('mail') }}{{ $customer->email }}</a>@endif
                @if ($customer->address)<span class="inline-flex items-start gap-2 {{ $muted }}">{{ ant_icon('environment', 'mt-1') }}<span>{{ $customer->address }}</span></span>@endif
              </div>
            @endif
            <div class="mt-3 border-t border-[#f0f0f0] pt-3 text-xs {{ $muted }}">
              <a href="{{ route('backend.projects.index', ['customer_id' => $customer->id]) }}">{{ $counts['direct'] }} dự án</a>
              @if ($counts['total'] > $counts['direct']) · +{{ $counts['total'] - $counts['direct'] }} qua giới thiệu @endif
              @if ($customer->referrer)
                <div class="mt-1">Được giới thiệu bởi <a href="{{ route('backend.projects.index', ['customer_id' => $customer->referrer->id]) }}">{{ $customer->referrer->name }}</a></div>
              @endif
            </div>
          @else
            <p class="m-0 {{ $muted }}">Không có khách hàng.</p>
          @endif
        </div>
      </div>

      <div class="ant-card ant-card-bordered" data-card="finance">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tài chính</div></div></div>
        <div class="ant-card-body">
          <div class="ant-statistic">
            <div class="ant-statistic-title">Giá hợp đồng</div>
            <div class="ant-statistic-content wrap-anywhere"><span class="ant-statistic-content-value">{{ Money::format($price) }}</span> <span class="ant-statistic-content-suffix text-sm">{{ $currency }}</span></div>
          </div>
          @if ($price > 0)
            <div @class(['ant-progress ant-progress-line ant-progress-show-info ant-progress-small mt-2', 'ant-progress-status-success' => $percent >= 100, 'ant-progress-status-normal' => $percent < 100]) title="Đã thu {{ $percent }}% giá hợp đồng">
              {{-- Không để khoảng trắng giữa thanh và chữ: thanh rộng 100%, khoảng trắng làm chữ rơi xuống dòng dưới --}}
              <div class="ant-progress-outer"><div class="ant-progress-inner"><div class="ant-progress-bg" style="width: {{ $percent }}%; height: 6px"></div></div></div><span class="ant-progress-text">{!! $percent >= 100 ? ant_icon('check-circle') : $percent.'%' !!}</span>
            </div>
          @endif
          <div class="mt-3 flex flex-col gap-2">
            @php $hasExtra = $unpaidExtra > 0 || $paidExtra > 0; @endphp
            <div class="flex justify-between gap-2"><span class="{{ $muted }}">Đã thu (mới)</span><span class="tabular-nums text-[#52c41a]">{{ Money::format($paidNew) }}</span></div>
            <div class="flex justify-between gap-2" data-finance="remaining"><span class="{{ $muted }}">{{ $hasExtra ? 'Còn nợ hợp đồng' : 'Còn nợ' }}</span><span @class(['tabular-nums', 'text-[#f5222d]' => $remaining > 0, $muted => $remaining <= 0])>{{ Money::format($remaining) }}</span></div>
            {{-- Phát sinh ngoài hợp đồng: chưa thu cộng vào công nợ, đã thu tính doanh thu --}}
            @if ($hasExtra)
              <div class="flex justify-between gap-2 border-t border-[#f0f0f0] pt-2" data-finance="unpaid-extra"><span class="{{ $muted }}">Phát sinh chưa thu</span><span @class(['tabular-nums', 'text-[#fa541c]' => $unpaidExtra > 0, $muted => $unpaidExtra <= 0])>{{ Money::format($unpaidExtra) }}</span></div>
              <div class="flex justify-between gap-2"><span class="{{ $muted }}">Phát sinh đã thu</span><span class="tabular-nums">{{ Money::format($paidExtra) }}</span></div>
              <div class="flex justify-between gap-2 font-medium" data-finance="debt"><span>Tổng công nợ</span><span @class(['tabular-nums', 'text-[#f5222d]' => $debt > 0, $muted => $debt <= 0])>{{ Money::format($debt) }}</span></div>
            @endif
            <div class="flex justify-between gap-2 border-t border-[#f0f0f0] pt-2"><span class="{{ $muted }}">Đã thu gia hạn</span><span class="tabular-nums">{{ Money::format($paidRenewal) }}</span></div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered" data-card="renewal">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Gia hạn hằng năm</div></div></div>
        <div class="ant-card-body">
          <div class="flex flex-col gap-2">
            <div class="flex justify-between gap-2"><span class="{{ $muted }}">Hosting</span><span class="tabular-nums">{{ Money::format($project->renewal_hosting_amount) }}</span></div>
            <div class="flex justify-between gap-2"><span class="{{ $muted }}">Domain</span><span class="tabular-nums">{{ Money::format($project->renewal_domain_amount) }}</span></div>
            <div class="flex justify-between gap-2 border-t border-[#f0f0f0] pt-2 font-medium text-[rgba(0,0,0,0.85)]"><span>Tổng / năm</span><span class="tabular-nums">{{ Money::format($project->renewalTotal()) }}</span></div>
          </div>
          @if ($renewal)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded bg-[#fafafa] px-3 py-2">
              <span class="{{ $muted }}">Kỳ tới</span>
              <span class="flex items-center gap-2">
                <span class="tabular-nums">{{ $renewal['date']->format('d/m/Y') }}</span>
                @if (in_array($renewal['state'], ['overdue', 'upcoming'], true))
                  {!! $dayTag($renewal['days']) !!}
                @elseif ($renewal['state'] === 'self')
                  <span class="ant-tag m-0">Khách tự gia hạn</span>
                @elseif ($renewal['state'] === 'stopped')
                  <span class="ant-tag m-0">Không gia hạn</span>
                @endif
              </span>
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>

@if ($user->can('payment/add') || $user->can('payment/edit'))
  @include('backend.payments.dialog', ['fixedProject' => $project])
@endif
@endsection
