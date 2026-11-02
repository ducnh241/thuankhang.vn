@extends('layouts.backend')

@section('title', 'Dự án')

{{-- Theo template ProCMS projects.html: dải chỉ số, tab trạng thái, ô tìm + nút lọc dạng "pill", bảng, phân trang.
     Lọc / tìm / phân trang chạy phía server (GET, link thật); resources/admin/js/list.js mở danh sách chọn của nút
     lọc (nội dung render sẵn trong <template>), bấm cả dòng / tên để mở dự án (sửa, xóa ở trang dự án). --}}
@php
    use App\Models\Project;
    use App\Support\Money;
    use App\Support\Renewal;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $canAdd = auth()->user()->can('project/add');
    $sorts = ['id:desc' => 'Mới nhất', 'id:asc' => 'Cũ nhất', 'name:asc' => 'Tên A–Z', 'price:desc' => 'Giá cao nhất'];

    // Link danh sách giữ các bộ lọc đang chọn, đổi vài tham số (null = bỏ), luôn về trang 1
    $current = array_filter([
        'q' => $filter['q'], 'status' => $filter['status'], 'payment_status' => $filter['payment_status'],
        'customer_id' => $filter['customer_id'], 'cat_id' => $filter['cat_id'], 'tag_id' => $filter['tag_id'],
        'hosting_id' => $filter['hosting_id'], 'debt' => $filter['debt'] ? 1 : null, 'sort' => $filter['sort'] !== 'id:desc' ? $filter['sort'] : null,
    ]);
    $url = fn (array $changes = []) => route('backend.projects.index', array_filter([...$current, ...$changes], fn ($v) => $v !== null && $v !== ''));
    $filtering = $filter['q'] !== '' || $filter['payment_status'] !== '' || $filter['customer_id'] || $filter['cat_id'] || $filter['tag_id'] || $filter['hosting_id'] || $filter['debt'];

    // Nút lọc: [nhãn, [giá trị => [tên, ghi chú]]]. Khách hàng chỉ liệt kê người có dự án (và người đang chọn).
    $pills = [
        'customer_id' => ['Khách hàng', $customers
            ->filter(fn ($c) => ($customerCounts[$c->id]['direct'] ?? 0) > 0 || $c->id === $filter['customer_id'])
            ->mapWithKeys(fn ($c) => [$c->id => [$c->displayName(), ($customerCounts[$c->id]['direct'] ?? 0).' dự án']])->all()],
        'cat_id' => ['Danh mục', $categories->mapWithKeys(fn ($c) => [$c->id => [$c->indentedName(), null]])->all()],
        'payment_status' => ['Thanh toán', collect(Project::PAYMENT_STATUSES)->map(fn ($label) => [$label, null])->all()],
        'tag_id' => ['Tag', $tags->mapWithKeys(fn ($t) => [$t->id => [$t->name, null]])->all()],
        'hosting_id' => ['Hosting', $hostings->mapWithKeys(fn ($h) => [$h->id => [$h->name, null]])->all()],
    ];
    // Bỏ nút không có lựa chọn nào (vd chưa tạo danh mục dự án), trừ khi đang lọc theo nó
    $pills = array_filter($pills, fn ($pill, $key) => $pill[1] || ($filter[$key] ?? null), ARRAY_FILTER_USE_BOTH);
    $showCategory = $categories->isNotEmpty();
    $columns = 6 + ($showCategory ? 1 : 0);
    // Đang tìm: domain (chính / alias) khớp từ khóa thì in đậm
    $needle = mb_strtolower(trim($filter['q']));
    $matches = fn (?string $domain) => $needle !== '' && $domain && str_contains(mb_strtolower($domain), $needle);

    $stat = $metrics['status'];
    $metricLink = fn (string $status) => $url(['status' => $filter['status'] === $status ? null : $status]);
    $renewalUrl = route('backend.renewals.index', ['period' => 'custom', 'from' => $today->toDateString(), 'to' => $today->addDays(Renewal::SOON_DAYS)->toDateString(), 'renewed' => 'not_renewed']);
    $tagDot = fn ($color) => preg_match('/^#[0-9a-f]{3,8}$/i', (string) $color) ? $color : '#d9d9d9';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
@endphp

@section('actions')
  @if ($canAdd)
    <a href="{{ route('backend.projects.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm dự án</span></a>
  @endif
@endsection

@section('content')
<div id="projects" class="mx-auto max-w-[1200px]" data-list>
  {{-- Chỉ số: Đang triển khai / Đã bàn giao mở tab tương ứng (bấm lại để về Tất cả) --}}
  <div class="ant-card ant-card-bordered mb-4">
    <div class="product-metrics" id="project-metrics">
      <a href="{{ $metricLink('in_progress') }}" @class(['product-metric', 'is-active' => $filter['status'] === 'in_progress'])>
        <span class="product-metric-label">Đang triển khai</span>
        <span class="product-metric-value">{{ $count($stat['in_progress'] ?? 0) }}</span>
        <span class="product-metric-sub">{{ $count($stat['lead'] ?? 0) }} đang đàm phán</span>
      </a>
      <a href="{{ $metricLink('delivered') }}" @class(['product-metric', 'is-active' => $filter['status'] === 'delivered'])>
        <span class="product-metric-label">Đã bàn giao</span>
        <span class="product-metric-value">{{ $count($stat['delivered'] ?? 0) }}</span>
        <span class="product-metric-sub">{{ $count($stat['maintenance'] ?? 0) }} đang bảo trì</span>
      </a>
      @php $next = $metrics['renewal_next']; @endphp
      @if (auth()->user()->can('renewal'))<a href="{{ $renewalUrl }}" class="product-metric" data-metric="renewal">@else<div class="product-metric" data-metric="renewal">@endif
        <span class="product-metric-label" title="Đến kỳ gia hạn trong {{ Renewal::SOON_DAYS }} ngày tới, chưa gia hạn">Sắp gia hạn</span>
        <span class="product-metric-value">{{ $count($metrics['renewal_count']) }}</span>
        <span class="product-metric-sub" @if ($next) title="{{ $next['project']->name }} · {{ $next['due']->format('d/m/Y') }}" @endif>{{ $next ? 'Gần nhất: '.$next['project']->name.' · '.$next['due']->format('d/m') : 'Không có trong '.Renewal::SOON_DAYS.' ngày tới' }}</span>
      @if (auth()->user()->can('renewal'))</a>@else</div>@endif
      {{-- Bấm: lọc các dự án còn công nợ (bấm lại để bỏ lọc) --}}
      <a href="{{ $url(['debt' => $filter['debt'] ? null : 1]) }}" @class(['product-metric', 'is-active' => $filter['debt']]) data-metric="debt" title="Nợ hợp đồng + phát sinh chưa thu (không tính dự án đã hủy gia hạn)">
        <span class="product-metric-label">Còn phải thu</span>
        <span @class(['product-metric-value', 'text-[#f5222d]' => $metrics['debt']['total'] > 0])>{{ Money::format($metrics['debt']['total']) }}</span>
        <span class="product-metric-sub">{{ $metrics['debt']['count'] ? $count($metrics['debt']['count']).' dự án còn công nợ' : 'Mọi dự án đã thu đủ' }}</span>
      </a>
    </div>
  </div>

  <div class="ant-card ant-card-bordered product-index">
    {{-- Tab trạng thái (số đếm theo tìm kiếm / bộ lọc đang chọn) --}}
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        <a href="{{ $url(['status' => null]) }}" role="tab" aria-selected="{{ $filter['status'] === '' ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['status'] === ''])>Tất cả <span class="product-view-count">{{ $count($statusCounts['all']) }}</span></a>
        @foreach (Project::STATUSES as $key => $label)
          <a href="{{ $url(['status' => $key]) }}" role="tab" aria-selected="{{ $filter['status'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['status'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($statusCounts[$key] ?? 0) }}</span></a>
        @endforeach
      </div>
    </div>

    {{-- Tìm kiếm (Enter để tìm) + nút lọc --}}
    <div class="product-search">
      <form method="GET" action="{{ route('backend.projects.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except($current, ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="project-search" placeholder="Tìm theo tên, domain, khách hàng, ghi chú" aria-label="Tìm dự án" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @foreach ($pills as $key => [$label, $options])
          @php $value = $filter[$key]; @endphp
          @if ($value !== '' && $value !== 0)
            <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="{{ $key }}">{{ $label }}: {{ $options[$value][0] ?? '#'.$value }}</button><a href="{{ $url([$key => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc {{ $label }}">{{ ant_icon('close') }}</a></span>
          @else
            <button type="button" class="filter-pill" data-filter="{{ $key }}">{{ $label }}{{ ant_icon('down') }}</button>
          @endif
        @endforeach
        {{-- Còn công nợ: bật / tắt (nợ hợp đồng hoặc phát sinh chưa thu) --}}
        @if ($filter['debt'])
          <span class="filter-pill is-applied" data-filter-debt><span class="filter-pill-label inline-flex items-center">Còn công nợ</span><a href="{{ $url(['debt' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc còn công nợ">{{ ant_icon('close') }}</a></span>
        @else
          <a href="{{ $url(['debt' => 1]) }}" class="filter-pill" data-filter-debt>Còn công nợ</a>
        @endif
        @if ($filtering)
          <a href="{{ $url(['q' => null, 'payment_status' => null, 'customer_id' => null, 'cat_id' => null, 'tag_id' => null, 'hosting_id' => null, 'debt' => null]) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
        <button type="button" class="filter-pill ml-auto" data-filter="sort">Sắp xếp: {{ $sorts[$filter['sort']] }}{{ ant_icon('down') }}</button>
      </div>

      {{-- Danh sách chọn của từng nút lọc: list.js mở dưới nút (popup), gõ để lọc khi danh sách dài --}}
      @foreach ($pills as $key => [$label, $options])
        <template id="filter-{{ $key }}">
          <div class="ant-dropdown">
            <div class="ant-table-filter-dropdown project-filter" data-filter-popup="{{ $key }}">
              @if (count($options) > 8)
                <div class="project-filter-search">
                  <span class="ant-input-affix-wrapper ant-input-affix-wrapper-sm"><span class="ant-input-prefix">{{ ant_icon('search') }}</span><input type="text" class="ant-input" placeholder="Tìm {{ mb_strtolower($label) }}" aria-label="Tìm {{ mb_strtolower($label) }}" /></span>
                </div>
              @endif
              <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
                @foreach ($options as $value => [$name, $note])
                  <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => (string) $filter[$key] === (string) $value]) role="menuitem">
                    <a href="{{ $url([$key => $value]) }}">{{ $name }}@if ($note) <span class="{{ $muted }}">· {{ $note }}</span>@endif</a>
                  </li>
                @endforeach
                <li class="ant-dropdown-menu-item ant-dropdown-menu-item-disabled hidden" data-empty>Không tìm thấy</li>
              </ul>
            </div>
          </div>
        </template>
      @endforeach
      <template id="filter-sort">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach ($sorts as $key => $label)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['sort'] === $key]) role="menuitem"><a href="{{ $url(['sort' => $key === 'id:desc' ? null : $key]) }}">{{ $label }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            {{-- Không có dòng nào: bỏ bề rộng tối thiểu của bảng để thông báo trống nằm gọn giữa khung, không phải cuộn ngang --}}
            <table @class(['product-table project-table', 'min-w-0' => $projects->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Dự án</th>
                  <th class="ant-table-cell" data-col="customer">Khách hàng</th>
                  {{-- Danh mục: chỉ màn rất rộng (dưới đó ghi ở dòng phụ dưới tên) — nhường chỗ cho domain alias + Hosting --}}
                  @if ($showCategory)<th class="ant-table-cell max-2xl:hidden" data-col="category">Danh mục</th>@endif
                  <th class="ant-table-cell max-lg:hidden" data-col="hosting">Hosting</th>
                  <th class="ant-table-cell" data-col="status">Trạng thái</th>
                  <th class="ant-table-cell" data-col="value" style="text-align: right">Giá trị (VND)</th>
                  <th class="ant-table-cell" data-col="created">Ngày tạo</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="project-rows">
                @forelse ($projects as $project)
                  @php
                      $price = (float) $project->price;
                      $paidRenewal = (float) $project->paid_renewal;
                      $paidExtra = (float) $project->paid_extra;
                      $unpaidExtra = (float) $project->unpaid_extra;
                      // Còn nợ = nợ hợp đồng + phát sinh chưa thu (Project::debtAmount)
                      $debt = $project->debtAmount();
                      $counts = $customerCounts[$project->customer_id] ?? ['direct' => 0, 'total' => 0];
                      $referred = $counts['total'] - $counts['direct'];
                      // Tiền mặc định là VND (cả trang đều vậy) — chỉ ghi đơn vị khi là tiền khác
                      $money = Money::format($price).($project->currency && $project->currency !== 'VND' ? ' '.$project->currency : '');
                      // Tên dự án thường chính là domain: không nhắc lại domain ở dòng phụ (icon quả cầu mở website)
                      $sameAsName = Project::normalizeSiteKey($project->name) === $project->site_key;
                      $statusTag = '<span class="ant-tag m-0 '.(Project::STATUS_TAGS[$project->status] ?? '').'">'.e($project->statusLabel()).'</span>';
                  @endphp
                  <tr class="ant-table-row ant-table-row-level-0 product-row" data-row-key="{{ $project->id }}" data-href="{{ route('backend.projects.show', $project) }}">
                    <td class="ant-table-cell">
                      <div class="flex items-center gap-3">
                        @if ($project->domainUrl())
                          <a href="{{ $project->domainUrl() }}" target="_blank" rel="noopener noreferrer" class="product-thumb project-thumb" data-tooltip="Mở {{ $project->site_key }}" aria-label="Mở website {{ $project->site_key }}">{{ ant_icon('global') }}</a>
                        @else
                          <span class="product-thumb project-thumb">{{ ant_icon('global') }}</span>
                        @endif
                        <div class="min-w-0 max-w-[300px]">
                          <a class="product-name wrap-anywhere" href="{{ route('backend.projects.show', $project) }}">{{ $project->name }}</a>
                          <div class="truncate text-xs {{ $muted }} max-sm:whitespace-normal max-sm:wrap-anywhere">
                            #{{ $project->id }}
                            @if ($project->primary_domain && ! $sameAsName) · <span @class(['project-domain-match' => $matches($project->primary_domain)])>{{ $project->primary_domain }}</span> @endif
                            @if ($project->tech_stack) · {{ $project->tech_stack }} @endif
                            @if ($showCategory && $project->category)<span class="2xl:hidden"> · {{ $project->category->name }}</span>@endif
                          </div>
                          {{-- Domain alias (cùng website): hiện luôn để tìm domain là biết dự án chạy những domain nào --}}
                          @if ($project->domains->isNotEmpty())
                            <div class="project-aliases" title="Domain alias">
                              {{ ant_icon('link', 'project-aliases-icon') }}
                              {{-- Mỗi domain kèm dấu phẩy trong cùng một khối: xuống dòng không để dấu phẩy rơi ra đầu dòng sau --}}
                              <span>@foreach ($project->domains as $alias)<span class="project-alias">@if ($alias->url())<a href="{{ $alias->url() }}" target="_blank" rel="noopener noreferrer" @if ($matches($alias->domain)) class="project-domain-match" @endif>{{ $alias->site_key }}</a>@else<span @if ($matches($alias->domain)) class="project-domain-match" @endif>{{ $alias->domain }}</span>@endif{{ $loop->last ? '' : ',' }}</span>{{ $loop->last ? '' : ' ' }}@endforeach</span>
                            </div>
                          @endif
                          {{-- Màn hẹp (cột Hosting ẩn): server ghi dưới tên --}}
                          @if ($project->hostings->isNotEmpty())
                            <div class="project-aliases lg:hidden">
                              {{ ant_icon('cloud-server', 'project-aliases-icon') }}
                              <span>{{ $project->hostings->map(fn ($h) => $h->name.($h->ip ? ' ('.$h->ip.')' : ''))->implode(', ') }}</span>
                            </div>
                          @endif
                          @if ($project->tags->isNotEmpty())
                            <div class="mt-1 flex flex-wrap gap-1">
                              @foreach ($project->tags as $tag)
                                <span class="ant-tag project-tag m-0" title="{{ $tag->name }}"><span class="project-tag-dot" style="background: {{ $tagDot($tag->color) }}"></span><span class="truncate">{{ $tag->name }}</span></span>
                              @endforeach
                            </div>
                          @endif
                          <div class="product-row-meta">{!! $statusTag !!} <span class="tabular-nums">{{ $money }}</span></div>
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell" data-col="customer">
                      @if ($project->customer)
                        <a href="{{ $url(['customer_id' => $project->customer_id]) }}" class="project-link block max-w-[160px] truncate" title="Lọc dự án của {{ $project->customer->name }}">{{ $project->customer->name }}</a>
                        <div class="text-xs {{ $muted }}">{{ $counts['direct'] }} dự án @if ($referred > 0)· +{{ $referred }} giới thiệu @endif</div>
                      @else
                        <span class="{{ $muted }}">—</span>
                      @endif
                    </td>
                    @if ($showCategory)
                      <td class="ant-table-cell max-2xl:hidden" data-col="category">
                        @if ($project->category)
                          <a href="{{ $url(['cat_id' => $project->cat_id]) }}" class="project-link block max-w-[130px] truncate" title="Lọc theo danh mục {{ $project->category->name }}">{{ $project->category->name }}</a>
                        @else
                          <span class="{{ $muted }}">Chưa phân loại</span>
                        @endif
                      </td>
                    @endif
                    <td class="ant-table-cell max-lg:hidden" data-col="hosting">
                      @forelse ($project->hostings as $hosting)
                        <div class="project-hosting">
                          <a href="{{ $url(['hosting_id' => $hosting->id]) }}" @class(['project-link', $muted => ! $hosting->is_active]) title="Lọc dự án trên {{ $hosting->name }}">{{ $hosting->name }}</a>
                          @if ($hosting->ownership === 'customer')<span class="ant-tag ant-tag-blue project-hosting-tag">Của khách</span>@endif
                          @if ($hosting->ip)<div class="font-mono text-xs {{ $muted }} wrap-anywhere">{{ $hosting->ip }}</div>@endif
                        </div>
                      @empty
                        <span class="{{ $muted }}">—</span>
                      @endforelse
                    </td>
                    <td class="ant-table-cell" data-col="status">{!! $statusTag !!}</td>
                    <td class="ant-table-cell tabular-nums" data-col="value" style="text-align: right">
                      <div>{{ $money }}</div>
                      @if ($debt > 0)
                        <div class="text-xs text-[#f5222d]" title="Giá hợp đồng trừ tiền thu mới đã thu (không tính tiền gia hạn), cộng phát sinh chưa thu">Còn nợ {{ Money::format($debt) }}</div>
                        @if ($unpaidExtra > 0)
                          <div class="text-xs text-[#fa541c]" title="Phát sinh ngoài hợp đồng, khách chưa trả">gồm phát sinh {{ Money::format($unpaidExtra) }}</div>
                        @endif
                      @elseif ($price > 0)
                        <div class="text-xs text-[#52c41a]">Đã thu đủ</div>
                      @endif
                      @if ($paidRenewal > 0)
                        <div class="text-xs {{ $muted }}" title="Tiền gia hạn đã thu, tính riêng">+{{ Money::format($paidRenewal) }} gia hạn</div>
                      @endif
                      @if ($paidExtra > 0)
                        <div class="text-xs {{ $muted }}" title="Tiền phát sinh đã thu, tính riêng">+{{ Money::format($paidExtra) }} phát sinh</div>
                      @endif
                    </td>
                    {{-- Ngày tạo = ngày bắt đầu dự án (created_at của dữ liệu cũ là ngày chuyển hệ thống, không phải ngày tạo thật) --}}
                    <td class="ant-table-cell tabular-nums" data-col="created">
                      @if ($project->start_date)
                        {{ $project->start_date->format('d/m/Y') }}
                      @else
                        <span class="{{ $muted }}">—</span>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="{{ $columns }}" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('search', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if ($filtering || $filter['status'] !== '')
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy dự án nào</strong>Thử từ khóa khác hoặc bỏ bớt bộ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.projects.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có dự án nào</strong>Dự án bạn thêm sẽ hiện ở đây.</p>
                          @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.projects.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm dự án</span></a></div>@endif
                        @endif
                      </div>
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    @if ($projects->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($projects->firstItem()) }}–{{ $count($projects->lastItem()) }} / {{ $count($projects->total()) }} dự án</span>
        {{ $projects->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
