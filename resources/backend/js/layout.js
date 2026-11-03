import $ from 'jquery';

import { closePopup, isPopupOpenFor, openPopup } from './core';

/*
 * Khung trang quản trị (layouts/backend.blade.php), theo src/js/layout.js của template ProCMS:
 *   - menu cha trong sidebar thu / mở
 *   - menu tài khoản mở từ avatar đầu sidebar (nội dung lấy từ <template id="account-menu">)
 *   - mobile: sidebar nằm trong drawer, mở bằng nút ở header
 *   - thông báo thành công tự ẩn, khung báo lỗi đóng được
 * Mục đang mở của sidebar được đánh dấu sẵn từ server nên không cần <body data-page> như template.
 */

const MOBILE_QUERY = window.matchMedia('(max-width: 991px)');

function toggleSubmenu($title) {
  const $li = $title.parent();
  const $sub = $li.children('.ant-menu-sub');
  const open = !$li.hasClass('ant-menu-submenu-open');
  $title.attr('aria-expanded', open);
  $li.toggleClass('ant-menu-submenu-open', open);
  if (open) {
    $sub.removeClass('ant-menu-hidden').hide().slideDown(200);
  } else {
    $sub.slideUp(200, () => $sub.addClass('ant-menu-hidden').css('display', ''));
  }
}

/* Sidebar: menu cha thu / mở khi bấm, hoặc Enter / Space (tiêu đề là role="button"). Gắn ở document để chạy cả trong drawer. */
function initSidebar() {
  const title = '.sidebar .ant-menu-submenu-inline > .ant-menu-submenu-title';
  $(document).on('click', title, function () {
    toggleSubmenu($(this));
  });
  $(document).on('keydown', title, function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    toggleSubmenu($(this));
  });
}

/* Menu tài khoản: avatar đầu sidebar (cả bản trong drawer mobile). */
function initAccountMenu() {
  const template = document.getElementById('account-menu');
  if (!template) return;

  $(document).on('click', '.sidebar-user-button', function (e) {
    if (isPopupOpenFor(this)) return closePopup();
    const $button = $(this).attr('aria-expanded', true);
    const { $popup } = openPopup($button, template.innerHTML.trim(), {
      placementClass: 'ant-dropdown',
      gap: 8,
      onClose: () => {
        $button.attr('aria-expanded', false);
        // Đóng bằng Esc khi focus đang trong menu: trả focus về avatar thay vì rơi về <body>
        if (document.activeElement === document.body) $button.trigger('focus');
      },
    });
    // Đăng xuất: gửi form POST có CSRF thay vì mở link
    $popup.on('click', '[data-submit]', function (ev) {
      ev.preventDefault();
      document.getElementById(this.dataset.submit)?.submit();
    });
    $popup.on('click', 'a', () => setTimeout(closePopup));
    // Mở bằng bàn phím (Enter / Space): đưa focus vào mục đầu, mũi tên lên / xuống để chuyển mục
    if (e.detail === 0) $popup.find('a').first().trigger('focus');
    $popup.on('keydown', 'a', function (ev) {
      if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;
      ev.preventDefault();
      const $links = $popup.find('a');
      const i = $links.index(this) + (ev.key === 'ArrowDown' ? 1 : -1);
      $links.eq((i + $links.length) % $links.length).trigger('focus');
    });
  });
}

/* Mobile: sidebar nằm trong drawer, mở bằng nút ở header; bấm nền mờ hoặc Esc để đóng. */
function initDrawer() {
  const $trigger = $('.app-header .trigger');
  if (!$trigger.length) return;
  let $drawer = null;

  const close = () => {
    if (!$drawer) return;
    $drawer.removeClass('ant-drawer-open');
    $drawer.find('.ant-drawer-content-wrapper').css('transform', 'translateX(-100%)');
    $trigger.attr('aria-expanded', false);
  };
  const build = () => {
    $drawer = $(`
      <div tabindex="-1" class="ant-drawer ant-drawer-left chat-drawer">
        <div class="ant-drawer-mask"></div>
        <div class="ant-drawer-content-wrapper" style="width: 220px; transform: translateX(-100%)">
          <div class="ant-drawer-content">
            <div class="ant-drawer-wrapper-body">
              <div class="ant-drawer-body">
                <div class="app-sidebar">
                  <nav class="drawer-sidebar" aria-label="Menu"></nav>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>`).appendTo('body');
    $drawer.find('.drawer-sidebar').append($('.ant-layout-sider-children').children().clone());
    $drawer.on('click', '.ant-drawer-mask', close);
  };
  const open = () => {
    if (!$drawer) build();
    $drawer.addClass('ant-drawer-open');
    $drawer.find('.ant-drawer-content-wrapper').css('transform', '');
    $trigger.attr('aria-expanded', true);
  };

  $trigger.on('click', e => {
    e.preventDefault();
    if ($drawer && $drawer.hasClass('ant-drawer-open')) close();
    else open();
  });
  // Esc: đóng menu tài khoản trước (core.js), lần sau mới đóng drawer. Chạy ở pha capture để biết lúc bấm phím
  // còn popup nào mở hay không (core.js đóng popup ở pha bubble, trước khi handler bubble này kịp kiểm tra).
  document.addEventListener(
    'keydown',
    e => {
      if (e.key === 'Escape' && $drawer && $drawer.hasClass('ant-drawer-open') && !$('.popup-root').length) close();
    },
    true,
  );
  MOBILE_QUERY.addEventListener('change', () => !MOBILE_QUERY.matches && close());
}

/* Thông báo: message thành công tự ẩn sau 3 giây như Ant Design; khung báo lỗi có nút × */
function initNotices() {
  $('[data-flash-message]').each(function () {
    const $notice = $(this);
    setTimeout(() => $notice.removeClass('move-up-appear move-up-appear-active'), 200);
    setTimeout(() => {
      $notice.addClass('move-up-leave move-up-leave-active');
      setTimeout(() => $notice.remove(), 200);
    }, 3000);
  });
  $(document).on('click', '.ant-alert-close-icon', function () {
    $(this).closest('.ant-alert').remove();
  });
}

export default function initLayout() {
  initSidebar();
  initAccountMenu();
  initDrawer();
  initNotices();
}
