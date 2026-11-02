<?php

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
    function ant_icon(string $name, string $class = '', string $style = ''): \Illuminate\Support\HtmlString
    {
        $label = preg_replace('/-tt$/', '', $name);

        return new \Illuminate\Support\HtmlString(
            '<span role="img" aria-label="'.e($label).'" class="anticon anticon-'.e($label).($class !== '' ? ' '.e($class) : '').'"'
            .($style !== '' ? ' style="'.e($style).'"' : '')
            .'><svg width="1em" height="1em" fill="currentColor" aria-hidden="true"><use href="#i-'.e($name).'"></use></svg></span>'
        );
    }
}
