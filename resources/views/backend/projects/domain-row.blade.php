{{-- Một dòng domain alias trong form dự án. Tham số: $i (chỉ số trong domains[…], "__I__" ở mẫu cho JS), $row
     (['domain' => …, 'note' => …]), $errors. JS thêm / bỏ dòng: project-form.js --}}
@php
    $domainError = $errors->first("domains.{$i}.domain") ?: $errors->first("domains.{$i}");
    $noteError = $errors->first("domains.{$i}.note");
@endphp
<div class="domain-alias-row" data-domain-row>
  <div @class(['ant-row ant-form-item mb-0 domain-alias-domain', 'ant-form-item-has-error ant-form-item-with-help' => $domainError])>
    <div class="ant-col ant-form-item-control">
      <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
        <input type="text" name="domains[{{ $i }}][domain]" value="{{ $row['domain'] ?? '' }}" class="ant-input" placeholder="en.example.com" maxlength="255" aria-label="Domain alias" spellcheck="false" />
      </div></div>
      @if ($domainError)<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">{{ $domainError }}</div></div>@endif
    </div>
  </div>
  <div @class(['ant-row ant-form-item mb-0 domain-alias-note', 'ant-form-item-has-error ant-form-item-with-help' => $noteError])>
    <div class="ant-col ant-form-item-control">
      <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
        <input type="text" name="domains[{{ $i }}][note]" value="{{ $row['note'] ?? '' }}" class="ant-input" placeholder="Ghi chú (vd bản tiếng Anh)" maxlength="255" aria-label="Ghi chú domain" />
      </div></div>
      @if ($noteError)<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">{{ $noteError }}</div></div>@endif
    </div>
  </div>
  <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only domain-alias-remove" data-domain-remove data-tooltip="Bỏ domain này" aria-label="Bỏ domain alias">{{ ant_icon('minus-circle') }}</button>
</div>
