import $ from 'jquery';

import { closePopup, escapeHtml, icon, openPopup } from './core';

/* Focus rings that Ant Design draws on wrapper elements. */
function initFocus() {
  const wrappers = [
    ['.ant-input-affix-wrapper', 'ant-input-affix-wrapper-focused'],
    ['.ant-input-number', 'ant-input-number-focused'],
    ['.ant-picker', 'ant-picker-focused'],
    ['.ant-select', 'ant-select-focused'],
  ];
  for (const [sel, cls] of wrappers) {
    $(document)
      .on('focusin', sel, function () {
        $(this).addClass(cls);
      })
      .on('focusout', sel, function () {
        $(this).removeClass(cls);
      });
  }
  // Clicking the wrapper (prefix, padding) focuses its input.
  $(document).on('mousedown', '.ant-input-affix-wrapper', function (e) {
    if (e.target.tagName !== 'INPUT') {
      e.preventDefault();
      $(this).find('input').trigger('focus');
    }
  });
}

/* Password inputs: the eye icon toggles visibility. */
function initPassword() {
  $(document).on('mousedown', '.ant-input-password-icon', e => e.preventDefault());
  $(document).on('click', '.ant-input-password-icon', function () {
    const $input = $(this).closest('.ant-input-password').find('input');
    const visible = $input.attr('type') === 'text';
    $input.attr('type', visible ? 'password' : 'text');
    $(this).replaceWith(icon(visible ? 'eye-invisible' : 'eye', 'ant-input-password-icon'));
  });
}

/* Checkbox state lives on the native input; mirror it to the Ant Design classes. */
export function syncCheckbox(input) {
  const $input = $(input);
  const checked = $input.prop('checked');
  $input.closest('.ant-checkbox').toggleClass('ant-checkbox-checked', checked);
  $input.closest('.ant-checkbox-wrapper').toggleClass('ant-checkbox-wrapper-checked', checked);
}

function initCheckbox() {
  $(document).on('change', '.ant-checkbox-input', function () {
    syncCheckbox(this);
  });
}

/* Radios have no `name`, so exclusivity is handled per .ant-radio-group. */
export function setRadioValue($group, value) {
  $group.find('.ant-radio-input, .ant-radio-button-input').each(function () {
    const checked = this.value === value;
    const prefix = $(this).hasClass('ant-radio-button-input') ? 'ant-radio-button' : 'ant-radio';
    this.checked = checked;
    $(this).parent().toggleClass(`${prefix}-checked`, checked);
    $(this).closest(`.${prefix}-wrapper`).toggleClass(`${prefix}-wrapper-checked`, checked);
  });
  $group.attr('data-value', value).trigger('radio:change', [value]);
}

function initRadio() {
  $(document).on('change', '.ant-radio-input, .ant-radio-button-input', function () {
    setRadioValue($(this).closest('.ant-radio-group'), this.value);
  });
}

/* Switch */
function initSwitch() {
  $(document).on('click', '.ant-switch', function () {
    const checked = !$(this).hasClass('ant-switch-checked');
    $(this).toggleClass('ant-switch-checked', checked).attr('aria-checked', checked);
    // ProCMS: công tắc trong form thật đi kèm <input type="checkbox" class="switch-input" hidden> cạnh nó để gửi lên server
    $(this).siblings('.switch-input').prop('checked', checked).trigger('change');
  });
}

/* Rate: hover previews, click sets the value (stored on the <ul> as data-value). */
function initRate() {
  const paint = ($rate, value) =>
    $rate.children('.ant-rate-star').each(function (i) {
      $(this)
        .toggleClass('ant-rate-star-full', i < value)
        .toggleClass('ant-rate-star-zero', i >= value)
        .find('[role=radio]')
        .attr('aria-checked', i < value);
    });

  $(document)
    .on('mousemove', '.ant-rate-star', function () {
      paint($(this).parent(), $(this).index() + 1);
    })
    .on('mouseleave', '.ant-rate', function () {
      paint($(this), Number($(this).attr('data-value') || 0));
    })
    .on('click', '.ant-rate-star', function () {
      const $rate = $(this).parent();
      const value = $(this).index() + 1;
      $rate.attr('data-value', Number($rate.attr('data-value')) === value ? 0 : value);
      paint($rate, Number($rate.attr('data-value')));
    });
}

/* Slider (0–100): drag the handle or click the rail. */
function initSlider() {
  const set = ($slider, value) => {
    value = Math.max(0, Math.min(100, Math.round(value)));
    $slider.attr('data-value', value);
    $slider.find('.ant-slider-track').css({ left: '0%', right: 'auto', width: `${value}%` });
    $slider.find('.ant-slider-handle').css('left', `${value}%`).attr('aria-valuenow', value);
    $slider.find('.ant-slider-dot').each(function () {
      $(this).toggleClass('ant-slider-dot-active', parseFloat(this.style.left) <= value);
    });
    $slider.find('.ant-slider-mark-text').each(function () {
      $(this).toggleClass('ant-slider-mark-text-active', parseFloat(this.style.left) <= value);
    });
  };
  const valueAt = ($slider, pageX) => {
    const rect = $slider[0].getBoundingClientRect();
    return ((pageX - window.scrollX - rect.left) / rect.width) * 100;
  };

  $(document).on('mousedown', '.ant-slider', function (e) {
    if ($(this).hasClass('ant-slider-disabled')) return;
    e.preventDefault();
    const $slider = $(this);
    $slider.find('.ant-slider-handle').trigger('focus');
    set($slider, valueAt($slider, e.pageX));
    $(document)
      .on('mousemove.slider', ev => set($slider, valueAt($slider, ev.pageX)))
      .one('mouseup', () => $(document).off('mousemove.slider'));
  });
}

/* InputNumber: step handlers + clamping to min / max. */
function initInputNumber() {
  const update = ($wrap, value) => {
    const $input = $wrap.find('.ant-input-number-input');
    const min = $input.attr('min') !== undefined ? Number($input.attr('min')) : -Infinity;
    const max = $input.attr('max') !== undefined ? Number($input.attr('max')) : Infinity;
    value = Math.max(min, Math.min(max, value));
    $input.val(value).attr('aria-valuenow', value);
    $wrap.find('.ant-input-number-handler-up').toggleClass('ant-input-number-handler-up-disabled', value >= max);
    $wrap.find('.ant-input-number-handler-down').toggleClass('ant-input-number-handler-down-disabled', value <= min);
    $input.trigger('number:change', [value]);
  };

  $(document).on('mousedown', '.ant-input-number-handler', function (e) {
    e.preventDefault();
    const $wrap = $(this).closest('.ant-input-number');
    const step = Number($wrap.find('input').attr('step') || 1);
    const current = Number($wrap.find('input').val()) || 0;
    $wrap.find('input').trigger('focus');
    update($wrap, current + ($(this).hasClass('ant-input-number-handler-up') ? step : -step));
  });
  $(document).on('change', '.ant-input-number-input', function () {
    const value = parseFloat(this.value);
    const $wrap = $(this).closest('.ant-input-number');
    if (Number.isNaN(value)) $(this).val('');
    else update($wrap, value);
  });
  $(document).on('keydown', '.ant-input-number-input', function (e) {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault();
    const $wrap = $(this).closest('.ant-input-number');
    update($wrap, (Number(this.value) || 0) + (e.key === 'ArrowUp' ? 1 : -1));
  });
}

/* Tooltip for any element with data-tooltip. */
function initTooltip() {
  $(document)
    .on('mouseenter', '[data-tooltip]', function () {
      const $el = $(this);
      const { $popup } = openPopup(
        $el,
        `<div class="ant-tooltip ant-tooltip-placement-top">
          <div class="ant-tooltip-content">
            <div class="ant-tooltip-arrow"><span class="ant-tooltip-arrow-content"></span></div>
            <div class="ant-tooltip-inner" role="tooltip">${escapeHtml($el.attr('data-tooltip'))}</div>
          </div>
        </div>`,
      );
      // Centered above the element, kept inside the viewport; the arrow keeps pointing at the element.
      // ProCMS: phía trên không đủ chỗ (vd nút trên thanh trên cùng) thì lật xuống dưới như Ant Design
      // (autoAdjustOverflow); rộng tối đa 250px như Ant Design, chữ dài xuống dòng.
      const a = this.getBoundingClientRect();
      const vw = document.documentElement.clientWidth;
      $popup.css('max-width', Math.min(250, vw - 16));
      const w = $popup.outerWidth();
      const h = $popup.outerHeight();
      const center = a.left + a.width / 2;
      const left = Math.max(8, Math.min(center - w / 2, vw - 8 - w));
      const below = a.top - h < 0 && a.bottom + h <= window.innerHeight;
      $popup
        .toggleClass('ant-tooltip-placement-top', !below)
        .toggleClass('ant-tooltip-placement-bottom', below)
        .css({ left: left + window.scrollX, top: (below ? a.bottom : a.top - h) + window.scrollY });
      $popup.find('.ant-tooltip-arrow').css({ left: center - left, transform: 'translateX(-50%)' });
    })
    .on('mouseleave', '[data-tooltip]', closePopup);
}

/* One image in a picture-card upload list. */
export const pictureCard = (src, name = '') => `<div class="ant-upload-list-picture-card-container">
  <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
    <div class="ant-upload-list-item-info">
      <span><a class="ant-upload-list-item-thumbnail" href="${src}" target="_blank" rel="noopener noreferrer"
        ><img src="${src}" alt="${escapeHtml(name)}" class="ant-upload-list-item-image" /></a
      ></span>
    </div>
    <span class="ant-upload-list-item-actions"
      ><a href="${src}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">${icon('eye')}</a
      ><button title="Bỏ ảnh" type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only remove-file">${icon(
        'delete',
      )}</button></span>
  </div>
</div>`;

/* Upload: click (or drop on a dragger) selects files, listed under the control. */
function initUpload() {
  const listItem = (file, type) => {
    const name = escapeHtml(file.name);
    const thumb =
      type === 'picture'
        ? `<span class="ant-upload-list-item-thumbnail">${
            file.type.startsWith('image/')
              ? `<img src="${URL.createObjectURL(file)}" alt="${name}" class="ant-upload-list-item-image" />`
              : icon('paper-clip', 'ant-upload-list-item-icon')
          }</span>`
        : `<div class="ant-upload-text-icon">${icon('paper-clip')}</div>`;
    return `<div class="ant-upload-list-${type}-container">
      <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-${type}">
        <div class="ant-upload-list-item-info">
          <span>${thumb}<a class="ant-upload-list-item-name" title="${name}">${name}</a
            ><span class="ant-upload-list-item-card-actions ${type === 'picture' ? 'picture' : ''}"
              ><button title="Remove file" type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only">${icon(
                'delete',
              )}</button></span></span>
        </div>
      </div>
    </div>`;
  };
  const addFiles = ($upload, files) => {
    // Picture cards: images are read as data URLs (so pages can store them) and shown before the "+" card.
    if ($upload.hasClass('ant-upload-select-picture-card')) {
      [...files]
        .filter(f => f.type.startsWith('image/'))
        .forEach(f => {
          const reader = new FileReader();
          reader.onload = () => {
            $upload.before(pictureCard(reader.result, f.name));
            $upload.closest('.ant-upload-list').trigger('upload:change');
          };
          reader.readAsDataURL(f);
        });
      return;
    }
    const $list = $upload.parent().find('.ant-upload-list');
    const type = $list.hasClass('ant-upload-list-picture') ? 'picture' : 'text';
    [...files].forEach(f => $list.append(listItem(f, type)));
  };

  $(document).on('click', '.ant-upload[role=button]', function (e) {
    if (e.target.tagName !== 'INPUT') $(this).find('input[type=file]').trigger('click');
  });
  $(document).on('change', '.ant-upload input[type=file]', function () {
    addFiles($(this).closest('.ant-upload-select, .ant-upload-drag'), this.files);
    this.value = '';
  });
  $(document).on('click', '.ant-upload-list-item-card-actions .ant-btn', function () {
    $(this).closest('[class$="-container"]').remove();
  });
  $(document).on('click', '.ant-upload-list-item-actions .remove-file', function () {
    const $list = $(this).closest('.ant-upload-list');
    $(this).closest('.ant-upload-list-picture-card-container').remove();
    $list.trigger('upload:change');
  });
  $(document)
    .on('dragover', '.ant-upload-drag', function (e) {
      e.preventDefault();
      $(this).addClass('ant-upload-drag-hover');
    })
    .on('dragleave', '.ant-upload-drag', function () {
      $(this).removeClass('ant-upload-drag-hover');
    })
    .on('drop', '.ant-upload-drag', function (e) {
      e.preventDefault();
      $(this).removeClass('ant-upload-drag-hover');
      addFiles($(this), e.originalEvent.dataTransfer.files);
    });
}

export default function initControls() {
  initFocus();
  initPassword();
  initCheckbox();
  initRadio();
  initSwitch();
  initRate();
  initSlider();
  initInputNumber();
  initTooltip();
  initUpload();
}
