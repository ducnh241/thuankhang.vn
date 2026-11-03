<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 20 sản phẩm demo (4 sản phẩm × 5 danh mục demo) cho dev / staging, đủ thông tin: mã, giá đã gồm VAT, VAT, mô tả ngắn,
 * mô tả chi tiết, 2 ảnh. Không gọi từ DatabaseSeeder:
 *   php artisan db:seed --class=DemoProductSeeder
 * Ảnh: tải ảnh stock miễn phí từ Unsplash (giấy phép Unsplash — dùng thương mại miễn phí, không bắt buộc ghi nguồn; chỉ
 * chọn ảnh không có logo thương hiệu). Không tải được (máy không có mạng…) thì vẽ ảnh minh họa bằng GD; chạy lại khi có
 * mạng sẽ thay ảnh vẽ bằng ảnh thật. Chạy lại không tạo trùng sản phẩm (theo slug).
 */
class DemoProductSeeder extends Seeder
{
    /** Màu ảnh minh họa theo danh mục (nền, hộp). */
    private const COLORS = [
        'thuc-pham-chuc-nang' => [[232, 245, 233], [56, 142, 60]],
        'my-pham-lam-dep' => [[252, 228, 236], [194, 24, 91]],
        'cham-soc-ca-nhan' => [[227, 242, 253], [25, 118, 210]],
        'do-uong-dinh-duong' => [[255, 243, 224], [239, 108, 0]],
        'gia-dung' => [[237, 231, 246], [94, 53, 177]],
    ];

    /** Ảnh Unsplash theo mã sản phẩm (id ảnh trên images.unsplash.com), ảnh đầu là ảnh đại diện. */
    public const PHOTOS = [
        'TPCN-VITC' => ['photo-1723951174326-2a97221d3b7f', 'photo-1707129785947-ddc627a8bab9'],
        'TPCN-OMG3' => ['photo-1687200267616-83a40f3e674c', 'photo-1693996047034-311ab7656691'],
        'TPCN-CANX' => ['photo-1544829894-eb023ba95a38', 'photo-1555633514-abcee6ab92e1'],
        'TPCN-PROB' => ['photo-1549477752-31cd7327aed0', 'photo-1631824681694-60908ffbbff6'],
        'MP-SRVC' => ['photo-1713768704571-6aeb0d0e5105', 'photo-1781344116880-029945a42b75'],
        'MP-KCN50' => ['photo-1594055103006-7871176f1a7e', 'photo-1623676714504-edd78728155e'],
        'MP-SRM' => ['photo-1745141063798-7fa04698ea80', 'photo-1748639320154-6ba118bccc74'],
        'MP-MNN' => ['photo-1763503836825-97f5450d155a', 'photo-1763503834047-ac85c4105c0b'],
        'CSCN-KDR' => ['photo-1763048819607-ea55217cff34', 'photo-1787202742502-8c1ea05638a5'],
        'CSCN-DGBK' => ['photo-1747858989102-cca0f4dc4a11', 'photo-1771535626784-7de36a960c1a'],
        'CSCN-ST' => ['photo-1725940889761-35d90aead72d', 'photo-1772038786507-647371ac686b'],
        'CSCN-NSM' => ['photo-1626006864160-aa21716d0204', 'photo-1597931752949-98c74b5b159f'],
        'DU-SHOC' => ['photo-1601436423474-51738541c1b1', 'photo-1635436338433-89747d0ca0ef'],
        'DU-MTCH' => ['photo-1589698272390-0501a07619bb', 'photo-1757582893040-ee545fdfc0c7'],
        'DU-NGC' => ['photo-1724441980123-aca7911329d0', 'photo-1668723968333-28fff638eecd'],
        'DU-SBLN' => ['photo-1593095948071-474c5cc2989d', 'photo-1704650311140-aba27da8623d'],
        'GD-BGN' => ['photo-1625708458528-802ec79b1ed8', 'photo-1568395216634-ab1b1e848751'],
        'GD-NCKD' => ['photo-1774074645537-f72f70d40d12', 'photo-1781737857663-f1f14a60ba46'],
        'GD-HTT' => ['photo-1543352632-5a4b24e4d2a6', 'photo-1774442501862-eeaeaad491ee'],
        'GD-MLKK' => ['photo-1765153155226-f617921051b3', 'photo-1436473849883-bb3464c23e93'],
    ];

    /** danh mục => [[tên, mã, giá gồm VAT, VAT %, nổi bật, mô tả ngắn, công dụng, cách dùng / thông số]] */
    public const PRODUCTS = [
        'thuc-pham-chuc-nang' => [
            ['Viên uống Vitamin C 1000mg', 'TPCN-VITC', 350_000, '8', true, 'Bổ sung vitamin C, hỗ trợ tăng sức đề kháng. Hộp 60 viên.', 'Giúp tăng cường hệ miễn dịch, hỗ trợ chống oxy hóa và làm đẹp da.', 'Uống 1 viên/ngày sau bữa sáng.'],
            ['Dầu cá Omega-3 Fish Oil', 'TPCN-OMG3', 480_000, '8', false, 'Bổ sung EPA, DHA cho tim mạch và trí não. Lọ 100 viên nang mềm.', 'Hỗ trợ sức khỏe tim mạch, thị lực và chức năng não bộ.', 'Uống 1–2 viên/ngày cùng bữa ăn.'],
            ['Canxi Nano D3 K2', 'TPCN-CANX', 420_000, '8', false, 'Canxi dễ hấp thu kết hợp D3, K2 cho xương chắc khỏe. Hộp 30 viên.', 'Hỗ trợ phát triển chiều cao, giảm nguy cơ loãng xương.', 'Uống 1 viên/ngày vào buổi sáng.'],
            ['Men vi sinh Probiotic 10 tỷ', 'TPCN-PROB', 290_000, '8', true, 'Cân bằng hệ vi sinh đường ruột. Hộp 20 gói.', 'Hỗ trợ tiêu hóa, giảm đầy hơi, táo bón.', 'Pha 1 gói với nước ấm, dùng sau bữa ăn.'],
        ],
        'my-pham-lam-dep' => [
            ['Serum Vitamin C sáng da 30ml', 'MP-SRVC', 550_000, '10', true, 'Serum dưỡng sáng, mờ thâm với 15% vitamin C.', 'Làm sáng da, mờ vết thâm, giúp da đều màu sau 4 tuần.', 'Dùng 3–4 giọt sau bước toner, buổi sáng và tối.'],
            ['Kem chống nắng SPF50+ PA++++', 'MP-KCN50', 320_000, '10', false, 'Chống nắng phổ rộng, kết cấu mỏng nhẹ, không bết dính. Tuýp 50ml.', 'Bảo vệ da khỏi tia UVA/UVB, ngăn sạm nám.', 'Thoa đều trước khi ra nắng 15 phút, thoa lại sau 2–3 giờ.'],
            ['Sữa rửa mặt dịu nhẹ 150ml', 'MP-SRM', 180_000, '10', false, 'Làm sạch sâu, độ pH cân bằng, phù hợp da nhạy cảm.', 'Loại bỏ bụi bẩn và dầu thừa, không gây khô căng.', 'Tạo bọt với nước, massage 30 giây rồi rửa sạch.'],
            ['Mặt nạ ngủ dưỡng ẩm 80g', 'MP-MNN', 260_000, '10', false, 'Cấp ẩm suốt đêm, sáng dậy da căng mịn.', 'Phục hồi độ ẩm, làm dịu da khô và mệt mỏi.', 'Thoa lớp mỏng ở bước cuối buổi tối, 2–3 lần/tuần.'],
        ],
        'cham-soc-ca-nhan' => [
            ['Kem đánh răng thảo dược 180g', 'CSCN-KDR', 65_000, '10', false, 'Chiết xuất thảo dược, giúp hơi thở thơm mát.', 'Ngừa sâu răng, giảm ê buốt và chảy máu chân răng.', 'Chải răng 2 lần/ngày.'],
            ['Dầu gội thảo mộc bồ kết 500ml', 'CSCN-DGBK', 145_000, '10', true, 'Bồ kết, vỏ bưởi, hương nhu — giảm gãy rụng.', 'Làm sạch da đầu, giúp tóc chắc khỏe, mềm mượt.', 'Thoa lên tóc ướt, massage 2–3 phút rồi xả sạch.'],
            ['Sữa tắm dưỡng ẩm 900ml', 'CSCN-ST', 189_000, '10', false, 'Hương hoa nhẹ nhàng, dưỡng ẩm cho da mềm mại.', 'Làm sạch dịu nhẹ, giữ ẩm cho da suốt ngày dài.', 'Tạo bọt với bông tắm, thoa đều rồi tắm sạch.'],
            ['Nước súc miệng muối khoáng 500ml', 'CSCN-NSM', 79_000, '10', false, 'Muối khoáng thiên nhiên, không cồn.', 'Kháng khuẩn, giảm hôi miệng, bảo vệ nướu.', 'Súc miệng 20ml trong 30 giây, ngày 2 lần.'],
        ],
        'do-uong-dinh-duong' => [
            ['Sữa hạt óc chó hạnh nhân (thùng 24 hộp)', 'DU-SHOC', 360_000, '8', true, 'Sữa hạt nguyên chất, ít đường. Hộp 180ml.', 'Bổ sung đạm thực vật, omega-3 và vitamin E.', 'Lắc đều trước khi uống, ngon hơn khi uống lạnh.'],
            ['Trà xanh matcha nguyên chất 100g', 'DU-MTCH', 210_000, '8', false, 'Bột matcha xay mịn từ lá trà non.', 'Chống oxy hóa, giúp tỉnh táo và tập trung.', 'Pha 2g với 70ml nước 80°C, đánh tan bằng chổi tre.'],
            ['Ngũ cốc dinh dưỡng 25 loại hạt 500g', 'DU-NGC', 155_000, '8', false, 'Bữa sáng nhanh gọn, giàu chất xơ.', 'Cung cấp năng lượng, hỗ trợ tiêu hóa và no lâu.', 'Pha 3 thìa với 150ml nước ấm hoặc sữa.'],
            ['Sữa bột người lớn ít béo 800g', 'DU-SBLN', 495_000, '8', false, 'Bổ sung canxi, vitamin D cho người trưởng thành.', 'Giúp xương chắc khỏe, duy trì thể trạng.', 'Pha 5 muỗng gạt với 200ml nước ấm, ngày 1–2 ly.'],
        ],
        'gia-dung' => [
            ['Bình giữ nhiệt inox 304 500ml', 'GD-BGN', 230_000, '10', false, 'Giữ nóng 12 giờ, giữ lạnh 24 giờ.', 'Inox 304 an toàn, nắp chống tràn, dễ mang theo.', 'Rửa sạch trước lần dùng đầu; không dùng trong lò vi sóng.'],
            ['Nồi chiên không dầu 5 lít', 'GD-NCKD', 1_590_000, '10', true, 'Công suất 1500W, 8 chế độ nấu cài sẵn.', 'Chiên, nướng ít dầu mỡ, giữ trọn hương vị món ăn.', 'Làm nóng 3 phút, đặt nhiệt độ và thời gian theo món.'],
            ['Bộ hộp thủy tinh đựng thực phẩm (5 hộp)', 'GD-HTT', 390_000, '10', false, 'Thủy tinh chịu nhiệt, nắp khóa 4 cạnh kín khí.', 'Bảo quản thực phẩm tươi lâu, dùng được trong lò vi sóng.', 'Tháo nắp trước khi hâm nóng trong lò vi sóng.'],
            ['Máy lọc không khí mini', 'GD-MLKK', 1_250_000, '10', false, 'Màng lọc HEPA H13, phòng đến 20m².', 'Lọc bụi mịn PM2.5, khử mùi, vận hành êm.', 'Thay màng lọc sau 6–8 tháng sử dụng.'],
        ],
    ];

    public function run(): void
    {
        $this->call(DemoProductCategorySeeder::class);
        $categories = Category::where('type', Category::TYPE_PRODUCT)->pluck('id', 'slug');

        DB::transaction(function () use ($categories) {
            $order = 0;
            foreach (self::PRODUCTS as $categorySlug => $products) {
                foreach ($products as [$name, $sku, $price, $vat, $featured, $short, $benefit, $usage]) {
                    $order++;
                    $product = Product::firstOrCreate(['slug' => Str::slug($name)], [
                        'category_id' => $categories[$categorySlug],
                        'name' => $name,
                        'sku' => $sku,
                        'price' => $price,
                        'vat_rate' => $vat,
                        'short_description' => $short,
                        'description' => '<h2>Công dụng</h2><p>'.e($benefit).'</p><h2>Cách dùng</h2><p>'.e($usage).'</p>'
                            .'<h2>Lưu ý</h2><ul><li>Bảo quản nơi khô ráo, thoáng mát, tránh ánh nắng trực tiếp.</li><li>Để xa tầm tay trẻ em.</li></ul>',
                        'status' => true,
                        'is_featured' => $featured,
                        'sort_order' => $order,
                    ]);
                    $this->seedImages($product, $sku, $categorySlug);
                }
            }
        });
    }

    /**
     * Sản phẩm chưa có ảnh, hoặc chỉ có ảnh vẽ tạm của seeder: lấy ảnh Unsplash; không tải được thì vẽ ảnh minh họa.
     * Ảnh admin tự tải lên không bị đụng tới.
     */
    private function seedImages(Product $product, string $sku, string $categorySlug): void
    {
        $images = $product->images()->get();
        $drawn = fn (string $path) => str_starts_with($path, Product::IMAGE_DIRECTORY.'/demo-') && ! str_contains($path, '/demo-photo-');
        if ($images->isNotEmpty() && ! $images->every(fn ($image) => $drawn($image->path))) {
            return;
        }

        $photos = array_filter(array_map(fn ($id, $i) => $this->downloadPhoto($id, $sku, $i), self::PHOTOS[$sku] ?? [], array_keys(self::PHOTOS[$sku] ?? [])));
        if ($images->isNotEmpty() && $photos === []) {
            return; // vẫn không có mạng: giữ ảnh vẽ
        }
        foreach ($images as $image) {
            Storage::disk('public')->delete($image->path);
            $image->delete();
        }
        $paths = $photos ?: [$this->drawImage($sku, $categorySlug, 0), $this->drawImage($sku, $categorySlug, 1)];
        foreach (array_values($paths) as $i => $path) {
            $product->images()->create(['path' => $path, 'sort_order' => $i]);
        }
    }

    /** Tải ảnh vuông 1000×1000 từ Unsplash; chỉ nhận đúng ảnh JPEG. Lỗi → null. */
    private function downloadPhoto(string $photoId, string $sku, int $index): ?string
    {
        try {
            $response = Http::timeout(30)->get("https://images.unsplash.com/{$photoId}", [
                'fm' => 'jpg', 'q' => 80, 'w' => 1000, 'h' => 1000, 'fit' => 'crop', 'crop' => 'entropy',
            ]);
        } catch (\Throwable) {
            return null;
        }
        $info = $response->successful() ? @getimagesizefromstring($response->body()) : false;
        if (! $info || $info['mime'] !== 'image/jpeg') {
            return null;
        }
        $path = Product::IMAGE_DIRECTORY.'/demo-photo-'.Str::lower($sku).'-'.($index + 1).'.jpg';
        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    /** Ảnh minh họa 800×800: nền màu nhạt theo danh mục, hộp sản phẩm, mã sản phẩm (font có sẵn của GD chỉ có chữ không dấu). */
    private function drawImage(string $sku, string $categorySlug, int $variant): string
    {
        [$bg, $box] = self::COLORS[$categorySlug];
        $img = imagecreatetruecolor(800, 800);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$bg));
        $main = imagecolorallocate($img, ...$box);
        $light = imagecolorallocate($img, min(255, $box[0] + 90), min(255, $box[1] + 90), min(255, $box[2] + 90));
        $white = imagecolorallocate($img, 255, 255, 255);

        if ($variant === 0) {
            // Mặt trước: hộp đứng + nhãn
            imagefilledrectangle($img, 250, 160, 550, 660, $main);
            imagefilledrectangle($img, 250, 160, 550, 220, $light);
            imagefilledrectangle($img, 280, 380, 520, 470, $white);
            $this->text($img, $sku, 400, 425, $main);
        } else {
            // Mặt bên: hộp nghiêng + vòng tròn
            imagefilledellipse($img, 400, 400, 560, 560, $light);
            imagefilledpolygon($img, [300, 200, 560, 260, 520, 640, 260, 580], $main);
            $this->text($img, $sku, 410, 420, $white);
        }
        $this->text($img, 'THUAN KHANG', 400, 730, $main);

        ob_start();
        imagejpeg($img, null, 85);
        $data = ob_get_clean();
        imagedestroy($img);

        $path = Product::IMAGE_DIRECTORY.'/demo-'.Str::lower($sku).'-'.($variant + 1).'.jpg';
        Storage::disk('public')->put($path, $data);

        return $path;
    }

    /** Chữ phóng to ×3 từ font 5 của GD, căn giữa tại (cx, cy). */
    private function text(\GdImage $img, string $text, int $cx, int $cy, int $color): void
    {
        $w = imagefontwidth(5) * strlen($text);
        $h = imagefontheight(5);
        $small = imagecreatetruecolor($w, $h);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        [$r, $g, $b] = array_values(imagecolorsforindex($img, $color));
        imagestring($small, 5, 0, 0, $text, imagecolorallocate($small, $r, $g, $b));
        imagecopyresampled($img, $small, (int) ($cx - $w * 1.5), (int) ($cy - $h * 1.5), 0, 0, $w * 3, $h * 3, $w, $h);
        imagedestroy($small);
    }
}
