import $ from 'jquery';

/*
 * Ô lọc [data-autosubmit] (vd ô ngày ở trang Nhật ký hệ thống): đổi giá trị thì gửi luôn form GET chứa nó.
 */
export default function initAutoSubmit() {
  $(document).on('change', 'form [data-autosubmit]', function () {
    this.form.requestSubmit();
  });
}
