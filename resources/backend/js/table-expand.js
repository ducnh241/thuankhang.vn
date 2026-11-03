import $ from 'jquery';

/*
 * Dòng mở rộng của Table Ant Design: nút .ant-table-row-expand-icon[data-expand="id"] mở / đóng dòng
 * tr.ant-table-expanded-row[data-expanded-for="id"] ngay bên dưới.
 */
export default function initTableExpand() {
  $(document).on('click', '.ant-table-row-expand-icon[data-expand]', function (e) {
    e.stopPropagation();
    const $row = $(`tr[data-expanded-for="${this.dataset.expand}"]`);
    const open = $row.hasClass('hidden');
    $row.toggleClass('hidden', !open);
    $(this)
      .toggleClass('ant-table-row-expand-icon-expanded', open)
      .toggleClass('ant-table-row-expand-icon-collapsed', !open)
      .attr('aria-expanded', open ? 'true' : 'false')
      .attr('aria-label', open ? 'Thu gọn' : 'Mở rộng');
  });
}
