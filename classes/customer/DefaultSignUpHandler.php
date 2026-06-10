<?php

namespace KodZero\POSMall\Classes\Customer;

use DB;
use Event;
use Illuminate\Support\Facades\Validator;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Classes\User\PosmallUserGroup;
use KodZero\POSMall\Classes\User\Settings;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Wishlist;
use RainLab\User\Models\Setting;
use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;
use RainLab\User\Models\UserLog;
use System\Classes\PluginManager;

class DefaultSignUpHandler implements SignUpHandler
{
    protected $asGuest;

    public function handle(array $data, bool $asGuest = false): ?User
    {
        $this->asGuest = $asGuest;

        return $this->signUp($data);
    }

    public static function rules($forSignup = true): array
    {
        $minPasswordLength = Settings::getMinPasswordLength();
        $rules = [
            'firstname'           => 'required',
            'lastname'            => 'required',
            'email'               => ['required', 'email', ($forSignup ? 'posmall_non_existing_user' : null)],
            'billing_lines'       => 'required',
            'billing_zip'         => 'required',
            'billing_city'        => 'required',
            'billing_country_id'  => 'bail|required|integer|exists:rainlab_location_countries,id',
            'billing_state_id'    => 'bail|required|integer|exists:rainlab_location_states,id',
            'shipping_lines'      => 'required_if:use_different_shipping,1',
            'shipping_zip'        => 'required_if:use_different_shipping,1',
            'shipping_city'       => 'required_if:use_different_shipping,1',
            'shipping_state_id'   => 'bail|required_if:use_different_shipping,1|nullable|integer|exists:rainlab_location_states,id',
            'shipping_country_id' => 'bail|required_if:use_different_shipping,1|nullable|integer|exists:rainlab_location_countries,id',
            'password'            => sprintf('required|min:%d|max:255', $minPasswordLength),
            'password_repeat'     => 'required|same:password',
            'terms_accepted'      => 'required',
        ];

        if ((bool)GeneralSettings::get('use_state', true) !== true) {
            unset($rules['billing_state_id'], $rules['shipping_state_id']);
        }

        Event::fire('posmall.customer.extendSignupRules', [&$rules, $forSignup]);

        // Winter CMS compatibility: RainLab.Location validation tables use the winter_ prefix there.
        if (PluginManager::instance()->hasPlugin('Winter.Location')) {
            $translatedRules = array_where($rules, fn ($value, $key) => (is_string($value) && str_contains($value, 'rainlab_')));

            foreach (array_keys($translatedRules) as $rule) {
                $rules[$rule] = str_replace('rainlab_', 'winter_', $rules[$rule]);
            }
        }

        return $rules;
    }

    public static function messages(): array
    {
        $messages = [
            'email.required'          => trans('kodzero.posmall::lang.components.signup.errors.email.required'),
            'email.email'             => trans('kodzero.posmall::lang.components.signup.errors.email.email'),
            'email.unique'            => trans('kodzero.posmall::lang.components.signup.errors.email.unique'),
            'email.non_existing_user' => trans('kodzero.posmall::lang.components.signup.errors.email.non_existing_user'),
            'email.posmall_non_existing_user' => trans('kodzero.posmall::lang.components.signup.errors.email.non_existing_user'),

            'firstname.required'           => trans('kodzero.posmall::lang.components.signup.errors.firstname.required'),
            'lastname.required'            => trans('kodzero.posmall::lang.components.signup.errors.lastname.required'),
            'billing_lines.required'       => trans('kodzero.posmall::lang.components.signup.errors.lines.required'),
            'billing_zip.required'         => trans('kodzero.posmall::lang.components.signup.errors.zip.required'),
            'billing_city.required'        => trans('kodzero.posmall::lang.components.signup.errors.city.required'),
            'billing_country_id.required'  => trans('kodzero.posmall::lang.components.signup.errors.country_id.required'),
            'billing_country_id.exists'    => trans('kodzero.posmall::lang.components.signup.errors.country_id.exists'),
            'billing_state_id.required'    => trans('kodzero.posmall::lang.components.signup.errors.state_id.required'),
            'billing_state_id.exists'      => trans('kodzero.posmall::lang.components.signup.errors.state_id.exists'),
            'shipping_lines.required'      => trans('kodzero.posmall::lang.components.signup.errors.lines.required'),
            'shipping_zip.required'        => trans('kodzero.posmall::lang.components.signup.errors.zip.required'),
            'shipping_city.required'       => trans('kodzero.posmall::lang.components.signup.errors.city.required'),
            'shipping_country_id.required' => trans('kodzero.posmall::lang.components.signup.errors.country_id.required'),
            'shipping_country_id.exists'   => trans('kodzero.posmall::lang.components.signup.errors.country_id.exists'),

            'password.required' => trans('kodzero.posmall::lang.components.signup.errors.password.required'),
            'password.min'      => trans('kodzero.posmall::lang.components.signup.errors.password.min'),
            'password.max'      => trans('kodzero.posmall::lang.components.signup.errors.password.max'),

            'password_repeat.required' => trans('kodzero.posmall::lang.components.signup.errors.password_repeat.required'),
            'password_repeat.same'     => trans('kodzero.posmall::lang.components.signup.errors.password_repeat.same'),

            'terms_accepted.required' => trans('kodzero.posmall::lang.components.signup.errors.terms_accepted.required'),
        ];

        Event::fire('posmall.customer.extendSignupMessages', [&$messages]);

        return $messages;
    }

    /**
     * @throws ValidationException
     * @return User
     */
    protected function signUp(array $data)
    {
        if ($this->asGuest) {
            $data['password'] = $data['password_repeat'] = str_random(30);
        }

        $this->validate($data);

        $requiresConfirmation = ($data['requires_confirmation'] ?? false);

        Event::fire('posmall.customer.beforeSignup', [$this, $data]);

        $user = DB::transaction(function () use ($data, $requiresConfirmation) {
            $user = $this->createUser($data, $requiresConfirmation);

            $customer            = new Customer();
            $customer->firstname = $data['firstname'];
            $customer->lastname  = $data['lastname'];
            $customer->user_id   = $user->id;
            $customer->is_guest  = $this->asGuest;
            $customer->save();

            $addressData = $this->transformAddressKeys($data, 'billing');
            $fullname    = $data['firstname'] . ' ' . $data['lastname'];

            $billing = new Address();
            $billing->fill($addressData);
            $billing->name        = $addressData['address_name'] ?: $fullname;
            $billing->customer_id = $customer->id;
            $billing->save();
            $customer->default_billing_address_id = $billing->id;

            if (! empty($data['use_different_shipping'])) {
                $addressData = $this->transformAddressKeys($data, 'shipping');

                $shipping = new Address();
                $shipping->fill($addressData);
                $shipping->name        = $addressData['address_name'] ?: $fullname;
                $shipping->customer_id = $customer->id;
                $shipping->save();
                $customer->default_shipping_address_id = $shipping->id;
            } else {
                $customer->default_shipping_address_id = $billing->id;
            }

            $customer->save();

            $user = $user->fresh();

            Cart::transferSessionCartToCustomer($customer);
            Wishlist::transferToCustomer($customer);

            return $user;
        });

        // To prevent multiple guest accounts with the same email address we edit
        // the email of all existing guest accounts registered to the same email.
        $this->renameExistingGuestAccounts($data, $user);

        Event::fire('posmall.customer.afterSignup', [$this, $user]);

        if (class_exists(UserLog::class)) {
            UserLog::createRecord($user->getKey(), UserLog::TYPE_NEW_USER, [
                'user_full_name' => $user->full_name,
            ]);
        }

        if ($requiresConfirmation === true) {
            return $user;
        }

        Auth::login($user, true);

        return $user;
    }

    /**
     * @throws ValidationException
     */
    protected function validate(array $data)
    {
        $rules = static::rules();

        if ($this->asGuest) {
            unset($rules['password'], $rules['password_repeat']);
        }

        $messages = static::messages();

        $validation = Validator::make($data, $rules, $messages);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }
    }

    /**
     * @param $data
     * @param $requiresConfirmation
     */
    protected function createUser($data, $requiresConfirmation)
    {
        $data['name']                  = $data['firstname'];
        $data['surname']               = $data['lastname'];
        $data['password']              = $data['password'];
        $data['password_confirmation'] = $data['password_repeat'];
        $data['is_guest']              = $this->asGuest;

        // RainLab.User 3.0
        if (class_exists(Setting::class)) {
            $data['first_name'] = $data['firstname'];
            $data['last_name']  = $data['lastname'];

            unset($data['name']);
            unset($data['surname']);
        }
        
        $user = Auth::register($data, ! $requiresConfirmation);

        if ($user) {
            PosmallUserGroup::markOwned($user);

            $groupIds = [PosmallUserGroup::get()->id];

            if ($this->asGuest && $group = UserGroup::getGuestGroup()) {
                $groupIds[] = $group->id;
            }

            PosmallUserGroup::attachGroupIds($user, $groupIds);
        }

        return $user;
    }

    protected function transformAddressKeys(array $data, string $type): array
    {
        return collect($data)->mapWithKeys(function ($value, $key) use ($type) {
            if (starts_with($key, $type)) {
                $newKey = str_replace($type . '_', '', $key);

                return [$newKey => $value];
            }

            return [];
        })->toArray();
    }

    protected function renameExistingGuestAccounts(array $data, $user)
    {
        // Add a "mall-guest_2021-05-31_075100" suffix to the already registered email.
        $parts = explode('@', $data['email']);
        $suffix = 'mall-guest_' . date('Y-m-d_His');

        $newEmail = sprintf('%s+%s@%s', $parts[0], $suffix, $parts[1]);

        User::where('id', '<>', $user->id)
            ->where('email', $data['email'])
            ->whereIn('id', Customer::where('is_guest', 1)->pluck('user_id'))
            ->update(['email' => $newEmail, 'username' => $newEmail]);
    }
}
