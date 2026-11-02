import $ from 'jquery';

import initLayout from './layout';
import initControls from './controls';
import initSelects from './select';
import initPickers from './picker';
import initForms from './forms';
import initLogin from './login';
import initRevenue from './revenue';
import initDashboard from './dashboard';
import initDeleteConfirm from './delete';
import initLists from './list';
import initEditForms from './edit-form';
import initProjectForm from './project-form';
import initPaymentModal from './payment-modal';
import initBulk from './bulk';
import initUserForm from './user-form';
import initGroupForm from './group-form';
import initTagModal from './tag-modal';
import initHostingForm from './hosting-form';
import initRenewals from './renewal';
import initTemplateForm from './renewal-template';
import initSettings from './settings';
import initTreePages from './tree-page';
import initPageForm from './page-form';
import initLinkPickers from './link-picker';

/*
 * JS của giao diện admin (template ProCMS: Ant Design 4 + Tailwind + jQuery). Mỗi module tự kiểm tra trang hiện tại
 * có phần tử của mình hay không, nên mọi trang dùng chung một file.
 */
window.$ = window.jQuery = $;

$(() => {
  initLayout();
  initControls();
  initSelects();
  initPickers();
  initForms();
  initLogin();
  initRevenue();
  initDashboard();
  initDeleteConfirm();
  initLists();
  initEditForms();
  initProjectForm();
  initPaymentModal();
  initBulk();
  initUserForm();
  initGroupForm();
  initTagModal();
  initHostingForm();
  initRenewals();
  initTemplateForm();
  initSettings();
  initTreePages();
  initPageForm();
  initLinkPickers();
});
