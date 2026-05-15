<?php

namespace App\Services;

use App\Models\User;

class DiscountPermissionService
{
    public function canApproveDiscount(User $user, int $storeId, ?string $discountType = null, ?float $amount = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if (! $user->stores()->where('store_id', $storeId)->exists()) {
            return false;
        }

        if ($discountType) {
            if (! $this->hasDiscountTypePermission($user, $discountType, $storeId)) {
                return false;
            }

            if ($amount !== null) {
                return $this->canApproveAmount($user, $discountType, $amount);
            }

            return true;
        }

        return $this->hasGeneralDiscountPermission($user, $storeId);
    }

    public function hasDiscountTypePermission(User $user, string $discountType, ?int $storeId = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return false;
        }

        $allowedRoles = $discountConfig['approval_roles'] ?? [];

        foreach ($allowedRoles as $role) {
            if (! $user->hasRole($role)) {
                continue;
            }

            if (in_array($role, ['store_owner', 'store_staff'], true) && $storeId) {
                return $user->stores()->where('store_id', $storeId)->exists();
            }

            return true;
        }

        return false;
    }

    public function hasGeneralDiscountPermission(User $user, ?int $storeId = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('store_owner')) {
            if ($storeId) {
                return $user->stores()->where('store_id', $storeId)->exists();
            }

            return true;
        }

        if ($user->hasRole('store_staff')) {
            $staffCanDiscount = config('payment.discount_types.discount.approval_roles', []);
            if (in_array('store_staff', $staffCanDiscount, true)) {
                if ($storeId) {
                    return $user->stores()->where('store_id', $storeId)->exists();
                }

                return true;
            }
        }

        return false;
    }

    public function canApproveAmount(User $user, string $discountType, float $amount): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return false;
        }

        $maxAmount = $discountConfig['max_amount'] ?? 0;

        if ($user->hasRole('store_owner')) {
            return $amount <= $maxAmount;
        }

        if ($user->hasRole('store_staff')) {
            $staffMaxAmount = min($maxAmount, config('payment.auto_discount.max_amount', 100));

            return $amount <= $staffMaxAmount;
        }

        return false;
    }

    public function requiresApproval(string $discountType, float $amount): bool
    {
        $discountConfig = config("payment.discount_types.{$discountType}");

        if (! $discountConfig) {
            return true;
        }

        if ($discountConfig['requires_approval'] ?? false) {
            return true;
        }

        $autoApprovalLimit = config('payment.auto_discount.max_amount', 100);

        return $amount > $autoApprovalLimit;
    }
}
