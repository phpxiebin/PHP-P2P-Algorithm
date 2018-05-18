<?php

/**
 * 金融P2P融资贷款相关算法类
 * @author xiebin
 * @version 1.0
 */
class Algorithm
{

    /**
     * 计算适当的还金和利息
     * @param int $account 借款金额
     * @param double $year_apr 借款年利率
     * @param int $month_times 借款天数(目前只支持30的倍数)
     * @param string $borrow_style 还款方式
     * @param int $borrow_time 借款时间
     * @param string $type all=返回一次性还款数据,留空为返回还款每月还款详细数据
     * @return array|bool|string
     * @throws Exception
     */
    public function EqualInterest($account, $year_apr, $month_times, $borrow_style, $borrow_time = 0, $type = '')
    {
        if (intval($borrow_time) == 0) {
            $borrow_time = $_SERVER['REQUEST_TIME'];//获取当前请求时间
        }
        if (intval($month_times) % 30 != 0) { //目前只支持30的倍数
            return array();
        }
        // y：月 | x：利息 | b：本金 | e：到期
        switch ($borrow_style) {
            case 'yxb': // 按月等额本息
                return self::EqualMonth($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type);
            case 'yx_eb': // 按月付息到期还本
                return self::EqualEndMonth($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type);
            case 'ebx': // 到期还本付息
                return self::EqualEnd($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type);
            case 'jx_eb'://按季付息到期还本
                return self::EqualSeason($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type);
        }
    }

    /**
     * 按月等额本息算利息法
     * @param int $account 借款金额
     * @param double $year_apr 借款年利率
     * @param int $month_times 借款天数(目前只支持30的倍数)
     * @param int $borrow_style 还款方式
     * @param int $borrow_time 借款时间
     * @param string $type all=返回一次性还款数据,留空为返回还款每月还款详细数据
     * @return Array
     * 贷款本金×月利率×（1+月利率）还款月数/[（1+月利率）还款月数-1]
     * a*[i*(1+i)^n]/[(1+I)^n-1]
     */
    private function EqualMonth($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type)
    {
        /**
         * @todo 等额本息新算法(不涉及投标利息算法)
         * @author xiebin
         * @since 2015-03-25
         */
        return $this->EqualMonthNew($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type, 0);
        $times = ($month_times < 30) ? 1 : $month_times / 30;
        $mrate = $year_apr / 100 / 12;
        $trate = pow(1 + $mrate, $times);
        $interest = ($account * $mrate * $trate) / ($trate - 1);
        $tInterest = round($interest * $times, 2);
        // 返回总数据
        if ($type == "all") {
            return array(
                'repayment_account' => $tInterest,
                'monthly_repayment' => round($interest, 2),
                'month_apr' => round($year_apr / 12, 2),
            );
        }
        // 计算每月利息
        $_result = array();
        $mcapital = $minterest = 0;
        $surplus = $account;
        for ($i = 0; $i < $times; $i++) {
            $minterest = $surplus * $mrate;
            $mcapital = $interest - $minterest;
            $_result[$i]['repayment_account'] = round($mcapital, 2) + round($minterest, 2);
            //@todo 等额本息新算法计算还款时间
            $_result[$i]['repayment_time'] = $this->getRepamentTime($borrow_time, $i, 'yxb');
            $_result[$i]['interest'] = round($minterest, 2);
            $_result[$i]['capital'] = round($mcapital, 2);
            if ($i + 1 == $times) {
                $_result[$i]['repayment_account'] = round($tInterest, 2);
                $_result[$i]['capital'] = round($account, 2);
                $_result[$i]['interest'] = round($tInterest - $account, 2);
            }
            $account -= $_result[$i]['capital'];
            $tInterest -= $_result[$i]['repayment_account'];
            $surplus = $surplus - $mcapital;
        }
        return $_result;
    }

    // 	等额本息法 新算法
    // 	贷款本金×月利率×（1+月利率）还款月数/[（1+月利率）还款月数-1]
    // 	a*[i*(1+i)^n]/[(1+I)^n-1]
    // 	（a×i－b）×（1＋i）
    private function EqualMonthNew($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type, $profit_fee_rate)
    {
        $times = ($month_times < 30) ? 1 : $month_times / 30;
        $mrate = $year_apr / 100 / 12;
        $trate = pow(1 + $mrate, $times);
        $monthly_repayment = round(($account * $mrate * $trate) / ($trate - 1), 2);
        $repayment_account = round($monthly_repayment * $times, 2);

        // 计算每月利息
        $_result = array();
        $mcapital = $minterest = $scapital = 0; //每期本金、每期利息、总还款本金
        $surplus = $account; //剩余本金
        $tProfitFee = 0; //总手续费
        for ($i = 0; $i < $times; $i++) {
            $_result[$i]['profit_fee'] = round($surplus * ($profit_fee_rate / 12), 2);
            $tProfitFee += $_result[$i]['profit_fee'];

            $minterest = round($surplus * $mrate, 2);
            $mcapital = round($monthly_repayment - $minterest, 2);
            $_result[$i]['repayment_account'] = $monthly_repayment;

            //@todo 等额本息新算法计算还款时间
            $_result[$i]['repayment_time'] = $this->getRepamentTime($borrow_time, $i, 'yxb');
            $_result[$i]['interest'] = $minterest;
            $_result[$i]['capital'] = $mcapital;
            $surplus = $surplus - $mcapital;

            $scapital += $mcapital;
            //最后一期平账
            if ($i + 1 == $times) {
                $_result[$i]['capital'] = round($mcapital + ($account - $scapital), 2);
                $_result[$i]['interest'] = round($minterest + ($scapital - $account), 2);
            }
        }
        // 返回总数据
        if ($type == "all") {
            return array(
                'repayment_account' => $repayment_account,
                'monthly_repayment' => $monthly_repayment,
                'month_apr' => round($year_apr / 12, 2),
                'profit_fee' => $tProfitFee,
            );
        }
        return $_result;
    }

    /**
     * 按月付息到期还本
     * @param int $account 借款金额
     * @param double $year_apr 借款年利率
     * @param int $month_times 借款天数(目前只支持30的倍数)
     * @param int $borrow_style 还款方式
     * @param int $borrow_time 借款时间
     * @param string $type all=返回一次性还款数据,留空为返回还款每月还款详细数据
     * @return Array
     */
    private function EqualEndMonth($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type)
    {
        $times = ($month_times < 30) ? 1 : $month_times / 30;
        $tInterest = ceil($account * $times * ($year_apr / 12)) / 100;
        $interest = round($tInterest / $times, 2);
        $tAccount = $tInterest + $account;
        // 返回总数据
        if ($type == "all") {
            return array(
                'repayment_account' => $tAccount,
                'monthly_repayment' => $interest,
                'month_apr' => round($year_apr / 12, 2),
            );
        }
        // 计算每月利息
        $_result = array();
        // 本金
        for ($i = 0; $i < $times; $i++) {
            $_result[$i]['repayment_account'] = $interest;
            //@todo 按月付息到期还本新算法计算还款时间
            $_result[$i]['repayment_time'] = $this->getRepamentTime($borrow_time, $i, 'yx_eb');
            $_result[$i]['interest'] = $interest;
            $_result[$i]['capital'] = 0;
            if ($i + 1 == $times) {
                $_result[$i]['capital'] = $account;
                $_result[$i]['repayment_account'] = $account + $tInterest - $interest * ($times - 1);
                $_result[$i]['interest'] = $tInterest - $interest * ($times - 1);
            }
        }
        return $_result;
    }

    // 到期还本，按季付息
    private function EqualSeason($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type)
    {
        //得到总月数
        $times = ($month_times < 30) ? 1 : $month_times / 30;
        //得到总季数
        $_season = ceil($times / 3);

        //计算总利息（按月计算）
        $tInterest = ceil($account * $times * ($year_apr / 12)) / 100;
        $interest = round($tInterest / $times, 2);
        $tAccount = $tInterest + $account;

        // 返回总数据
        if ($type == "all") {
            return array(
                'repayment_account' => $tAccount,
                'monthly_repayment' => $interest,
                'month_apr' => round($year_apr / 12, 2),
            );
        }

        // 计算每季利息
        $_result = array();

        // 循环季度
        $rinterest = 0;
        for ($i = 0; $i < $_season; $i++) {

            $_result[$i]['repayment_account'] = $interest * 3;
            //@todo 按季付息到期还本新算法计算还款时间
            $_result[$i]['repayment_time'] = $this->getRepamentTime($borrow_time, $i, 'jx_eb');
            $_result[$i]['interest'] = $interest * 3;
            $_result[$i]['capital'] = 0;

            if ($i + 1 == $_season) { //最后一期
                $lastInterest = $tInterest - $rinterest;
                if (($times % 3) == 0) {
                    $_result[$i]['capital'] = $account;
                    $_result[$i]['repayment_account'] = $account + $lastInterest;
                    //@todo 按季付息到期还本新算法计算还款时间
                    $_result[$i]['repayment_time'] = $this->getRepamentTime($borrow_time, $i, 'jx_eb');
                    $_result[$i]['interest'] = $lastInterest;//$interest*3;
                } else {
                    $_result[$i]['capital'] = $account;
                    $_result[$i]['repayment_account'] = $account + $lastInterest;
                    $_result[$i]['repayment_time'] = $this->formatTimeNew($borrow_time, (($i * 3) + ($times % 3)) + 1);
                    $_result[$i]['interest'] = $lastInterest;//$interest*($times%3);
                }
            }
            $rinterest += $interest * 3;
        }
        return $_result;
    }

    /**
     * 到期还本付息
     * @param int $account 借款金额
     * @param double $year_apr 借款年利率
     * @param int $month_times 借款天数(目前只支持30的倍数)
     * @param int $borrow_style 还款方式
     * @param int $borrow_time 借款时间
     * @param string $type all=返回一次性还款数据,留空为返回还款每月还款详细数据
     * @return Array
     */
    private function EqualEnd($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type)
    {

        $times = ($month_times < 30) ? 1 : $month_times / 30;
        $tInterest = ceil($account * $times * ($year_apr / 12)) / 100;
        $interest = round($tInterest / $times, 2);
        $tAccount = $tInterest + $account;
        // 返回总数据
        if ($type == "all") {
            return array(
                'repayment_account' => $tAccount,
                'monthly_repayment' => $tAccount,
                'month_apr' => round($year_apr / 12, 2),
            );
        }
        $_result = array();
        $_result['repayment_account'] = $tAccount;
        if ($month_times % 30 == 0) {
            $month_times = ceil($month_times / 30) - 1;
            //@todo 到期还本付息新算法计算还款时间
            $_result['repayment_time'] = $this->getRepamentTime($borrow_time, $month_times, 'ebx');
        } else {
            //@todo 到期还本付息新算法计算还款时间
            $time = $this->formatTime($borrow_time);
            $_result['repayment_time'] = strtotime($month_times . ' day', $time);
        }
        $_result['interest'] = $tInterest;
        $_result['capital'] = $account;
        return array($_result);
    }

    /**
     * 按融资期限(30/1期)相对应的平台信息服务费
     * @param int $time_limit 融资期限
     * @param int $key 数组KEY(默认取服务费率)
     * return string
     */
    public function getFee($time_limit, $key = 'fee')
    {

        $fee = array(
            '30' => array('name' => '1个月', 'key' => 1, 'default' => 1, 'fee' => '0.67'),
            '60' => array('name' => '2个月', 'key' => 2, 'default' => 0, 'fee' => '1.33'),
            '90' => array('name' => '3个月', 'key' => 3, 'default' => 0, 'fee' => '2.00'),
            '120' => array('name' => '4个月', 'key' => 4, 'default' => 0, 'fee' => '2.50'),
            '150' => array('name' => '5个月', 'key' => 5, 'default' => 0, 'fee' => '2.92'),
            '180' => array('name' => '6个月', 'key' => 6, 'default' => 0, 'fee' => '3.25'),
            '210' => array('name' => '7个月', 'key' => 7, 'default' => 0, 'fee' => '3.50'),
            '240' => array('name' => '8个月', 'key' => 8, 'default' => 0, 'fee' => '3.67'),
            '270' => array('name' => '9个月', 'key' => 9, 'default' => 0, 'fee' => '4.13'),
            '300' => array('name' => '10个月', 'key' => 10, 'default' => 0, 'fee' => '4.58'),
            '330' => array('name' => '11个月', 'key' => 11, 'default' => 0, 'fee' => '4.58'),
            '360' => array('name' => '12个月', 'key' => 12, 'default' => 0, 'fee' => '4.50'),
        );

        return !empty($fee[$time_limit][$key]) ? $fee[$time_limit][$key] : "未定义";
    }

    /**
     * 根据资信评分获取资信等级
     * @param int $credit 资信评分
     */
    public function getLevelByCredit($credit)
    {
        $level = array(
            1 => array("name" => "特级", "max" => 900, "min" => 855),
            2 => array("name" => "一级", "max" => 855, "min" => 810),
            3 => array("name" => "二级", "max" => 810, "min" => 765),
            4 => array("name" => "三级", "max" => 765, "min" => 720),
            5 => array("name" => "四级", "max" => 720, "min" => 675),
            6 => array("name" => "五级", "max" => 675, "min" => 630),
            7 => array("name" => "六级", "max" => 630, "min" => 585),
            8 => array("name" => "七级", "max" => 585, "min" => 540),
            9 => array("name" => "八级", "max" => 540, "min" => 495),
            10 => array("name" => "八级", "max" => 495, "min" => 0),
        );
        $level_name = '未定义';
        foreach ($level as $v) {
            //@todo 855分为一级
            if ($credit <= $v['max'] && $credit > $v['min']) {
                $level_name = $v['name'];
            }
        }
        return $level_name;
    }

    /**
     * 根据审批发布前的审核信息自动计算出审核发布默认打分信息
     * @param array $array 审核信息
     * @return array
     */
    public function getIssueInfoByAudit($array)
    {
        if ($array) {
            foreach ($array as $k => $v) {
                if (preg_match("/^audit\d$/", $k)) { //JSON字符串转数组
                    $array[$k] = json_decode($v, TRUE);
                }
            }
            $f_degree = array();
            $f_degree['score'] = round($array['audit1']['score'] * 0.3 + $array['audit4']['score'] * 0.7);
            $f_degree['management'] = round($array['audit1']['management'] * 0.3 + $array['audit4']['management'] * 0.7);
            $f_degree['credit'] = round($array['audit3']['credit'] * 0.2 + $array['audit5']['credit'] * 0.3 + $array['audit6']['credit'] * 0.5);
            return $f_degree;
        }
    }

    //获取审批状态，写法并不科学，因为用了static
    static function getApprStatus($id)
    {
        $re = DwBorrow::model()->findByAttributes(array('id' => $id));
        if (isset($re->issue_type)) {
            return $re->issue_type;
        }

    }

    static function getDebitStatus($id)
    {
        $re = DwBorrow::model()->findByAttributes(array('id' => $id));
        if (isset($re->auto_debit)) {
            return $re->auto_debit;
        }
    }

    /**
     * 根据放款时间获取具体期数还款截止时间
     * @todo 新规则还款时间精确到天，已29日为节点
     *         1，小于每月29日0点0分放款，当天就开始计算利息，截止到下个月当天减一天晚上23点59分59秒算（也就是28号晚上23点59分59秒为当期最后还款期限）
     *         2，大于每月29日0点0分放款，当天就开始计算利息，固定截止到下个月28号23点59分59秒算最后还款时间
     * @param int $debitTime 放款时间
     * @param int $order 期数
     * @param int $borrowStyle 融资还款方式 [yxb按月等额本息 | yx_eb按月付息到期还本 | ebx到期还本付息 | jx_eb按季付息到期还本]
     * @author xiebin
     * @since 2014-10-29
     */
    public function getRepamentTime($debitTime, $order, $borrowStyle)
    {
        /**
         * @todo 解决30号、31号、1号时间问题
         */
        return $this->getRepamentTimeNew($debitTime, $order, $borrowStyle);
        $time = $this->formatTime($debitTime);
        $repayment_time = 0;
        if ("yxb" == $borrowStyle || "yx_eb" == $borrowStyle) {
            $repayment_time = strtotime(($order + 1) . ' month', $time);
        } elseif ("ebx" == $borrowStyle) {
            $repayment_time = strtotime(($order + 1) . ' month', $time);
        } elseif ("jx_eb" == $borrowStyle) {
            $repayment_time = strtotime((($order * 3) + 3) . ' month', $time);
        }
        return $repayment_time;
    }

    /**
     * 格式化时间并判断
     * @param int $time 时间戳
     * @author xiebin
     * @since 2014-10-29
     */
    public function formatTime($time)
    {
        //格式化时间并判断
        list($y, $m, $d, $h, $i, $s) = explode(' ', date('Y m d H i s', $time));
        $f_time = ($d <= 29) ? mktime(0, 0, 0, $m, $d, $y) - 1 : mktime(23, 59, 59, $m, 28, $y);
        $f_time = ($d == 1) ? mktime(23, 59, 59, $m - 1, 28, $y) : $f_time;
        return $f_time;
    }

    /**
     * 根据放款时间获取具体期数还款截止时间
     * @todo 新规则还款时间精确到天，解决30号、31号、1号时间问题
     * @param int $debitTime 放款时间
     * @param int $order 期数
     * @param int $borrowStyle 融资还款方式 [yxb按月等额本息 | yx_eb按月付息到期还本 | ebx到期还本付息 | jx_eb按季付息到期还本]
     * @author xiebin
     * @since 2014-04-03
     */
    function getRepamentTimeNew($debitTime, $order, $borrowStyle)
    {
        $repayment_time = 0;
        if ("yxb" == $borrowStyle || "yx_eb" == $borrowStyle) {
            $repayment_time = $this->formatTimeNew($debitTime, $order + 1);
        } elseif ("ebx" == $borrowStyle) {
            $repayment_time = $this->formatTimeNew($debitTime, $order + 1);
        } elseif ("jx_eb" == $borrowStyle) {
            $repayment_time = $this->formatTimeNew($debitTime, ($order + 1) * 3);
        }
        return $repayment_time;
    }

    /**
     * 格式化时间并判断
     * @param int $time 时间戳
     * @param int $order 期数［取值范围1-12］
     * @author xiebin
     * @since 2015-04-03
     */
    function formatTimeNew($time, $order = 1)
    {
        //格式化时间并判断
        list($y, $m, $d, $h, $i, $s) = explode(' ', date('Y m d H i s', $time));
        if ($d == 30 || $d == 31) { //如果是30号或者31号放款
            /**
             * @todo 解决还款计划2月跨年问题
             * @version 2015-05-06
             */
            $diff = intval(($m + $order) / 12);
            $month = ($m + $order) > 12 ? ($m + $order - $diff * 12) : $m + $order; //获取月份
            if ($month == 2) { //如果是2月
                $day = date('t', mktime(0, 0, 0, 2, 1, $y + $diff)); //获取2月天数
                $timestamp = mktime(23, 59, 59, 2, $day, $y + $diff);
            } else {
                $timestamp = mktime(0, 0, 0, $m + $order, $d, $y) - 1;
            }
        } else {
            $timestamp = mktime(0, 0, 0, $m + $order, $d, $y) - 1;
        }
        return $timestamp;
    }
}