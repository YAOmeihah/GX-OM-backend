<?php

namespace App\Helpers;

/**
 * 金额计算辅助类
 * 使用 bcmath 扩展确保金融计算的精度
 */
class MoneyHelper
{
    /**
     * 默认小数位数
     */
    const SCALE = 2;

    /**
     * 比较两个金额
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     * @return int -1: amount1 < amount2, 0: 相等, 1: amount1 > amount2
     */
    public static function compare($amount1, $amount2, int $scale = self::SCALE): int
    {
        return bccomp(
            self::normalize($amount1),
            self::normalize($amount2),
            $scale
        );
    }

    /**
     * 判断金额是否为零
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function isZero($amount, int $scale = self::SCALE): bool
    {
        return self::compare($amount, 0, $scale) === 0;
    }

    /**
     * 判断金额是否为正数
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function isPositive($amount, int $scale = self::SCALE): bool
    {
        return self::compare($amount, 0, $scale) > 0;
    }

    /**
     * 判断金额是否为负数
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function isNegative($amount, int $scale = self::SCALE): bool
    {
        return self::compare($amount, 0, $scale) < 0;
    }

    /**
     * 判断金额是否小于等于零
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function isZeroOrNegative($amount, int $scale = self::SCALE): bool
    {
        return self::compare($amount, 0, $scale) <= 0;
    }

    /**
     * 判断金额是否大于等于零
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function isZeroOrPositive($amount, int $scale = self::SCALE): bool
    {
        return self::compare($amount, 0, $scale) >= 0;
    }

    /**
     * 判断 amount1 是否大于 amount2
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function isGreaterThan($amount1, $amount2, int $scale = self::SCALE): bool
    {
        return self::compare($amount1, $amount2, $scale) > 0;
    }

    /**
     * 判断 amount1 是否大于等于 amount2
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function isGreaterThanOrEqual($amount1, $amount2, int $scale = self::SCALE): bool
    {
        return self::compare($amount1, $amount2, $scale) >= 0;
    }

    /**
     * 判断 amount1 是否小于 amount2
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function isLessThan($amount1, $amount2, int $scale = self::SCALE): bool
    {
        return self::compare($amount1, $amount2, $scale) < 0;
    }

    /**
     * 判断 amount1 是否小于等于 amount2
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function isLessThanOrEqual($amount1, $amount2, int $scale = self::SCALE): bool
    {
        return self::compare($amount1, $amount2, $scale) <= 0;
    }

    /**
     * 判断两个金额是否相等
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function equals($amount1, $amount2, int $scale = self::SCALE): bool
    {
        return self::compare($amount1, $amount2, $scale) === 0;
    }

    /**
     * 加法
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function add($amount1, $amount2, int $scale = self::SCALE): string
    {
        return bcadd(
            self::normalize($amount1),
            self::normalize($amount2),
            $scale
        );
    }

    /**
     * 减法
     *
     * @param  float|string  $amount1  被减数
     * @param  float|string  $amount2  减数
     * @param  int  $scale  小数位数
     */
    public static function subtract($amount1, $amount2, int $scale = self::SCALE): string
    {
        return bcsub(
            self::normalize($amount1),
            self::normalize($amount2),
            $scale
        );
    }

    /**
     * 乘法
     *
     * @param  float|string  $amount  金额
     * @param  float|string  $multiplier  乘数
     * @param  int  $scale  小数位数
     */
    public static function multiply($amount, $multiplier, int $scale = self::SCALE): string
    {
        return bcmul(
            self::normalize($amount),
            self::normalize($multiplier),
            $scale
        );
    }

    /**
     * 除法
     *
     * @param  float|string  $amount  被除数
     * @param  float|string  $divisor  除数
     * @param  int  $scale  小数位数
     *
     * @throws \InvalidArgumentException 如果除数为零
     */
    public static function divide($amount, $divisor, int $scale = self::SCALE): string
    {
        if (self::isZero($divisor)) {
            throw new \InvalidArgumentException('除数不能为零');
        }

        return bcdiv(
            self::normalize($amount),
            self::normalize($divisor),
            $scale
        );
    }

    /**
     * 取两个金额中的较小值
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function min($amount1, $amount2, int $scale = self::SCALE): string
    {
        return self::isLessThanOrEqual($amount1, $amount2, $scale)
            ? self::format($amount1, $scale)
            : self::format($amount2, $scale);
    }

    /**
     * 取两个金额中的较大值
     *
     * @param  float|string  $amount1  第一个金额
     * @param  float|string  $amount2  第二个金额
     * @param  int  $scale  小数位数
     */
    public static function max($amount1, $amount2, int $scale = self::SCALE): string
    {
        return self::isGreaterThanOrEqual($amount1, $amount2, $scale)
            ? self::format($amount1, $scale)
            : self::format($amount2, $scale);
    }

    /**
     * 确保金额不为负数
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function ensurePositive($amount, int $scale = self::SCALE): string
    {
        return self::max($amount, 0, $scale);
    }

    /**
     * 格式化金额
     *
     * @param  float|string  $amount  金额
     * @param  int  $scale  小数位数
     */
    public static function format($amount, int $scale = self::SCALE): string
    {
        return number_format((float) self::normalize($amount), $scale, '.', '');
    }

    /**
     * 转换为浮点数
     *
     * @param  float|string  $amount  金额
     */
    public static function toFloat($amount): float
    {
        return (float) self::normalize($amount);
    }

    /**
     * 标准化金额字符串
     *
     * @param  float|string|null  $amount  金额
     */
    private static function normalize($amount): string
    {
        if ($amount === null) {
            return '0';
        }

        // 移除千位分隔符和空格
        $normalized = str_replace([',', ' '], '', (string) $amount);

        // 确保是有效数字
        if (! is_numeric($normalized)) {
            return '0';
        }

        return $normalized;
    }
}
