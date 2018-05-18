<?php

/**
 * 金融P2P融资贷款相关算法类
 * @author xiebin
 * @version 1.0
 */
class Algorithm
{

    /**
     * 根据借款方式计算对应期数的应还本金和利息
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
            default:
                return 'not_found';
        }
    }

    // 	等额本息法
    // 	贷款本金×月利率×（1+月利率）还款月数/[（1+月利率）还款月数-1]
    // 	a*[i*(1+i)^n]/[(1+I)^n-1]
    // 	（a×i－b）×（1＋i）
    private function EqualMonth($account, $year_apr, $month_times, $borrow_style, $borrow_time, $type, $profit_fee_rate)
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
     * 根据放款时间获取具体期数还款截止时间
     * @todo 新规则还款时间精确到天，解决30号、31号、1号时间问题
     * @param int $debitTime 放款时间
     * @param int $order 期数
     * @param int $borrowStyle 融资还款方式 [yxb按月等额本息 | yx_eb按月付息到期还本 | ebx到期还本付息 | jx_eb按季付息到期还本]
     * @author xiebin
     */
    function getRepamentTime($debitTime, $order, $borrowStyle)
    {
        $repayment_time = 0;
        if ("yxb" == $borrowStyle || "yx_eb" == $borrowStyle) {
            $repayment_time = $this->formatTime($debitTime, $order + 1);
        } elseif ("ebx" == $borrowStyle) {
            $repayment_time = $this->formatTime($debitTime, $order + 1);
        } elseif ("jx_eb" == $borrowStyle) {
            $repayment_time = $this->formatTime($debitTime, ($order + 1) * 3);
        }
        return $repayment_time;
    }

    /**
     * 格式化时间并判断
     * @param int $time 时间戳
     * @param int $order 期数［取值范围1-12］
     * @author xiebin
     */
    function formatTime($time, $order = 1)
    {
        //格式化时间并判断
        list($y, $m, $d, $h, $i, $s) = explode(' ', date('Y m d H i s', $time));
        if ($d == 30 || $d == 31) { //如果是30号或者31号放款
            /**
             * @todo 解决还款计划2月跨年问题
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