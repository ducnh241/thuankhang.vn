<?php

use Illuminate\Support\HtmlString;

if (! function_exists('asset_v')) {
    /**
     * asset() kèm ?v=<thời điểm sửa file> để trình duyệt tải lại khi file thay đổi
     * (CSS/JS của template không qua Vite nên không có hash trong tên file).
     */
    function asset_v(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}

if (! function_exists('ant_icon')) {
    /**
     * Icon Ant Design của giao diện admin, tham chiếu bộ icon nhúng trong trang (backend.partials.sprite),
     * cùng markup với icon() trong resources/admin/js/core.js. Tên có hậu tố -tt là icon 2 màu.
     */
    function ant_icon(string $name, string $class = '', string $style = ''): HtmlString
    {
        $label = preg_replace('/-tt$/', '', $name);

        return new HtmlString(
            '<span role="img" aria-label="'.e($label).'" class="anticon anticon-'.e($label).($class !== '' ? ' '.e($class) : '').'"'
            .($style !== '' ? ' style="'.e($style).'"' : '')
            .'><svg width="1em" height="1em" fill="currentColor" aria-hidden="true"><use href="#i-'.e($name).'"></use></svg></span>'
        );
    }
}

if (! function_exists('ant_input_number')) {
    /**
     * Ô số kiểu InputNumber của Ant Design 4 (nút tăng / giảm hiện khi rê chuột; xử lý ở resources/admin/js/controls.js).
     * $options: id, min, max, step (mặc định 1), size ('sm' | 'lg'), label (aria-label), placeholder, readonly, class.
     */
    function ant_input_number(string $name, string|int|null $value, array $options = []): HtmlString
    {
        $size = isset($options['size']) ? ' ant-input-number-'.$options['size'] : '';
        $readonly = ! empty($options['readonly']);
        $attrs = ['type' => 'text', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'role' => 'spinbutton', 'class' => 'ant-input-number-input',
            'name' => $name, 'value' => (string) $value, 'id' => $options['id'] ?? null, 'aria-label' => $options['label'] ?? null,
            'placeholder' => $options['placeholder'] ?? null, 'min' => $options['min'] ?? null, 'max' => $options['max'] ?? null,
            'aria-valuemin' => $options['min'] ?? null, 'aria-valuemax' => $options['max'] ?? null, 'aria-valuenow' => $value,
            'step' => $options['step'] ?? null, 'readonly' => $readonly ?: null];
        $html = '';
        foreach ($attrs as $attr => $v) {
            if ($v !== null) {
                $html .= ' '.$attr.($v === true ? '' : '="'.e($v).'"');
            }
        }
        $handler = fn (string $dir, string $label) => '<span unselectable="on" role="button" aria-label="'.$label.'" class="ant-input-number-handler ant-input-number-handler-'.$dir.'">'
            .ant_icon($dir, 'ant-input-number-handler-'.$dir.'-inner').'</span>';

        return new HtmlString(
            '<div class="ant-input-number'.$size.($readonly ? ' ant-input-number-readonly' : '').(isset($options['class']) ? ' '.e($options['class']) : '').'">'
            .($readonly ? '' : '<div class="ant-input-number-handler-wrap">'.$handler('up', 'Tăng').$handler('down', 'Giảm').'</div>')
            .'<div class="ant-input-number-input-wrap"><input'.$html.' /></div></div>'
        );
    }
}

if (! function_exists('ant_form_error')) {
    /** Dòng báo lỗi dưới ô theo markup Form.Item của Ant Design (đặt trong .ant-form-item-control). */
    function ant_form_error(?string $message): HtmlString
    {
        return new HtmlString($message === null || $message === ''
            ? '' : '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($message).'</div></div>');
    }
}
