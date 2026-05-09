<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // Tài sản ngắn hạn
            ['code' => '111',   'name' => 'Tiền mặt',                                       'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '112',   'name' => 'Tiền gửi ngân hàng',                             'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '131',   'name' => 'Phải thu khách hàng',                            'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '133',   'name' => 'Thuế GTGT được khấu trừ',                        'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '1331',  'name' => 'Thuế GTGT được khấu trừ của hàng hóa, dịch vụ', 'type' => AccountType::Asset,     'parent_code' => '133'],
            ['code' => '141',   'name' => 'Tạm ứng',                                        'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '152',   'name' => 'Nguyên liệu, vật liệu',                          'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '153',   'name' => 'Công cụ, dụng cụ',                               'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '155',   'name' => 'Thành phẩm',                                     'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '156',   'name' => 'Hàng hóa',                                       'type' => AccountType::Asset,     'parent_code' => null],
            ['code' => '157',   'name' => 'Hàng gửi đi bán',                                'type' => AccountType::Asset,     'parent_code' => null],

            // Nợ phải trả
            ['code' => '331',   'name' => 'Phải trả nhà cung cấp',                          'type' => AccountType::Liability, 'parent_code' => null],
            ['code' => '333',   'name' => 'Thuế và các khoản phải nộp nhà nước',            'type' => AccountType::Liability, 'parent_code' => null],
            ['code' => '3331',  'name' => 'Thuế GTGT phải nộp',                             'type' => AccountType::Liability, 'parent_code' => '333'],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra',                               'type' => AccountType::Liability, 'parent_code' => '3331'],
            ['code' => '33312', 'name' => 'Thuế GTGT hàng nhập khẩu',                       'type' => AccountType::Liability, 'parent_code' => '3331'],
            ['code' => '334',   'name' => 'Phải trả người lao động',                        'type' => AccountType::Liability, 'parent_code' => null],
            ['code' => '341',   'name' => 'Vay và nợ thuê tài chính',                       'type' => AccountType::Liability, 'parent_code' => null],

            // Vốn chủ sở hữu
            ['code' => '411',   'name' => 'Vốn đầu tư của chủ sở hữu',                     'type' => AccountType::Equity,    'parent_code' => null],
            ['code' => '421',   'name' => 'Lợi nhuận sau thuế chưa phân phối',              'type' => AccountType::Equity,    'parent_code' => null],
            ['code' => '4211',  'name' => 'Lợi nhuận sau thuế chưa phân phối năm trước',   'type' => AccountType::Equity,    'parent_code' => '421'],
            ['code' => '4212',  'name' => 'Lợi nhuận sau thuế chưa phân phối năm nay',     'type' => AccountType::Equity,    'parent_code' => '421'],

            // Doanh thu
            ['code' => '511',   'name' => 'Doanh thu bán hàng và cung cấp dịch vụ',        'type' => AccountType::Revenue,   'parent_code' => null],
            ['code' => '515',   'name' => 'Doanh thu hoạt động tài chính',                  'type' => AccountType::Revenue,   'parent_code' => null],
            ['code' => '521',   'name' => 'Các khoản giảm trừ doanh thu',                   'type' => AccountType::Revenue,   'parent_code' => null],

            // Chi phí
            ['code' => '632',   'name' => 'Giá vốn hàng bán',                              'type' => AccountType::Expense,   'parent_code' => null],
            ['code' => '635',   'name' => 'Chi phí tài chính',                              'type' => AccountType::Expense,   'parent_code' => null],
            ['code' => '641',   'name' => 'Chi phí bán hàng',                               'type' => AccountType::Expense,   'parent_code' => null],
            ['code' => '642',   'name' => 'Chi phí quản lý doanh nghiệp',                   'type' => AccountType::Expense,   'parent_code' => null],
            ['code' => '811',   'name' => 'Chi phí khác',                                   'type' => AccountType::Expense,   'parent_code' => null],
            ['code' => '821',   'name' => 'Chi phí thuế thu nhập doanh nghiệp',             'type' => AccountType::Expense,   'parent_code' => null],
        ];

        foreach ($accounts as $data) {
            Account::updateOrCreate(['code' => $data['code']], $data);
        }
    }
}
