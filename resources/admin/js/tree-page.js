import $ from 'jquery';

import { message, plain } from './core';
import { openModal } from './modal';
import { setSelectValue } from './select';

/*
 * Trang cây + form (theo template categories.js): Danh mục (backend/categories/index) và Menu website
 * (backend/menus/index). Cây do server dựng (danh sách phẳng theo thứ tự cây, mỗi dòng có data-depth / data-parent).
 * Markup: [data-tree-page="khóa nhớ thu gọn"] bọc trang; ul[data-tree] (data-move-url có __ID__ khi được kéo thả);
 * form[data-tree-form]; a[data-tree-nav] (đổi mục / Thêm); select[data-tree-switch] (đổi loại / vị trí, option có
 * data-url). Ở đây:
 *   - thu gọn / mở nhánh (nhớ theo trang + loại trong localStorage);
 *   - đổi loại / bấm mục khác / Thêm khi form còn thay đổi chưa lưu: hỏi "Bỏ thay đổi?" rồi mới chuyển trang;
 *   - kéo tay nắm để đặt trước / sau / vào trong mục khác, Alt+↑/↓ đổi chỗ với mục bên cạnh: gửi lên server
 *     (POST …/move) rồi tải lại trang; đang có thay đổi chưa lưu thì không cho kéo;
 *   - slug tự theo ô nguồn ([data-slug-source] → [data-slug-target]) cho tới khi sửa tay;
 *   - điện thoại / tablet (một cột): mở mục / thêm mới thì cuộn xuống form.
 */

const dirty = () => !$('#save-bar').hasClass('hidden') && $('#save-bar').length > 0;

// Như Str::slug của Laravel cho tiếng Việt: bỏ dấu, đ → d, chữ thường, cách bằng "-"
export const toSlug = s =>
  plain(s)
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

// Chạy `go` ngay, hoặc sau khi người dùng đồng ý bỏ thay đổi chưa lưu
function confirmLeave(go) {
  if (!dirty()) return go();
  const $root = openModal('#delete-confirm');
  $root.find('.ant-modal-confirm-title').text('Bỏ thay đổi chưa lưu?');
  $root.find('.ant-modal-confirm-content').text('Thay đổi trong form chưa được lưu.');
  $root.find('.modal-ok span').text('Bỏ thay đổi');
  $root.find('.modal-ok').on('click', () => {
    $root.data('close')();
    $('form[data-tree-form]').trigger('edit-form:leave');
    go();
  });
}

function initCollapse($tree, storeKey) {
  const key = `procms:tree-collapsed:${storeKey}`;
  let collapsed;
  try {
    collapsed = new Set(JSON.parse(localStorage.getItem(key)) || []);
  } catch {
    collapsed = new Set();
  }
  // Ẩn / hiện các dòng con cháu theo trạng thái thu gọn của các dòng cha
  const apply = () => {
    let hideBelow = Infinity; // độ sâu của nhánh đang thu gọn
    $tree.children('.cat-item').each(function () {
      const depth = Number(this.dataset.depth);
      if (depth <= hideBelow) hideBelow = Infinity;
      $(this).toggleClass('hidden', depth > hideBelow);
      const id = Number(this.dataset.id);
      if (this.hasAttribute('aria-expanded')) {
        const open = !collapsed.has(id);
        this.setAttribute('aria-expanded', open);
        const $btn = $(this).find('> .cat-row .cat-toggle');
        $btn.attr('aria-label', $btn.attr(open ? 'data-label-open' : 'data-label-closed'));
        $btn.find('use').attr('href', open ? '#i-minus' : '#i-plus');
        if (!open && hideBelow === Infinity) hideBelow = depth;
      }
    });
  };
  // Danh mục đang mở nằm trong nhánh thu gọn: mở các nhánh cha để thấy nó
  const $selected = $tree.children('.cat-item[aria-selected="true"]');
  if ($selected.length) {
    let depth = Number($selected.attr('data-depth'));
    $selected.prevAll('.cat-item').each(function () {
      if (Number(this.dataset.depth) < depth) {
        collapsed.delete(Number(this.dataset.id));
        depth = Number(this.dataset.depth);
      }
    });
  }
  apply();
  $tree.on('click', '.cat-toggle', function () {
    const id = Number($(this).closest('.cat-item').attr('data-id'));
    if (collapsed.has(id)) collapsed.delete(id);
    else collapsed.add(id);
    try {
      localStorage.setItem(key, JSON.stringify([...collapsed]));
    } catch {
      // không nhớ được: không sao
    }
    apply();
  });
  return id => {
    collapsed.delete(id);
    try {
      localStorage.setItem(key, JSON.stringify([...collapsed]));
    } catch {
      // không nhớ được: không sao
    }
  };
}

function initMove($tree, expand) {
  const url = $tree.attr('data-move-url');
  if (!url) return;
  const items = () => $tree.children('.cat-item');
  const depthOf = el => Number(el.dataset.depth);
  // id của danh mục và mọi con cháu (các dòng ngay sau nó, sâu hơn)
  const subtree = el => {
    const ids = [Number(el.dataset.id)];
    $(el)
      .nextAll('.cat-item')
      .each(function () {
        if (depthOf(this) <= depthOf(el)) return false;
        ids.push(Number(this.dataset.id));
      });
    return ids;
  };

  let busy = false;
  const send = async (id, targetId, zone) => {
    if (busy) return;
    if (dirty()) {
      message('Lưu hoặc hủy thay đổi của form trước khi sắp xếp.', 'warning');
      return;
    }
    busy = true;
    if (zone === 'inside') expand(targetId);
    try {
      const res = await fetch(url.replace('__ID__', id), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': $('#delete-form [name="_token"]').val(),
        },
        body: JSON.stringify({ target_id: targetId, zone }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        message(data.message || 'Không chuyển được, thử lại sau.', 'error');
        busy = false;
        return;
      }
      window.location.reload();
    } catch {
      message('Không kết nối được máy chủ, thử lại sau.', 'error');
      busy = false;
    }
  };

  let drag = null;
  let fromHandle = false;
  const clear = () => $tree.find('.drop-before, .drop-after, .drop-inside').removeClass('drop-before drop-after drop-inside');
  const zoneOf = (row, e) => {
    const r = row.getBoundingClientRect();
    const y = (e.originalEvent.clientY - r.top) / r.height;
    return y < 0.25 ? 'before' : y > 0.75 ? 'after' : 'inside';
  };
  const allowed = target => drag && !subtree(drag).includes(Number(target.dataset.id));

  $tree.on('mousedown', '.cat-row', e => {
    fromHandle = !!$(e.target).closest('.cat-handle').length;
  });
  $tree.on('dragstart', '.cat-row', function (e) {
    if (!fromHandle) return e.preventDefault();
    if (dirty()) {
      e.preventDefault();
      message('Lưu hoặc hủy thay đổi của form trước khi sắp xếp.', 'warning');
      return;
    }
    drag = $(this).closest('.cat-item')[0];
    e.originalEvent.dataTransfer.effectAllowed = 'move';
    e.originalEvent.dataTransfer.setData('text/plain', drag.dataset.id);
    requestAnimationFrame(() => $(this).addClass('is-dragging'));
  });
  $tree.on('dragend', '.cat-row', function () {
    $(this).removeClass('is-dragging');
    clear();
    drag = null;
  });
  $tree.on('dragover', '.cat-row', function (e) {
    const target = $(this).closest('.cat-item')[0];
    clear();
    if (!allowed(target)) return;
    e.preventDefault();
    e.originalEvent.dataTransfer.dropEffect = 'move';
    $(this).addClass(`drop-${zoneOf(this, e)}`);
  });
  $tree.on('drop', '.cat-row', function (e) {
    e.preventDefault();
    const target = $(this).closest('.cat-item')[0];
    const zone = zoneOf(this, e);
    clear();
    if (!allowed(target)) return;
    const id = Number(drag.dataset.id);
    drag = null;
    send(id, Number(target.dataset.id), zone);
  });

  // Bàn phím: Alt+↑ / Alt+↓ đổi chỗ với mục cùng cấp bên cạnh
  $tree.on('keydown', '.cat-label', function (e) {
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    const el = $(this).closest('.cat-item')[0];
    const siblings = items()
      .toArray()
      .filter(x => x.dataset.parent === el.dataset.parent && depthOf(x) === depthOf(el));
    const i = siblings.indexOf(el);
    const j = e.key === 'ArrowUp' ? i - 1 : i + 1;
    if (j < 0 || j >= siblings.length) return;
    send(Number(el.dataset.id), Number(siblings[j].dataset.id), e.key === 'ArrowUp' ? 'before' : 'after');
  });
}

export default function initTreePages() {
  const $page = $('[data-tree-page]');
  if (!$page.length) return;
  const $tree = $page.find('[data-tree]');
  const $form = $('form[data-tree-form]');
  const expand = initCollapse($tree, $page.attr('data-tree-page'));
  initMove($tree, expand);

  // Chuyển trang (mục khác, Thêm, về trang gốc): hỏi trước nếu còn thay đổi chưa lưu
  $(document).on('click', 'a[data-tree-nav]', function (e) {
    if (!dirty() || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    const href = this.href;
    confirmLeave(() => window.location.assign(href));
  });
  // Đổi loại / vị trí: ô chọn về lại cái đang xem ngay (hủy ở hộp hỏi thì vẫn đúng), đồng ý thì sang trang kia
  const $switch = $('select[data-tree-switch]');
  const current = $switch.find('option[selected]').val();
  $switch.on('change', function () {
    const option = this.options[this.selectedIndex];
    if (!option || option.value === current) return;
    const href = option.getAttribute('data-url');
    setSelectValue($switch.prev('.ant-select'), current);
    confirmLeave(() => window.location.assign(href));
  });

  // Slug tự theo ô nguồn cho tới khi sửa tay (xóa trắng thì lại tự theo)
  const $slug = $form.find('[data-slug-target]');
  if ($slug.length) {
    $slug.on('input', function () {
      if (this.value.trim() === '') this.removeAttribute('data-touched');
      else this.setAttribute('data-touched', '');
    });
    $form.find('[data-slug-source]').on('input', function () {
      if (!$slug[0].hasAttribute('data-touched')) $slug.val(toSlug(this.value)).trigger('change');
    });
    // "Hủy thay đổi": slug về giá trị đã lưu → có giá trị thì coi như đã sửa tay
    $form.on('reset', () => setTimeout(() => $slug.attr('data-touched', $slug.val() !== '' ? '' : null)));
  }

  // Một cột (dưới lg): form nằm dưới cây → mở mục / thêm mới thì cuộn tới form
  const params = new URLSearchParams(window.location.search);
  if ((params.has('id') || params.has('new')) && window.matchMedia('(max-width: 991px)').matches && $form.length) {
    $form[0].scrollIntoView({ block: 'start' });
  }
}
