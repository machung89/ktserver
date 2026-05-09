<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemAccountsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('system_accounts')->truncate();

        $accounts = $this->accounts();
        $now = now();

        $rows = array_map(fn ($a, $i) => [
            'code' => $a[0],
            'name' => $a[1],
            'type' => $a[2],
            'parent_code' => $a[3],
            'sort_order' => $i + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $accounts, array_keys($accounts));

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('system_accounts')->insert($chunk);
        }
    }

    /**
     * Danh mục tài khoản kế toán theo Thông tư 200/2014/TT-BTC.
     * Cột: [mã TK, tên TK, loại, mã TK cha]
     *
     * @return array<int, array{0:string,1:string,2:string,3:string|null}>
     */
    private function accounts(): array
    {
        return [
            // ====== LOẠI 1: TÀI SẢN NGẮN HẠN ======
            ['111',   'Tiền mặt',                                              'asset',     null],
            ['1111',  'Tiền Việt Nam',                                         'asset',     '111'],
            ['1112',  'Ngoại tệ',                                              'asset',     '111'],
            ['1113',  'Vàng bạc, kim khí quý, đá quý',                        'asset',     '111'],
            ['112',   'Tiền gửi ngân hàng',                                   'asset',     null],
            ['1121',  'Tiền Việt Nam',                                         'asset',     '112'],
            ['1122',  'Ngoại tệ',                                              'asset',     '112'],
            ['1123',  'Vàng bạc, kim khí quý, đá quý',                        'asset',     '112'],
            ['113',   'Tiền đang chuyển',                                      'asset',     null],
            ['1131',  'Tiền Việt Nam',                                         'asset',     '113'],
            ['1132',  'Ngoại tệ',                                              'asset',     '113'],
            ['121',   'Chứng khoán kinh doanh',                               'asset',     null],
            ['128',   'Đầu tư nắm giữ đến ngày đáo hạn',                      'asset',     null],
            ['131',   'Phải thu của khách hàng',                               'asset',     null],
            ['133',   'Thuế GTGT được khấu trừ',                               'asset',     null],
            ['1331',  'Thuế GTGT được khấu trừ của hàng hóa, dịch vụ',        'asset',     '133'],
            ['1332',  'Thuế GTGT được khấu trừ của TSCĐ',                      'asset',     '133'],
            ['136',   'Phải thu nội bộ',                                       'asset',     null],
            ['138',   'Phải thu khác',                                         'asset',     null],
            ['141',   'Tạm ứng',                                               'asset',     null],
            ['151',   'Hàng mua đang đi đường',                                'asset',     null],
            ['152',   'Nguyên liệu, vật liệu',                                 'asset',     null],
            ['153',   'Công cụ, dụng cụ',                                      'asset',     null],
            ['154',   'Chi phí sản xuất, kinh doanh dở dang',                  'asset',     null],
            ['155',   'Thành phẩm',                                            'asset',     null],
            ['156',   'Hàng hóa',                                              'asset',     null],
            ['157',   'Hàng gửi đi bán',                                       'asset',     null],
            ['161',   'Chi sự nghiệp',                                         'asset',     null],
            // ====== LOẠI 2: TÀI SẢN DÀI HẠN ======
            ['211',   'Tài sản cố định hữu hình',                              'asset',     null],
            ['212',   'Tài sản cố định thuê tài chính',                        'asset',     null],
            ['213',   'Tài sản cố định vô hình',                               'asset',     null],
            ['214',   'Hao mòn tài sản cố định',                               'asset',     null],
            ['217',   'Bất động sản đầu tư',                                   'asset',     null],
            ['221',   'Đầu tư vào công ty con',                                'asset',     null],
            ['222',   'Đầu tư vào công ty liên doanh, liên kết',               'asset',     null],
            ['228',   'Đầu tư khác',                                           'asset',     null],
            ['229',   'Dự phòng tổn thất đầu tư tài chính',                    'asset',     null],
            ['241',   'Xây dựng cơ bản dở dang',                               'asset',     null],
            ['242',   'Chi phí trả trước',                                     'asset',     null],
            ['243',   'Tài sản thuế thu nhập hoãn lại',                        'asset',     null],
            ['244',   'Cầm cố, thế chấp, ký quỹ, ký cược',                    'asset',     null],
            // ====== LOẠI 3: NỢ PHẢI TRẢ ======
            ['311',   'Vay ngắn hạn',                                          'liability', null],
            ['315',   'Nợ dài hạn đến hạn trả',                               'liability', null],
            ['331',   'Phải trả cho người bán',                                'liability', null],
            ['333',   'Thuế và các khoản phải nộp Nhà nước',                   'liability', null],
            ['3331',  'Thuế giá trị gia tăng phải nộp',                        'liability', '333'],
            ['33311', 'Thuế GTGT đầu ra',                                      'liability', '3331'],
            ['33312', 'Thuế GTGT hàng nhập khẩu',                              'liability', '3331'],
            ['3332',  'Thuế tiêu thụ đặc biệt',                                'liability', '333'],
            ['3333',  'Thuế xuất, nhập khẩu',                                  'liability', '333'],
            ['3334',  'Thuế thu nhập doanh nghiệp',                            'liability', '333'],
            ['3335',  'Thuế thu nhập cá nhân',                                 'liability', '333'],
            ['3336',  'Thuế tài nguyên',                                       'liability', '333'],
            ['3337',  'Thuế nhà đất, tiền thuê đất',                           'liability', '333'],
            ['3338',  'Thuế bảo vệ môi trường và các loại thuế khác',          'liability', '333'],
            ['3339',  'Phí, lệ phí và các khoản phải nộp khác',               'liability', '333'],
            ['334',   'Phải trả người lao động',                               'liability', null],
            ['335',   'Chi phí phải trả',                                      'liability', null],
            ['336',   'Phải trả nội bộ',                                       'liability', null],
            ['338',   'Phải trả, phải nộp khác',                               'liability', null],
            ['341',   'Vay và nợ thuê tài chính dài hạn',                      'liability', null],
            ['344',   'Nhận ký quỹ, ký cược',                                  'liability', null],
            ['347',   'Thuế thu nhập hoãn lại phải trả',                       'liability', null],
            ['352',   'Dự phòng phải trả',                                     'liability', null],
            ['353',   'Quỹ khen thưởng, phúc lợi',                             'liability', null],
            ['356',   'Quỹ phát triển khoa học và công nghệ',                  'liability', null],
            // ====== LOẠI 4: VỐN CHỦ SỞ HỮU ======
            ['411',   'Vốn đầu tư của chủ sở hữu',                             'equity',    null],
            ['4111',  'Vốn góp của chủ sở hữu',                                'equity',    '411'],
            ['4112',  'Thặng dư vốn cổ phần',                                  'equity',    '411'],
            ['4113',  'Vốn khác',                                              'equity',    '411'],
            ['412',   'Chênh lệch đánh giá lại tài sản',                       'equity',    null],
            ['413',   'Chênh lệch tỷ giá hối đoái',                            'equity',    null],
            ['414',   'Quỹ đầu tư phát triển',                                 'equity',    null],
            ['418',   'Các quỹ khác thuộc vốn chủ sở hữu',                    'equity',    null],
            ['419',   'Cổ phiếu quỹ',                                          'equity',    null],
            ['421',   'Lợi nhuận sau thuế chưa phân phối',                     'equity',    null],
            ['4211',  'Lợi nhuận sau thuế chưa phân phối năm trước',           'equity',    '421'],
            ['4212',  'Lợi nhuận sau thuế chưa phân phối năm nay',             'equity',    '421'],
            ['441',   'Nguồn vốn đầu tư xây dựng cơ bản',                     'equity',    null],
            ['461',   'Nguồn kinh phí sự nghiệp',                              'equity',    null],
            // ====== LOẠI 5: DOANH THU ======
            ['511',   'Doanh thu bán hàng và cung cấp dịch vụ',                'revenue',   null],
            ['5111',  'Doanh thu bán hàng hóa',                                'revenue',   '511'],
            ['5112',  'Doanh thu bán các thành phẩm',                          'revenue',   '511'],
            ['5113',  'Doanh thu cung cấp dịch vụ',                            'revenue',   '511'],
            ['5114',  'Doanh thu trợ cấp, trợ giá',                            'revenue',   '511'],
            ['5117',  'Doanh thu kinh doanh bất động sản đầu tư',              'revenue',   '511'],
            ['515',   'Doanh thu hoạt động tài chính',                         'revenue',   null],
            ['521',   'Các khoản giảm trừ doanh thu',                          'revenue',   null],
            ['5211',  'Chiết khấu thương mại',                                 'revenue',   '521'],
            ['5212',  'Hàng bán bị trả lại',                                   'revenue',   '521'],
            ['5213',  'Giảm giá hàng bán',                                     'revenue',   '521'],
            // ====== LOẠI 6: CHI PHÍ SẢN XUẤT KINH DOANH ======
            ['611',   'Mua hàng',                                               'expense',   null],
            ['621',   'Chi phí nguyên liệu, vật liệu trực tiếp',               'expense',   null],
            ['622',   'Chi phí nhân công trực tiếp',                           'expense',   null],
            ['627',   'Chi phí sản xuất chung',                                'expense',   null],
            ['631',   'Giá thành sản xuất',                                    'expense',   null],
            ['632',   'Giá vốn hàng bán',                                      'expense',   null],
            ['635',   'Chi phí tài chính',                                     'expense',   null],
            ['641',   'Chi phí bán hàng',                                      'expense',   null],
            ['642',   'Chi phí quản lý doanh nghiệp',                          'expense',   null],
            // ====== LOẠI 7: THU NHẬP KHÁC ======
            ['711',   'Thu nhập khác',                                          'revenue',   null],
            // ====== LOẠI 8: CHI PHÍ KHÁC ======
            ['811',   'Chi phí khác',                                           'expense',   null],
            ['821',   'Chi phí thuế thu nhập doanh nghiệp',                    'expense',   null],
            // ====== LOẠI 9: XÁC ĐỊNH KẾT QUẢ KINH DOANH ======
            ['911',   'Xác định kết quả kinh doanh',                           'expense',   null],
        ];
    }
}
