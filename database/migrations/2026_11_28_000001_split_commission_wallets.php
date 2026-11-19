<?php

use App\Models\AuditLog;
use App\Models\Commission;
use App\Services\CommissionEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chia hoa hồng vào hai ví (chủ dự án yêu cầu 19/11/2026). Mỗi khoản hoa hồng X:
 *   thuế TNCN       = floor(X × pit_percent)        (mặc định 10%) — khấu trừ ngay, không vào ví nào
 *   ví tái tiêu dùng = floor(X × reconsume_percent)  (mặc định 20%)
 *   ví có thể rút    = X − tái tiêu dùng − thuế       (mặc định 70%)
 *
 * Cột mới: commission_ledger.{reconsume_percent, tax_percent, cash_amount, reconsume_amount, tax_amount} (snapshot lúc
 * chia), wallet_transactions.wallet (cash | reconsume — số dư từng ví = tổng amount của ví đó).
 *
 * Dữ liệu cũ (hoa hồng đã cộng 100% vào ví trước thay đổi này): chia lại giống hệt đơn mới — CHỈ GHI THÊM, không sửa / xóa
 * bút toán cũ: với mỗi dòng đã cộng ví, thêm một bút toán "split" trừ ví có thể rút (tái tiêu dùng + thuế) và một bút toán
 * cộng ví tái tiêu dùng. Chạy lại không chia hai lần (chỉ xử lý dòng chưa có tax_percent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->decimal('reconsume_percent', 5, 2)->nullable()->after('amount')->comment('Tỉ lệ vào ví tái tiêu dùng lúc chia');
            $table->decimal('tax_percent', 5, 2)->nullable()->after('reconsume_percent')->comment('Thuế TNCN lúc chia; NULL = chưa chia');
            $table->unsignedBigInteger('cash_amount')->default(0)->after('tax_percent')->comment('Vào ví có thể rút = hoa hồng − tái tiêu dùng − thuế');
            $table->unsignedBigInteger('reconsume_amount')->default(0)->after('cash_amount')->comment('Vào ví tái tiêu dùng = floor(hoa hồng × tỉ lệ)');
            $table->unsignedBigInteger('tax_amount')->default(0)->after('reconsume_amount')->comment('Thuế TNCN khấu trừ = floor(hoa hồng × thuế)');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('wallet', 10)->default('cash')->after('member_id')->comment('cash = ví có thể rút | reconsume = ví tái tiêu dùng');
            $table->index(['member_id', 'wallet', 'id']);
        });

        $this->splitExisting();
    }

    private function splitExisting(): void
    {
        DB::transaction(function () {
            $lines = DB::table('commission_ledger')->whereNull('tax_percent')->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                return;
            }
            $balances = [];
            $balance = function (int $memberId, string $wallet) use (&$balances): int {
                return $balances[$memberId][$wallet] ??= (int) DB::table('wallet_transactions')
                    ->where('member_id', $memberId)->where('wallet', $wallet)->sum('amount');
            };
            $now = now();
            $total = ['lines' => 0, 'reconsume' => 0, 'tax' => 0];

            foreach ($lines as $line) {
                $credited = $line->status === Commission::STATUS_CREDITED;
                $split = CommissionEngine::split($credited ? (int) $line->amount : 0);
                DB::table('commission_ledger')->where('id', $line->id)->update([
                    'reconsume_percent' => $split['reconsume_percent'], 'tax_percent' => $split['tax_percent'],
                    'cash_amount' => $split['cash'], 'reconsume_amount' => $split['reconsume'], 'tax_amount' => $split['tax'],
                ]);
                if (! $credited || (int) $line->amount === 0) {
                    continue;
                }
                $code = DB::table('orders')->where('id', $line->order_id)->value('code');
                $memberId = (int) $line->beneficiary_id;
                $out = $split['reconsume'] + $split['tax'];
                if ($out > 0) {
                    $balances[$memberId]['cash'] = $balance($memberId, 'cash') - $out;
                    DB::table('wallet_transactions')->insert([
                        'member_id' => $memberId, 'wallet' => 'cash', 'type' => 'split', 'amount' => -$out,
                        'balance_after' => $balances[$memberId]['cash'], 'commission_id' => $line->id, 'created_at' => $now,
                        'note' => "Chia lại hoa hồng {$line->tier} đơn {$code}: chuyển ví tái tiêu dùng + khấu trừ thuế TNCN",
                    ]);
                }
                if ($split['reconsume'] > 0) {
                    $balances[$memberId]['reconsume'] = $balance($memberId, 'reconsume') + $split['reconsume'];
                    DB::table('wallet_transactions')->insert([
                        'member_id' => $memberId, 'wallet' => 'reconsume', 'type' => 'commission', 'amount' => $split['reconsume'],
                        'balance_after' => $balances[$memberId]['reconsume'], 'commission_id' => $line->id, 'created_at' => $now,
                        'note' => "Hoa hồng {$line->tier} đơn {$code} — phần tái tiêu dùng",
                    ]);
                }
                $total['lines']++;
                $total['reconsume'] += $split['reconsume'];
                $total['tax'] += $split['tax'];
            }

            if ($total['lines'] > 0) {
                AuditLog::record('update', Commission::class, null, "Chia lại {$total['lines']} dòng hoa hồng cũ vào ví: "
                    .number_format($total['reconsume'], 0, ',', '.').' đ sang ví tái tiêu dùng, '
                    .number_format($total['tax'], 0, ',', '.').' đ khấu trừ thuế TNCN.');
            }
        });
    }

    public function down(): void
    {
        DB::table('wallet_transactions')->where('type', 'split')->orWhere('wallet', 'reconsume')->delete();
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'wallet', 'id']);
            $table->dropColumn('wallet');
        });
        Schema::table('commission_ledger', fn (Blueprint $table) => $table->dropColumn(['reconsume_percent', 'tax_percent', 'cash_amount', 'reconsume_amount', 'tax_amount']));
    }
};
