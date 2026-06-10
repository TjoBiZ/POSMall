<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Carbon\Carbon;
use DB;
use Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Validation;
use RainLab\User\Models\User;

class Customer extends Model
{
    use SoftDelete;
    use Validation;

    /**
     * The table associated with this model.
     * @var string
     */
    public $table = 'kodzero_posmall_customers';

    /**
     * The validation rules for the single attributes.
     * @var array
     */
    public $rules = [
        'firstname' => 'required',
        'lastname'  => 'required',
        'is_guest'  => 'boolean',
        'user_id'   => 'required|exists:users,id',
    ];

    /**
     * The attributes that are mass assignable.
     * @var array<string>
     */
    public $fillable = [
        'firstname',
        'lastname',
        'is_guest',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * @var array<string>
     */
    public $hidden = [
        'id',
        'user_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'stripe_customer_id',
    ];

    /**
     * The belongsTo relationships of this model.
     * @var array
     */
    public $belongsTo = [
        'user' => User::class,
    ];

    public static function forUser(?User $user): ?self
    {
        if (! $user) {
            return null;
        }

        return static::where('user_id', $user->id)->first();
    }

    public static function ensureForUser(?User $user): ?self
    {
        if (! $user || (bool)($user->is_guest ?? false)) {
            return null;
        }

        $customer = static::withTrashed()->where('user_id', $user->id)->first() ?? new static();

        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            $customer->restore();
        }

        $customer->user_id = $user->id;
        $customer->firstname = trim((string)($user->first_name ?? $user->name ?? 'Customer')) ?: 'Customer';
        $customer->lastname = trim((string)($user->last_name ?? $user->surname ?? 'Account')) ?: 'Account';
        $customer->is_guest = false;
        $customer->save();

        return $customer;
    }
    
    /**
     * The hasMany relationships of this model.
     * @var array
     */
    public $hasMany = [
        'addresses'       => Address::class,
        'orders'          => Order::class,
        'payment_methods' => CustomerPaymentMethod::class,
    ];

    /**
     * The attributes that should be cast.
     * @var array
     */
    protected $casts = [
        'is_guest'      => 'boolean',
        'deleted_at'    => 'datetime',
    ];

    /**
     * Get name attribute.
     * @return string
     */
    public function getNameAttribute()
    {
        return $this->firstname . ' ' . $this->lastname;
    }

    /**
     * Get default shipping address.
     * @return ?Address
     */
    public function getShippingAddressAttribute()
    {
        return $this->addresses->where('id', $this->default_shipping_address_id)->first();
    }

    /**
     * Get default billing address.
     * @return ?Address
     */
    public function getBillingAddressAttribute()
    {
        return $this->addresses->where('id', $this->default_billing_address_id)->first();
    }

    /**
     * Hook after model is deleted.
     * @return void
     */
    public function afterDelete()
    {
        $this->addresses->each->delete();
        $this->orders->each->delete();
    }

    /**
     * POSMall privacy cleanup callback used by the external cleanup integration.
     * @param Carbon $deadline
     * @param int $keepDays
     */
    public function gdprCleanup(Carbon $deadline, int $keepDays)
    {
        User::where('last_seen', '<', $deadline)->get()->each(function (User $user) {
            DB::transaction(function () use ($user) {
                Customer::withTrashed()->where('user_id', $user->id)->forceDelete();
                $user->delete();
            });
        });
    }
}
