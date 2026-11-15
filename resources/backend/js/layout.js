import $ from 'jquery';

import { closePopup, icon, isPopupOpenFor, openPopup } from './core';

/*
 * Khung trang quản trị (layouts/backend.blade.php), theo layout Admin Pro của template (nhánh admin-pro, src/js/pro.js):
 *   - menu cha trong sidebar thu / mở
 *   - sidebar thu gọn còn 48px (nút ở header và cuối sidebar), nhớ bằng cookie backend_sider để server dựng sẵn;
 *     khi thu gọn: rê chuột / focus / bấm vào menu cha hiện các mục con bên phải, rê vào mục thường hiện tên mục
 *   - menu tài khoản mở từ avatar + tên ở header (nội dung lấy từ <template id="account-menu">)
 *   - mobile (< 992px): sidebar nằm trong drawer, mở bằng chính nút thu gọn ở header
 *   - thanh trang (breadcrumb) hiện đường kẻ mép dưới khi nội dung cuộn xuống dưới nó
 *   - thông báo thành công tự ẩn, khung báo lỗi đóng được
 * Mục đang mở của sidebar được đánh dấu sẵn từ server nên không cần <body data-pro-menu> như template.
 */

const MOBILE_QUERY = window.matchMedia('(max-width: 991px)');
const SIDER_COLLAPSED_WIDTH = 48;

const $sidebar = () => $('#app-sidebar');
const isCollapsed = () => !MOBILE_QUERY.matches && $sidebar().hasClass('is-collapsed');
const inCollapsedSider = el => isCollapsed() && $(el).closest('#app-sidebar').length > 0;

function toggleSubmenu($title) {
  const $li = $title.parent();
  const $sub = $li.children('.pro-menu-sub');
  const open = !$li.hasClass('pro-menu-submenu-open');
  $title.attr('aria-expanded', open);
  $li.toggleClass('pro-menu-submenu-open', open);
  if (open) {
    $sub.removeAttr('hidden').stop(true, true).hide().slideDown(200);
  } else {
    $sub.stop(true, true).slideUp(200, () => $sub.attr('hidden', true).css('display', ''));
  }
}

/* Sidebar: menu cha thu / mở khi bấm (tiêu đề là <button> nên Enter / Space cũng chạy). Gắn ở document để chạy cả
   trong drawer. Sidebar đang thu gọn thì menu cha mở dạng popup bên phải (initCollapsedMenu). */
function initSidebar() {
  $(document).on('click', '.pro-menu-title', function () {
    if (inCollapsedSider(this)) return;
    toggleSubmenu($(this));
  });
}

/* Thu gọn / mở rộng sidebar (desktop). Cookie để lần tải trang sau server dựng sẵn đúng trạng thái. */
function setCollapsed(collapsed) {
  $sidebar().toggleClass('is-collapsed', collapsed);
  $('[data-sider-toggle]')
    .attr('aria-expanded', !collapsed)
    .html(icon(collapsed ? 'menu-unfold' : 'menu-fold'));
  document.cookie = `backend_sider=${collapsed ? 'collapsed' : 'expanded'}; path=/; max-age=31536000; samesite=lax`;
  $(document).trigger('sider:toggle');
}

/* Sidebar thu gọn: menu cha → popup các mục con bên phải (cách 6px, ngang hàng với icon); mục thường → tên mục. */
function initCollapsedMenu() {
  let $flyout = null;
  let $tip = null;
  let owner = null;
  let hideTimer = 0;
  let tipTimer = 0;

  const hideTip = () => {
    clearTimeout(tipTimer);
    $tip?.remove();
    $tip = null;
  };
  const hideFlyout = () => {
    if (!$flyout) return;
    $(owner).attr('aria-expanded', $(owner).parent().hasClass('pro-menu-submenu-open'));
    $flyout.remove();
    $flyout = null;
    owner = null;
  };
  const showFlyout = title => {
    hideTip();
    clearTimeout(hideTimer);
    if (owner === title) return;
    hideFlyout();
    const $items = $(title).next('.pro-menu-sub');
    if (!$items.length) return;
    $flyout = $('<ul class="pro-menu pro-menu-popup" role="menu"></ul>').html($items.html()).appendTo('body');
    const rect = title.getBoundingClientRect();
    $flyout.css({
      left: SIDER_COLLAPSED_WIDTH + 6,
      top: Math.max(8, Math.min(rect.top, window.innerHeight - $flyout.outerHeight() - 8)),
    });
    owner = title;
    $(title).attr('aria-expanded', true);
  };
  const showTip = link => {
    hideFlyout();
    hideTip();
    const text = link.dataset.label;
    if (!text) return;
    tipTimer = setTimeout(() => {
      $tip = $('<div class="pro-menu-tip" role="tooltip"></div>').text(text).appendTo('body');
      const rect = link.getBoundingClientRect();
      $tip.css({ left: SIDER_COLLAPSED_WIDTH + 12, top: rect.top + rect.height / 2 - $tip.outerHeight() / 2 });
    }, 100);
  };
  const hideAll = () => {
    hideTip();
    hideFlyout();
  };

  $(document).on('mouseover', e => {
    if (!isCollapsed()) return;
    const $target = $(e.target);
    const title = $target.closest('#app-sidebar .pro-menu-title')[0];
    const link = $target.closest('#app-sidebar .pro-menu > .pro-menu-item > a')[0];
    clearTimeout(hideTimer);
    if (title) return showFlyout(title);
    if (link) return showTip(link);
    if ($target.closest('.pro-menu-popup').length) return;
    hideTip();
    hideTimer = setTimeout(hideFlyout, 100);
  });
  // Bàn phím / màn hình chạm: bấm vào menu cha mở popup và đưa focus vào mục đầu; Esc đóng, trả focus về menu cha
  $(document).on('click', '#app-sidebar .pro-menu-title', function () {
    if (!isCollapsed()) return;
    if (owner === this) return hideFlyout();
    showFlyout(this);
    $flyout?.find('a').first().trigger('focus');
  });
  $(document).on('keydown', e => {
    if (e.key !== 'Escape' || !$flyout) return;
    const title = owner;
    hideFlyout();
    title?.focus();
  });
  $(document).on('focusout', '.pro-menu-popup', e => {
    if (!$(e.relatedTarget).closest('.pro-menu-popup').length) hideTimer = setTimeout(hideFlyout, 100);
  });
  $(document).on('mousedown', e => {
    if ($flyout && !$(e.target).closest('.pro-menu-popup, #app-sidebar .pro-menu-title').length) hideFlyout();
  });
  $(document.documentElement).on('mouseleave', hideAll);
  $(document).on('sider:toggle', hideAll);
  $(window).on('resize', hideAll);
  $('#app-sidebar .pro-sider-menu').on('scroll', hideAll);
}

/* Menu tài khoản: avatar + tên ở góc phải header. */
function initAccountMenu() {
  const template = document.getElementById('account-menu');
  if (!template) return;

  $(document).on('click', '.app-header-account', function (e) {
    if (isPopupOpenFor(this)) return closePopup();
    const $button = $(this).attr('aria-expanded', true);
    const { $popup } = openPopup($button, template.innerHTML.trim(), {
      placementClass: 'ant-dropdown',
      gap: 4,
      onClose: () => {
        $button.attr('aria-expanded', false);
        // Đóng bằng Esc khi focus đang trong menu: trả focus về nút tài khoản thay vì rơi về <body>
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

/* Nút thu gọn (header + cuối sidebar). Desktop: thu gọn / mở rộng sidebar. Mobile: sidebar nằm trong drawer, mở bằng
   nút ở header; bấm nền mờ hoặc Esc để đóng. */
function initSiderToggle() {
  const $headerToggle = $('.app-header-toggle');
  if (!$headerToggle.length) return;
  let $drawer = null;

  const isOpen = () => !!$drawer && $drawer.hasClass('ant-drawer-open');
  const close = () => {
    if (!isOpen()) return;
    $drawer.removeClass('ant-drawer-open');
    $drawer.find('.ant-drawer-content-wrapper').css('transform', 'translateX(-100%)');
    $headerToggle.attr('aria-expanded', false);
  };
  const build = () => {
    $drawer = $(`
      <div tabindex="-1" class="ant-drawer ant-drawer-left sider-drawer">
        <div class="ant-drawer-mask"></div>
        <div class="ant-drawer-content-wrapper" style="width: 208px; transform: translateX(-100%)">
          <div class="ant-drawer-content">
            <div class="ant-drawer-wrapper-body">
              <div class="ant-drawer-body">
                <div class="app-sidebar app-sidebar-drawer">
                  <nav class="pro-sider" aria-label="Menu chính"><div class="pro-sider-body"></div></nav>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>`).appendTo('body');
    $drawer.find('.pro-sider-body').append($('#app-sidebar .pro-sider-body').children().clone());
    $drawer.on('click', '.ant-drawer-mask', close);
  };
  const open = () => {
    if (!$drawer) build();
    $drawer.addClass('ant-drawer-open');
    $drawer.find('.ant-drawer-content-wrapper').css('transform', '');
    $headerToggle.attr('aria-expanded', true);
  };

  $(document).on('click', '[data-sider-toggle]', () => {
    if (!MOBILE_QUERY.matches) return setCollapsed(!$sidebar().hasClass('is-collapsed'));
    if (isOpen()) close();
    else open();
  });
  // Esc: đóng menu tài khoản trước (core.js), lần sau mới đóng drawer. Chạy ở pha capture để biết lúc bấm phím
  // còn popup nào mở hay không (core.js đóng popup ở pha bubble, trước khi handler bubble này kịp kiểm tra).
  document.addEventListener(
    'keydown',
    e => {
      if (e.key === 'Escape' && isOpen() && !$('.popup-root').length) close();
    },
    true,
  );
  // Đổi giữa mobile / desktop: nút ở header báo đúng trạng thái của thứ nó điều khiển (drawer / sidebar)
  const sync = () => {
    if (MOBILE_QUERY.matches) return $headerToggle.attr('aria-expanded', isOpen());
    close();
    $headerToggle.attr('aria-expanded', !$sidebar().hasClass('is-collapsed'));
  };
  MOBILE_QUERY.addEventListener('change', sync);
  sync();
}

/* Thanh trang (breadcrumb) cùng màu nền với nội dung: khi nội dung đã cuộn xuống dưới thanh thì thêm .is-stuck để hiện
   đường kẻ mảnh ở mép dưới. Desktop: header cuộn đi trước rồi thanh mới dính; mobile: header dính nên thanh dính ngay. */
function initStickyTopbar() {
  const $scroller = $('.workspace > .ant-layout');
  const $topbar = $('#page-topbar');
  if (!$scroller.length || !$topbar.length) return;
  const update = () => {
    const offset = MOBILE_QUERY.matches ? 0 : $('.app-header').outerHeight() || 0;
    $topbar.toggleClass('is-stuck', $scroller.scrollTop() > offset);
  };
  $scroller.on('scroll', update);
  MOBILE_QUERY.addEventListener('change', update);
  update();
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
  initCollapsedMenu();
  initAccountMenu();
  initSiderToggle();
  initStickyTopbar();
  initNotices();
}
