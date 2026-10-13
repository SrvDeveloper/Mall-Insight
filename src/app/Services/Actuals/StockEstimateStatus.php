<?php

namespace App\Services\Actuals;

/**
 * 在庫を取得していない日の在庫を推定できたか（K-087）。
 */
enum StockEstimateStatus: string
{
    /** 直前に在庫を取得した日の在庫から販売数を引いて推定した */
    case Estimated = 'estimated';

    /** 直前に在庫を取得した日が無い（在庫の履歴より前） */
    case NoBase = 'no_base';

    /** 推定に使う日の販売実績を取り込んでいない */
    case NoSalesData = 'no_sales_data';
}
